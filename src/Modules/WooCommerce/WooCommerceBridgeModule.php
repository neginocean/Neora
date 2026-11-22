<?php
/**
 * Neora Core — WooCommerce Bridge Module.
 *
 * Connects OTP customer identity (_neora_mobile <-> billing_phone),
 * checkout phone prefill/validation, and transactional order SMS
 * with WooCommerce in a defensive, HPOS-compatible way.
 *
 * @package NeoraStudio\NeoraCore\Modules\WooCommerce
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Modules\WooCommerce;

use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Modules\Auth\AuthModule;
use NeoraStudio\NeoraCore\Admin\SettingsPage;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use NeoraStudio\NeoraCore\Services\Sms\MelipayamakClient;
use NeoraStudio\NeoraCore\Services\Sms\OtpManager;
use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use NeoraStudio\NeoraCore\Services\Sms\Providers\MelipayamakProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\TwilioProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\WebhookProvider;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use NeoraStudio\NeoraCore\Services\Sms\SmsQueue;

final class WooCommerceBridgeModule implements ModuleMetadataInterface {

	public const KEY        = 'neora/woocommerce';
	public const OPTION_KEY = 'neora_wc_settings';

	private const OPTION_GROUP       = 'neora_core_wc_group';
	private const SETTINGS_PAGE_SLUG = 'neora-core-settings-wc';

	private ?MelipayamakClient $sms_client;
	private ?SmsManager $sms_manager;
	private ?SmsQueue $sms_queue;

	public function __construct(
		?MelipayamakClient $sms_client = null,
		?SmsManager $sms_manager = null,
		?SmsQueue $sms_queue = null
	) {
		$this->sms_client  = $sms_client;
		$this->sms_manager = $sms_manager;
		$this->sms_queue   = $sms_queue;
	}

	public function get_key(): string {
		return self::KEY;
	}

	public function get_name(): string {
		return __( 'WooCommerce Bridge', 'neora-core' );
	}

	public function get_description(): string {
		return __( 'Connects OTP mobile identity, checkout phone validation, and transactional order SMS with WooCommerce.', 'neora-core' );
	}

	public function is_core(): bool {
		return false;
	}

	public function is_default_enabled(): bool {
		return true;
	}

	public function get_missing_dependencies(): array {
		if ( self::is_woocommerce_active() ) {
			return [];
		}

		return [
			__( 'Requires the WooCommerce plugin to be installed and active.', 'neora-core' ),
		];
	}

	public function has_rewrite_rules(): bool {
		return false;
	}

	public static function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	public function register( Container $container ): void {
		if ( null === $this->sms_client && $container->has( 'neora.sms' ) ) {
			/** @var MelipayamakClient $sms */
			$sms              = $container->get( 'neora.sms' );
			$this->sms_client = $sms;
		}
		if ( null === $this->sms_manager && $container->has( 'neora.sms_manager' ) ) {
			/** @var SmsManager $manager */
			$manager           = $container->get( 'neora.sms_manager' );
			$this->sms_manager = $manager;
		}
		if ( null === $this->sms_queue && $container->has( 'neora.sms_queue' ) ) {
			/** @var SmsQueue $queue */
			$queue           = $container->get( 'neora.sms_queue' );
			$this->sms_queue = $queue;
		}
	}

	public function boot( HookRegistry $hooks ): void {
		// Identity & checkout mobile sync.
		$hooks->filter( 'neora_auth_find_user_id', [ $this, 'find_user_by_billing_phone' ], 10, 2 );
		$hooks->action( 'neora_auth_user_registered', [ $this, 'sync_billing_phone_from_otp' ], 10, 2 );
		$hooks->action( 'neora_auth_user_logged_in', [ $this, 'sync_billing_phone_from_otp' ], 10, 2 );
		$hooks->action( 'woocommerce_checkout_update_user_meta', [ $this, 'sync_mobile_from_checkout' ], 10, 2 );
		$hooks->action( 'woocommerce_customer_save_address', [ $this, 'sync_mobile_from_saved_address' ], 10, 2 );
		$hooks->filter( 'woocommerce_checkout_get_value', [ $this, 'prefill_checkout_phone' ], 10, 2 );
		$hooks->action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_phone' ] );
		$hooks->action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_store_api_checkout_phone' ], 10, 2 );

		// Transactional order SMS notifications (Classic + Block Checkout + HPOS safe).
		$hooks->action( 'woocommerce_checkout_order_processed', [ $this, 'handle_order_created' ], 20, 1 );
		$hooks->action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'handle_order_created' ], 20, 1 );
		$hooks->action( 'woocommerce_order_status_completed', [ $this, 'handle_order_completed' ], 20, 1 );

		// Optional IRT currency fallback when not registered by another WooCommerce extension.
		$hooks->filter( 'woocommerce_currencies', [ $this, 'register_irt_currency' ] );
		$hooks->filter( 'woocommerce_currency_symbol', [ $this, 'filter_irt_currency_symbol' ], 10, 2 );

		// Settings tab integration.
		$hooks->filter( 'neora_core_settings_tabs', [ $this, 'register_settings_tab' ] );
		$hooks->action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register Iranian Toman (IRT) in WooCommerce currency list if missing.
	 *
	 * @param array<string, string> $currencies WooCommerce currencies.
	 * @return array<string, string>
	 */
	public function register_irt_currency( array $currencies ): array {
		if ( ! isset( $currencies['IRT'] ) ) {
			$currency          = $this->get_currency_service()->get_currency( 'IRT' );
			$currencies['IRT'] = $currency['name'];
		}

		return $currencies;
	}

	/**
	 * Provide default IRT display symbol when WooCommerce has no custom symbol set.
	 */
	public function filter_irt_currency_symbol( string $symbol, string $currency ): string {
		if ( 'IRT' === $currency && ( '' === trim( $symbol ) || 'IRT' === $symbol ) ) {
			return $this->get_currency_service()->get_currency_symbol( 'IRT' );
		}

		return $symbol;
	}

	// -------------------------------------------------------------------------
	// Identity & Checkout Mobile Sync
	// -------------------------------------------------------------------------

	/**
	 * Optionally match a unique, non-privileged WooCommerce customer by billing_phone during OTP login.
	 */
	public function find_user_by_billing_phone( mixed $user_id, string $mobile ): mixed {
		if ( is_numeric( $user_id ) && (int) $user_id > 0 ) {
			return (int) $user_id;
		}

		$settings = self::get_settings();
		if ( empty( $settings['allow_unverified_billing_login'] ) ) {
			return $user_id;
		}

		$normalized = PhoneNumber::normalize_e164( $mobile );
		if ( null === $normalized ) {
			return $user_id;
		}

		$candidates = [ $normalized ];
		$iran_local = PhoneNumber::to_iran_local( $normalized );
		if ( null !== $iran_local ) {
			$without_zero = substr( $iran_local, 1 );
			$candidates[] = $iran_local;
			$candidates[] = '0098' . $without_zero;
			$candidates[] = '98' . $without_zero;
		}

		$users = get_users(
			[
				'meta_query' => [
					[
						'key'     => 'billing_phone',
						'value'   => $candidates,
						'compare' => 'IN',
					],
				],
				'number'     => 2,
				'fields'     => 'ID',
			]
		);

		if ( ! is_array( $users ) || 1 !== count( $users ) ) {
			return $user_id;
		}

		$first        = $users[0];
		$candidate_id = is_object( $first ) ? (int) $first->ID : (int) $first;

		if ( $candidate_id <= 0 || ! AuthModule::is_user_allowed_otp_login( $candidate_id ) ) {
			return $user_id;
		}

		$existing_mobile = (string) get_user_meta( $candidate_id, AuthModule::META_MOBILE, true );
		if ( '' !== trim( $existing_mobile ) ) {
			$existing_e164 = PhoneNumber::normalize_e164( $existing_mobile );
			if ( $existing_e164 !== $normalized ) {
				return $user_id;
			}
		}

		return $candidate_id;
	}

	/**
	 * Populate billing_phone from verified OTP mobile when empty or invalid.
	 */
	public function sync_billing_phone_from_otp( int $user_id, string $mobile ): void {
		if ( ! self::is_auth_module_active() ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['sync_mobile_checkout'] ) || $user_id <= 0 ) {
			return;
		}

		$normalized = PhoneNumber::normalize_e164( $mobile );
		if ( null === $normalized ) {
			return;
		}

		$existing = (string) get_user_meta( $user_id, 'billing_phone', true );
		if ( '' === trim( $existing ) || null === PhoneNumber::normalize_e164( $existing ) ) {
			update_user_meta( $user_id, 'billing_phone', $normalized );
		}
	}

	/**
	 * Sync _neora_mobile when a customer submits a valid mobile at checkout.
	 *
	 * @param array<string, mixed> $posted
	 */
	public function sync_mobile_from_checkout( int $customer_id, array $posted = [] ): void {
		if ( ! self::is_auth_module_active() ) {
			return;
		}

		$raw_phone = isset( $posted['billing_phone'] ) && is_scalar( $posted['billing_phone'] )
			? (string) $posted['billing_phone']
			: '';

		$this->maybe_claim_user_mobile( $customer_id, $raw_phone );
	}

	/**
	 * Sync _neora_mobile when a customer saves their WooCommerce billing address.
	 */
	public function sync_mobile_from_saved_address( int $user_id, string $load_address = 'billing' ): void {
		if ( 'billing' !== $load_address ) {
			return;
		}

		$raw_phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		$this->maybe_claim_user_mobile( $user_id, $raw_phone );
	}

	/**
	 * Prefill checkout billing_phone with the logged-in user's verified OTP mobile.
	 */
	public function prefill_checkout_phone( mixed $value, string $input ): mixed {
		if ( 'billing_phone' !== $input || ( is_string( $value ) && '' !== trim( $value ) ) ) {
			return $value;
		}

		if ( ! self::is_auth_module_active() ) {
			return $value;
		}

		$settings = self::get_settings();
		if ( empty( $settings['sync_mobile_checkout'] ) || ! is_user_logged_in() ) {
			return $value;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $value;
		}

		$mobile = (string) get_user_meta( $user_id, AuthModule::META_MOBILE, true );
		return '' !== $mobile ? $mobile : $value;
	}

	/**
	 * Enforce mobile phone format at classic checkout when enabled in settings.
	 */
	public function validate_checkout_phone(): void {
		$settings = self::get_settings();
		$iran     = ! empty( $settings['enforce_iran_phone'] );
		$intl     = ! empty( $settings['enforce_international_phone'] );
		if ( ! $iran && ! $intl ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce checkout handler.
		$raw_phone = isset( $_POST['billing_phone'] ) && is_scalar( $_POST['billing_phone'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( (string) $_POST['billing_phone'] ) )
			: '';

		if ( '' === trim( $raw_phone ) ) {
			return;
		}

		$is_valid = $iran
			? ( null !== PhoneNumber::to_iran_local( $raw_phone ) )
			: ( null !== PhoneNumber::normalize_e164( $raw_phone ) );

		if ( ! $is_valid && function_exists( 'wc_add_notice' ) ) {
			$message = $iran
				? __( 'Please enter a valid Iranian mobile number (e.g. 09123456789).', 'neora-core' )
				: __( 'Please enter a valid international mobile number (e.g. +989123456789).', 'neora-core' );
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Enforce mobile phone format on WooCommerce Block Checkout (Store API).
	 */
	public function validate_store_api_checkout_phone( mixed $order, mixed $request = null ): void {
		$settings = self::get_settings();
		$iran     = ! empty( $settings['enforce_iran_phone'] );
		$intl     = ! empty( $settings['enforce_international_phone'] );
		if ( ( ! $iran && ! $intl ) || ! is_object( $order ) || ! method_exists( $order, 'get_billing_phone' ) ) {
			return;
		}

		$raw_phone = trim( (string) $order->get_billing_phone() );
		if ( '' === $raw_phone ) {
			return;
		}

		$is_valid = $iran
			? ( null !== PhoneNumber::to_iran_local( $raw_phone ) )
			: ( null !== PhoneNumber::normalize_e164( $raw_phone ) );

		if ( $is_valid ) {
			return;
		}

		$message = $iran
			? __( 'Please enter a valid Iranian mobile number (e.g. 09123456789).', 'neora-core' )
			: __( 'Please enter a valid international mobile number (e.g. +989123456789).', 'neora-core' );

		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'neora_wc_invalid_billing_phone',
				$message,
				400
			);
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	// -------------------------------------------------------------------------
	// Order Transactional SMS
	// -------------------------------------------------------------------------

	public function handle_order_created( mixed $order_or_id ): void {
		$this->dispatch_order_sms( $order_or_id, 'created', 'order_created_pattern' );
	}

	public function handle_order_completed( mixed $order_or_id ): void {
		$this->dispatch_order_sms( $order_or_id, 'completed', 'order_completed_pattern' );
	}

	// -------------------------------------------------------------------------
	// Settings Tab Integration
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, array{title: string, callback?: callable}> $tabs
	 * @return array<string, array{title: string, callback?: callable}>
	 */
	public function register_settings_tab( array $tabs ): array {
		$tabs['woocommerce'] = [
			'title'    => __( 'WooCommerce', 'neora-core' ),
			'callback' => [ $this, 'render_settings_tab' ],
		];

		return $tabs;
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => self::get_default_settings(),
			]
		);

		add_settings_section(
			'neora_wc_bridge_section',
			__( 'WooCommerce Integration & Order SMS', 'neora-core' ),
			[ $this, 'render_section_description' ],
			self::SETTINGS_PAGE_SLUG
		);

		add_settings_field(
			'sync_mobile_checkout',
			__( 'Mobile & Checkout Sync', 'neora-core' ),
			[ $this, 'render_checkbox_field' ],
			self::SETTINGS_PAGE_SLUG,
			'neora_wc_bridge_section',
			[
				'key'   => 'sync_mobile_checkout',
				'label' => __( 'Sync verified OTP mobile with WooCommerce billing phone and prefill checkout', 'neora-core' ),
			]
		);

		add_settings_field(
			'enforce_iran_phone',
			__( 'Iranian Mobile Validation', 'neora-core' ),
			[ $this, 'render_checkbox_field' ],
			self::SETTINGS_PAGE_SLUG,
			'neora_wc_bridge_section',
			[
				'key'   => 'enforce_iran_phone',
				'label' => __( 'Require a valid Iranian mobile number (09xxxxxxxxx) on checkout', 'neora-core' ),
			]
		);

		add_settings_field(
			'enforce_international_phone',
			__( 'International Phone Validation', 'neora-core' ),
			[ $this, 'render_checkbox_field' ],
			self::SETTINGS_PAGE_SLUG,
			'neora_wc_bridge_section',
			[
				'key'   => 'enforce_international_phone',
				'label' => __( 'Require a valid international E.164 or regional mobile number on checkout', 'neora-core' ),
			]
		);

		add_settings_field(
			'async_order_sms',
			__( 'Asynchronous Order SMS', 'neora-core' ),
			[ $this, 'render_checkbox_field' ],
			self::SETTINGS_PAGE_SLUG,
			'neora_wc_bridge_section',
			[
				'key'   => 'async_order_sms',
				'label' => __( 'Dispatch order notifications through the async SMS queue with automatic retries', 'neora-core' ),
			]
		);

		add_settings_field(
			'allow_unverified_billing_login',
			__( 'Legacy Billing Phone Login', 'neora-core' ),
			[ $this, 'render_checkbox_field' ],
			self::SETTINGS_PAGE_SLUG,
			'neora_wc_bridge_section',
			[
				'key'   => 'allow_unverified_billing_login',
				'label' => __( 'Allow matching unique non-privileged WooCommerce customers by unverified billing_phone during OTP login (not recommended unless migrating trusted legacy accounts)', 'neora-core' ),
			]
		);

		add_settings_field(
			'order_created_pattern',
			__( 'New Order Pattern Code (BodyId)', 'neora-core' ),
			[ $this, 'render_pattern_field' ],
			self::SETTINGS_PAGE_SLUG,
			'neora_wc_bridge_section',
			[
				'key'         => 'order_created_pattern',
				'description' => __( 'Optional Melipayamak BodyId sent to customer on new order (passes order number as parameter). Leave blank to disable.', 'neora-core' ),
			]
		);

		add_settings_field(
			'order_completed_pattern',
			__( 'Completed Order Pattern Code (BodyId)', 'neora-core' ),
			[ $this, 'render_pattern_field' ],
			self::SETTINGS_PAGE_SLUG,
			'neora_wc_bridge_section',
			[
				'key'         => 'order_completed_pattern',
				'description' => __( 'Optional Melipayamak BodyId sent to customer when order status becomes Completed. Leave blank to disable.', 'neora-core' ),
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_default_settings(): array {
		return [
			'sync_mobile_checkout'           => true,
			'enforce_iran_phone'             => false,
			'enforce_international_phone'    => false,
			'async_order_sms'                => true,
			'allow_unverified_billing_login' => false,
			'order_created_pattern'          => '',
			'order_completed_pattern'        => '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, [] ) : [];
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return array_merge( self::get_default_settings(), $saved );
	}

	/**
	 * @param mixed $input Raw form input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_default_settings();
		}

		return [
			'sync_mobile_checkout'           => ! empty( $input['sync_mobile_checkout'] ),
			'enforce_iran_phone'             => ! empty( $input['enforce_iran_phone'] ),
			'enforce_international_phone'    => ! empty( $input['enforce_international_phone'] ),
			'async_order_sms'                => ! empty( $input['async_order_sms'] ),
			'allow_unverified_billing_login' => ! empty( $input['allow_unverified_billing_login'] ),
			'order_created_pattern'          => self::sanitize_pattern_code( $input['order_created_pattern'] ?? '' ),
			'order_completed_pattern'        => self::sanitize_pattern_code( $input['order_completed_pattern'] ?? '' ),
		];
	}

	public function render_settings_tab(): void {
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php
			settings_fields( self::OPTION_GROUP );
			do_settings_sections( self::SETTINGS_PAGE_SLUG );
			?>
			<p class="submit">
				<button type="submit" class="neora-btn neora-btn--primary">
					<?php esc_html_e( 'Save Settings', 'neora-core' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	public function render_section_description(): void {
		echo '<p>' . esc_html__( 'Configure WooCommerce customer mobile synchronization and optional Melipayamak order notifications.', 'neora-core' ) . '</p>';
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_checkbox_field( array $args ): void {
		$key      = $args['key'] ?? '';
		$label    = $args['label'] ?? '';
		$settings = self::get_settings();
		$checked  = ! empty( $settings[ $key ] );
		$id       = 'neora_wc_' . $key;
		?>
		<label for="<?php echo esc_attr( $id ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>"
				value="1"
				<?php checked( $checked, true ); ?>
			/>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_pattern_field( array $args ): void {
		$key         = $args['key'] ?? '';
		$description = $args['description'] ?? '';
		$settings    = self::get_settings();
		$value       = (string) ( $settings[ $key ] ?? '' );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( 'neora_wc_' . $key ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="123456"
			class="regular-text"
			dir="ltr"
			inputmode="numeric"
		/>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Internal Helpers
	// -------------------------------------------------------------------------

	private static function is_auth_module_active(): bool {
		$app = Bootstrap::app();
		if ( null === $app ) {
			return true;
		}

		return $app->module_manager()->is_active( AuthModule::KEY );
	}

	private function maybe_claim_user_mobile( int $user_id, string $raw_phone ): void {
		$settings = self::get_settings();
		if ( empty( $settings['sync_mobile_checkout'] ) || $user_id <= 0 ) {
			return;
		}

		$normalized = PhoneNumber::normalize_e164( $raw_phone );
		if ( null === $normalized ) {
			return;
		}

		$existing = (string) get_user_meta( $user_id, AuthModule::META_MOBILE, true );
		if ( '' !== trim( $existing ) ) {
			return;
		}

		// Prevent claiming a mobile already verified by another account.
		$owners = get_users(
			[
				'meta_key'   => AuthModule::META_MOBILE,
				'meta_value' => $normalized,
				'exclude'    => [ $user_id ],
				'number'     => 1,
				'fields'     => 'ID',
			]
		);

		if ( is_array( $owners ) && ! empty( $owners ) ) {
			return;
		}

		update_user_meta( $user_id, AuthModule::META_MOBILE, $normalized );
	}

	private function dispatch_order_sms( mixed $order_or_id, string $event, string $setting_key ): void {
		$settings     = self::get_settings();
		$pattern_code = (string) ( $settings[ $setting_key ] ?? '' );

		if ( '' === $pattern_code ) {
			return;
		}

		$order = is_object( $order_or_id )
			? $order_or_id
			: ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_or_id ) : null );

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_billing_phone' ) ) {
			return;
		}

		$meta_flag = '_neora_sms_sent_' . $event;
		if ( method_exists( $order, 'get_meta' ) && '' !== (string) $order->get_meta( $meta_flag ) ) {
			return;
		}

		$mobile = PhoneNumber::normalize_e164( (string) $order->get_billing_phone() );
		if ( null === $mobile ) {
			return;
		}

		$order_number = method_exists( $order, 'get_order_number' )
			? (string) $order->get_order_number()
			: (string) ( method_exists( $order, 'get_id' ) ? $order->get_id() : '' );

		$args = apply_filters( 'neora_wc_order_sms_args', [ $order_number ], $order, $event );
		if ( ! is_array( $args ) || empty( $args ) ) {
			return;
		}

		$str_args     = array_map( 'strval', array_values( $args ) );
		$sms_settings = SettingsPage::get_settings();
		$use_async    = ! empty( $settings['async_order_sms'] ) && ! empty( $sms_settings['async_queue_enabled'] );
		$context      = [
			'source' => 'woocommerce',
			'event'  => $event,
		];

		if ( $use_async ) {
			$queued = $this->get_sms_queue()->enqueue_pattern( $mobile, $str_args, $pattern_code, null, null, $context );
			$ok     = ! is_wp_error( $queued );
		} else {
			$result = $this->get_sms_manager()->send_pattern( $mobile, $str_args, $pattern_code, null, null, $context );
			$ok     = true === $result;
		}

		if ( $ok && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( $meta_flag, (string) time() );
			if ( method_exists( $order, 'save_meta_data' ) ) {
				$order->save_meta_data();
			}
		}
	}

	private function get_currency_service(): CurrencyService {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.currency' ) ) {
			/** @var CurrencyService $service */
			$service = $app->container()->get( 'neora.currency' );
			return $service;
		}

		return new CurrencyService();
	}

	private function get_sms_manager(): SmsManager {
		if ( null === $this->sms_manager ) {
			$this->sms_manager = new SmsManager(
				[
					new MelipayamakProvider( $this->sms_client ?? new MelipayamakClient() ),
					new TwilioProvider(),
					new WebhookProvider(),
				]
			);
		}

		return $this->sms_manager;
	}

	private function get_sms_queue(): SmsQueue {
		if ( null === $this->sms_queue ) {
			$this->sms_queue = new SmsQueue( $this->get_sms_manager() );
		}

		return $this->sms_queue;
	}

	private static function sanitize_pattern_code( mixed $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$normalized = OtpManager::normalize_digits( (string) $raw );
		return preg_replace( '/[^0-9]/', '', $normalized ) ?? '';
	}
}