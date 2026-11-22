<?php
/**
 * Admin Settings Page Module (Tabbed & Extensible Configuration).
 *
 * @package NeoraStudio\NeoraCore
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Admin;

use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Services\Branding\BrandingService;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use NeoraStudio\NeoraCore\Services\Sms\MelipayamakClient;
use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use NeoraStudio\NeoraCore\Services\Sms\Providers\MelipayamakProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\TwilioProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\WebhookProvider;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use NeoraStudio\NeoraCore\Services\Sms\SmsQueue;
use NeoraStudio\NeoraCore\Support\SecretBox;

final class SettingsPage implements ModuleMetadataInterface {

	public const OPTION_KEY          = 'neora_sms_settings';
	public const GENERAL_OPTION_KEY  = BrandingService::OPTION_KEY;
	public const REGIONAL_OPTION_KEY = CurrencyService::OPTION_KEY;

	private const OPTION_GROUP          = 'neora_core_settings_group';
	private const GENERAL_OPTION_GROUP  = 'neora_core_general_group';
	private const REGIONAL_OPTION_GROUP = 'neora_core_regional_group';
	private const PAGE_SLUG             = 'neora-core-settings';
	private const GENERAL_PAGE_SLUG     = 'neora-core-settings-general';
	private const REGIONAL_PAGE_SLUG    = 'neora-core-settings-regional';

	public function get_key(): string {
		return 'neora/settings-page';
	}

	public function get_name(): string {
		return __( 'Core & SMS Settings', 'neora-core' );
	}

	public function get_description(): string {
		return __( 'Configures Melipayamak SMS gateway credentials, sandbox mode, and core options.', 'neora-core' );
	}

	public function is_core(): bool {
		return true;
	}

	public function is_default_enabled(): bool {
		return true;
	}

	public function get_missing_dependencies(): array {
		return [];
	}

	public function has_rewrite_rules(): bool {
		return false;
	}

	public function register( Container $container ): void {}

	public function boot( HookRegistry $hooks ): void {
		$hooks->action( 'admin_menu', [ $this, 'register_submenu' ], 20 );
		$hooks->action( 'admin_init', [ $this, 'register_settings' ] );
		$hooks->action( 'admin_notices', [ $this, 'render_secretbox_notice' ] );
		$hooks->filter( 'pre_update_option_' . self::OPTION_KEY, [ $this, 'encrypt_password_before_save' ], 10, 2 );
		$hooks->action( 'wp_ajax_neora_test_sms', [ $this, 'handle_ajax_test_sms' ] );
		$hooks->action( 'wp_ajax_neora_sms_queue_action', [ $this, 'handle_ajax_queue_action' ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'neora-core',
			__( 'Neora Core Settings', 'neora-core' ),
			__( 'Settings', 'neora-core' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Return registered settings tabs (extensible via 'neora_core_settings_tabs').
	 *
	 * @return array<string, array{title: string, callback?: callable}>
	 */
	public static function get_tabs(): array {
		$tabs = [
			'general'  => [
				'title' => __( 'General & Branding', 'neora-core' ),
			],
			'regional' => [
				'title' => __( 'Currency & Regional', 'neora-core' ),
			],
			'sms'      => [
				'title' => __( 'SMS & Authentication', 'neora-core' ),
			],
		];

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_settings_tabs', $tabs );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				return $filtered;
			}
		}

		return $tabs;
	}

	public function register_settings(): void {
		// 1. General / Branding settings.
		register_setting(
			self::GENERAL_OPTION_GROUP,
			self::GENERAL_OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_general_settings' ],
				'default'           => self::get_default_general_settings(),
			]
		);

		add_settings_section(
			'neora_general_branding_section',
			__( 'Platform Branding (White-Label)', 'neora-core' ),
			[ $this, 'render_general_section_description' ],
			self::GENERAL_PAGE_SLUG
		);

		add_settings_field(
			'brand_name',
			__( 'Platform Brand Name', 'neora-core' ),
			[ $this, 'render_general_text_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'brand_name',
				'placeholder' => 'Neora Core',
				'description' => __( 'Displayed as the main dashboard title for this client site.', 'neora-core' ),
			]
		);

		add_settings_field(
			'menu_label',
			__( 'Admin Menu Label', 'neora-core' ),
			[ $this, 'render_general_text_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'menu_label',
				'placeholder' => 'Neora Core',
				'description' => __( 'Customizes the top-level WordPress admin menu item label.', 'neora-core' ),
			]
		);

		add_settings_field(
			'menu_icon',
			__( 'Admin Menu Icon', 'neora-core' ),
			[ $this, 'render_general_select_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'menu_icon',
				'options'     => BrandingService::get_menu_icons(),
				'description' => __( 'Selects the Dashicon shown next to the top-level admin menu item.', 'neora-core' ),
			]
		);

		add_settings_field(
			'logo_url',
			__( 'Brand Logo', 'neora-core' ),
			[ $this, 'render_general_logo_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'logo_url',
				'description' => __( 'Optional square logo URL displayed in Neora admin headers. Leave empty to use the brand monogram.', 'neora-core' ),
			]
		);

		add_settings_field(
			'brand_color',
			__( 'Brand Accent Color', 'neora-core' ),
			[ $this, 'render_general_color_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'brand_color',
				'description' => __( 'Primary accent color applied to Neora admin buttons, active tabs, switches, and badges.', 'neora-core' ),
			]
		);

		add_settings_field(
			'dashboard_subtitle',
			__( 'Dashboard Subtitle', 'neora-core' ),
			[ $this, 'render_general_text_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'dashboard_subtitle',
				'placeholder' => __( 'Modular WordPress platform foundation & module manager.', 'neora-core' ),
				'description' => __( 'Displayed beneath the main heading on the Modules Dashboard.', 'neora-core' ),
			]
		);

		add_settings_field(
			'settings_subtitle',
			__( 'Settings Header Subtitle', 'neora-core' ),
			[ $this, 'render_general_text_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'settings_subtitle',
				'placeholder' => __( 'Manage branding, SMS gateway, and active module configurations.', 'neora-core' ),
				'description' => __( 'Displayed beneath the main heading on the Settings page.', 'neora-core' ),
			]
		);

		add_settings_field(
			'admin_footer_text',
			__( 'Admin Footer Text', 'neora-core' ),
			[ $this, 'render_general_text_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'         => 'admin_footer_text',
				'placeholder' => __( 'Powered by Neora Studio', 'neora-core' ),
				'description' => __( 'Optional custom footer text displayed on Neora Core admin pages.', 'neora-core' ),
			]
		);

		add_settings_field(
			'show_version_badge',
			__( 'Version Badge', 'neora-core' ),
			[ $this, 'render_general_checkbox_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'   => 'show_version_badge',
				'label' => __( 'Display the plugin version badge in the dashboard header', 'neora-core' ),
			]
		);

		add_settings_field(
			'show_system_info',
			__( 'System Info Card', 'neora-core' ),
			[ $this, 'render_general_checkbox_field' ],
			self::GENERAL_PAGE_SLUG,
			'neora_general_branding_section',
			[
				'key'   => 'show_system_info',
				'label' => __( 'Display the System version summary card on the dashboard', 'neora-core' ),
			]
		);

		// 2. SMS Gateway settings.
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => self::get_default_settings(),
			]
		);

		$sms_manager      = $this->get_sms_manager();
		$provider_options = $sms_manager->get_provider_options();
		$intl_options     = [ '' => __( 'Disabled (Primary provider only)', 'neora-core' ) ]
			+ $sms_manager->get_provider_options( true );

		add_settings_section(
			'neora_sms_gateway_section',
			__( 'SMS Routing & Primary Gateway (Melipayamak)', 'neora-core' ),
			[ $this, 'render_section_description' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'sandbox_mode',
			__( 'Sandbox Mode (Local Test)', 'neora-core' ),
			[ $this, 'render_sandbox_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section'
		);

		add_settings_field(
			'default_provider',
			__( 'Primary SMS Provider', 'neora-core' ),
			[ $this, 'render_sms_select_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section',
			[
				'key'         => 'default_provider',
				'options'     => $provider_options,
				'description' => __( 'Primary gateway used for domestic and default SMS delivery.', 'neora-core' ),
			]
		);

		add_settings_field(
			'international_provider',
			__( 'International Fallback Provider', 'neora-core' ),
			[ $this, 'render_sms_select_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section',
			[
				'key'         => 'international_provider',
				'options'     => $intl_options,
				'description' => __( 'Automatically used for non-Iranian E.164 numbers when the primary provider only supports Iran.', 'neora-core' ),
			]
		);

		add_settings_field(
			'async_queue_enabled',
			__( 'Async Queue & Retries', 'neora-core' ),
			[ $this, 'render_sms_checkbox_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section',
			[
				'key'   => 'async_queue_enabled',
				'label' => __( 'Enable background queue and exponential-backoff retries for transactional SMS', 'neora-core' ),
			]
		);

		add_settings_field(
			'username',
			__( 'Melipayamak Username', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section',
			[
				'key'         => 'username',
				'type'        => 'text',
				'placeholder' => '0912xxxxxxx',
				'description' => __( 'Your Melipayamak panel username.', 'neora-core' ),
			]
		);

		add_settings_field(
			'password',
			__( 'Melipayamak Password / API Key', 'neora-core' ),
			[ $this, 'render_password_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section',
			[
				'description' => __( 'Leave blank to keep the existing password unchanged.', 'neora-core' ),
			]
		);

		add_settings_field(
			'sender_number',
			__( 'Sender Number', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section',
			[
				'key'         => 'sender_number',
				'type'        => 'text',
				'placeholder' => '5000xxx',
				'description' => __( 'Dedicated SMS line number used for standard (non-pattern) SMS.', 'neora-core' ),
			]
		);

		add_settings_field(
			'pattern_code',
			__( 'OTP Pattern Code (BodyId)', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_gateway_section',
			[
				'key'         => 'pattern_code',
				'type'        => 'text',
				'placeholder' => '123456',
				'description' => __( 'Service pattern code (BodyId) approved in Melipayamak for fast OTP delivery.', 'neora-core' ),
			]
		);

		add_settings_section(
			'neora_sms_international_section',
			__( 'International SMS Providers (Twilio & Webhook)', 'neora-core' ),
			[ $this, 'render_international_sms_description' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'twilio_account_sid',
			__( 'Twilio Account SID', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_international_section',
			[
				'key'         => 'twilio_account_sid',
				'placeholder' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			]
		);

		add_settings_field(
			'twilio_auth_token',
			__( 'Twilio Auth Token', 'neora-core' ),
			[ $this, 'render_secret_field' ],
			self::PAGE_SLUG,
			'neora_sms_international_section',
			[
				'key'         => 'twilio_auth_token',
				'constant'    => 'NEORA_TWILIO_AUTH_TOKEN',
				'description' => __( 'Leave blank to keep the existing token unchanged.', 'neora-core' ),
			]
		);

		add_settings_field(
			'twilio_from',
			__( 'Twilio From Number / Messaging Service SID', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_international_section',
			[
				'key'         => 'twilio_from',
				'placeholder' => '+15005550006',
			]
		);

		add_settings_field(
			'twilio_otp_template',
			__( 'Twilio OTP Message Template', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_international_section',
			[
				'key'         => 'twilio_otp_template',
				'placeholder' => 'Your verification code is: {code}',
				'description' => __( 'Use {code} as placeholder for the OTP code.', 'neora-core' ),
			]
		);

		add_settings_field(
			'webhook_url',
			__( 'Webhook Endpoint URL', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_international_section',
			[
				'key'         => 'webhook_url',
				'type'        => 'url',
				'placeholder' => 'https://sms-gateway.example.com/send',
			]
		);

		add_settings_field(
			'webhook_secret',
			__( 'Webhook Bearer Token', 'neora-core' ),
			[ $this, 'render_secret_field' ],
			self::PAGE_SLUG,
			'neora_sms_international_section',
			[
				'key'         => 'webhook_secret',
				'constant'    => 'NEORA_WEBHOOK_SECRET',
				'description' => __( 'Optional Bearer token sent in the Authorization header.', 'neora-core' ),
			]
		);

		add_settings_field(
			'webhook_otp_template',
			__( 'Webhook OTP Fallback Template', 'neora-core' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'neora_sms_international_section',
			[
				'key'         => 'webhook_otp_template',
				'placeholder' => 'Your verification code is: {code}',
			]
		);

		// 3. Currency & Regional settings.
		$currency_service = $this->get_currency_service();
		$currency_options = [];
		foreach ( $currency_service->get_currencies() as $code => $info ) {
			$currency_options[ $code ] = sprintf( '%s — %s (%s)', $code, $info['name'], $info['symbol'] );
		}

		register_setting(
			self::REGIONAL_OPTION_GROUP,
			self::REGIONAL_OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_regional_settings' ],
				'default'           => CurrencyService::get_default_settings(),
			]
		);

		add_settings_section(
			'neora_regional_currency_section',
			__( 'Currency & Regional Preferences', 'neora-core' ),
			[ $this, 'render_regional_section_description' ],
			self::REGIONAL_PAGE_SLUG
		);

		add_settings_field(
			'default_currency',
			__( 'Default Currency', 'neora-core' ),
			[ $this, 'render_regional_select_field' ],
			self::REGIONAL_PAGE_SLUG,
			'neora_regional_currency_section',
			[
				'key'         => 'default_currency',
				'options'     => $currency_options,
				'description' => __( 'Primary currency used for Services pricing and Neora formatting helpers.', 'neora-core' ),
			]
		);

		add_settings_field(
			'default_country',
			__( 'Default Country / Region', 'neora-core' ),
			[ $this, 'render_regional_select_field' ],
			self::REGIONAL_PAGE_SLUG,
			'neora_regional_currency_section',
			[
				'key'         => 'default_country',
				'options'     => $currency_service->get_supported_countries(),
				'description' => __( 'Default ISO region used for regional formatting and phone number normalization.', 'neora-core' ),
			]
		);

		add_settings_field(
			'numeral_style',
			__( 'Numeral Style', 'neora-core' ),
			[ $this, 'render_regional_select_field' ],
			self::REGIONAL_PAGE_SLUG,
			'neora_regional_currency_section',
			[
				'key'     => 'numeral_style',
				'options' => [
					'auto'    => __( 'Automatic (Match site language / Polylang)', 'neora-core' ),
					'persian' => __( 'Persian digits (۰-۹)', 'neora-core' ),
					'latin'   => __( 'Latin digits (0-9)', 'neora-core' ),
				],
			]
		);

		add_settings_field(
			'thousand_separator',
			__( 'Thousand Separator', 'neora-core' ),
			[ $this, 'render_regional_select_field' ],
			self::REGIONAL_PAGE_SLUG,
			'neora_regional_currency_section',
			[
				'key'     => 'thousand_separator',
				'options' => [
					'comma'         => __( 'Comma (,)', 'neora-core' ),
					'persian_comma' => __( 'Persian separator (٬)', 'neora-core' ),
					'space'         => __( 'Space ( )', 'neora-core' ),
					'dot'           => __( 'Dot (.)', 'neora-core' ),
					'none'          => __( 'None', 'neora-core' ),
				],
			]
		);

		add_settings_field(
			'decimal_separator',
			__( 'Decimal Separator', 'neora-core' ),
			[ $this, 'render_regional_select_field' ],
			self::REGIONAL_PAGE_SLUG,
			'neora_regional_currency_section',
			[
				'key'     => 'decimal_separator',
				'options' => [
					'dot'             => __( 'Dot (.)', 'neora-core' ),
					'slash'           => __( 'Slash (/)', 'neora-core' ),
					'persian_decimal' => __( 'Persian decimal (٫)', 'neora-core' ),
					'comma'           => __( 'Comma (,)', 'neora-core' ),
				],
			]
		);

		add_settings_field(
			'symbol_position',
			__( 'Currency Symbol Position', 'neora-core' ),
			[ $this, 'render_regional_select_field' ],
			self::REGIONAL_PAGE_SLUG,
			'neora_regional_currency_section',
			[
				'key'     => 'symbol_position',
				'options' => [
					'default'      => __( 'Currency default', 'neora-core' ),
					'before'       => __( 'Left ($100)', 'neora-core' ),
					'before_space' => __( 'Left with space ($ 100)', 'neora-core' ),
					'after'        => __( 'Right (100$)', 'neora-core' ),
					'after_space'  => __( 'Right with space (100 tomans)', 'neora-core' ),
				],
			]
		);

		add_settings_field(
			'sync_woocommerce_currency',
			__( 'WooCommerce Currency Sync', 'neora-core' ),
			[ $this, 'render_regional_checkbox_field' ],
			self::REGIONAL_PAGE_SLUG,
			'neora_regional_currency_section',
			[
				'key'   => 'sync_woocommerce_currency',
				'label' => __( 'Automatically follow WooCommerce active store currency when WooCommerce is active', 'neora-core' ),
			]
		);
	}

	/**
	 * @return array<string, string|bool>
	 */
	public static function get_default_general_settings(): array {
		return BrandingService::get_default_settings();
	}

	/**
	 * @return array<string, string|bool>
	 */
	public static function get_general_settings(): array {
		return self::resolve_branding_service()->get_settings();
	}

	/**
	 * @param mixed $input Raw general form input.
	 * @return array<string, string|bool>
	 */
	public function sanitize_general_settings( mixed $input ): array {
		return $this->get_branding_service()->sanitize_settings( $input );
	}

	private static function resolve_branding_service(): BrandingService {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.branding' ) ) {
			/** @var BrandingService $service */
			$service = $app->container()->get( 'neora.branding' );
			return $service;
		}

		return new BrandingService();
	}

	private function get_branding_service(): BrandingService {
		return self::resolve_branding_service();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_regional_settings(): array {
		return CurrencyService::get_settings();
	}

	/**
	 * @param mixed $input Raw regional form input.
	 * @return array<string, mixed>
	 */
	public function sanitize_regional_settings( mixed $input ): array {
		return $this->get_currency_service()->sanitize_settings( $input );
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

	public static function is_password_defined_in_config(): bool {
		return defined( 'NEORA_SMS_PASSWORD' )
			&& is_string( constant( 'NEORA_SMS_PASSWORD' ) )
			&& '' !== constant( 'NEORA_SMS_PASSWORD' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_default_settings(): array {
		return [
			'sandbox_mode'           => true,
			'default_provider'       => 'melipayamak',
			'international_provider' => '',
			'async_queue_enabled'    => true,
			'username'               => '',
			'password'               => '',
			'sender_number'          => '',
			'pattern_code'           => '',
			'twilio_account_sid'     => '',
			'twilio_auth_token'      => '',
			'twilio_from'            => '',
			'twilio_otp_template'    => '',
			'webhook_url'            => '',
			'webhook_secret'         => '',
			'webhook_otp_template'   => '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$merged  = array_merge( self::get_default_settings(), $saved );
		$secrets = [
			'password'          => 'NEORA_SMS_PASSWORD',
			'twilio_auth_token' => 'NEORA_TWILIO_AUTH_TOKEN',
			'webhook_secret'    => 'NEORA_WEBHOOK_SECRET',
		];

		foreach ( $secrets as $key => $const_name ) {
			if ( self::is_secret_defined_in_config( $const_name ) ) {
				$merged[ $key ] = (string) constant( $const_name );
			} elseif ( '' !== (string) ( $merged[ $key ] ?? '' ) ) {
				$merged[ $key ] = SecretBox::decrypt( (string) $merged[ $key ] );
			}
		}

		return $merged;
	}

	public function encrypt_password_before_save( mixed $value, mixed $old_value = null ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( [ 'password', 'twilio_auth_token', 'webhook_secret' ] as $secret_key ) {
			if ( empty( $value[ $secret_key ] ) || ! is_string( $value[ $secret_key ] ) ) {
				continue;
			}

			$encrypted = SecretBox::encrypt( $value[ $secret_key ] );
			if ( '' === $encrypted && null !== SecretBox::get_last_error() ) {
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error(
						self::OPTION_KEY,
						'neora_secretbox_encrypt_failed',
						__( 'Could not encrypt the SMS password because OpenSSL encryption is unavailable. Please define NEORA_SMS_PASSWORD in wp-config.php.', 'neora-core' ),
						'error'
					);
				}

				$value[ $secret_key ] = ( is_array( $old_value ) && isset( $old_value[ $secret_key ] ) && is_string( $old_value[ $secret_key ] ) )
					? $old_value[ $secret_key ]
					: '';
				continue;
			}

			$value[ $secret_key ] = $encrypted;
		}

		return $value;
	}

	private static function is_secret_defined_in_config( string $constant_name ): bool {
		return defined( $constant_name )
			&& is_string( constant( $constant_name ) )
			&& '' !== constant( $constant_name );
	}

	public function render_secretbox_notice(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( (string) $screen->id, self::PAGE_SLUG ) || self::is_password_defined_in_config() ) {
			return;
		}

		$raw = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $raw ) || empty( $raw['password'] ) || ! is_string( $raw['password'] ) ) {
			return;
		}

		SecretBox::decrypt( $raw['password'] );
		if ( null === SecretBox::get_last_error() ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'The stored SMS gateway password could not be decrypted (for example, OpenSSL is unavailable or site salts changed). Please re-enter the password or define NEORA_SMS_PASSWORD in wp-config.php.', 'neora-core' );
		echo '</p></div>';
	}

	/**
	 * @param mixed $input Raw form input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $input ): array {
		$existing = self::get_settings();

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$output                        = [];
		$output['sandbox_mode']        = ! empty( $input['sandbox_mode'] );
		$output['async_queue_enabled'] = ! empty( $input['async_queue_enabled'] );

		$raw_primary                = isset( $input['default_provider'] ) && is_scalar( $input['default_provider'] )
			? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $input['default_provider'] ) ) )
			: '';
		$output['default_provider'] = '' !== (string) $raw_primary ? (string) $raw_primary : 'melipayamak';

		$raw_intl                         = isset( $input['international_provider'] ) && is_scalar( $input['international_provider'] )
			? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $input['international_provider'] ) ) )
			: '';
		$output['international_provider'] = (string) ( $raw_intl ?? '' );

		foreach ( [ 'username', 'sender_number', 'twilio_account_sid', 'twilio_from', 'twilio_otp_template', 'webhook_otp_template' ] as $text_key ) {
			$output[ $text_key ] = isset( $input[ $text_key ] )
				? sanitize_text_field( trim( (string) $input[ $text_key ] ) )
				: '';
		}

		$raw_webhook_url = isset( $input['webhook_url'] ) ? trim( (string) $input['webhook_url'] ) : '';
		$webhook_scheme  = '' !== $raw_webhook_url ? strtolower( (string) parse_url( esc_url_raw( $raw_webhook_url ), PHP_URL_SCHEME ) ) : '';
		$output['webhook_url'] = ( '' !== $raw_webhook_url && in_array( $webhook_scheme, [ 'http', 'https' ], true ) && false !== filter_var( $raw_webhook_url, FILTER_VALIDATE_URL ) )
			? esc_url_raw( $raw_webhook_url )
			: '';

		$raw_pattern            = isset( $input['pattern_code'] ) ? trim( (string) $input['pattern_code'] ) : '';
		$normalized_pattern     = strtr(
			$raw_pattern,
			[
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			]
		);
		$output['pattern_code'] = preg_replace( '/[^0-9]/', '', $normalized_pattern ) ?? '';

		$secret_constants = [
			'password'          => 'NEORA_SMS_PASSWORD',
			'twilio_auth_token' => 'NEORA_TWILIO_AUTH_TOKEN',
			'webhook_secret'    => 'NEORA_WEBHOOK_SECRET',
		];

		$raw_saved = null;
		foreach ( $secret_constants as $secret_key => $const_name ) {
			$submitted = isset( $input[ $secret_key ] ) ? trim( (string) $input[ $secret_key ] ) : '';
			if ( self::is_secret_defined_in_config( $const_name ) ) {
				if ( null === $raw_saved ) {
					$raw_saved = get_option( self::OPTION_KEY, [] );
				}
				$output[ $secret_key ] = is_array( $raw_saved ) && isset( $raw_saved[ $secret_key ] )
					? (string) $raw_saved[ $secret_key ]
					: '';
			} elseif ( '' !== $submitted ) {
				$output[ $secret_key ] = sanitize_text_field( $submitted );
			} else {
				$output[ $secret_key ] = (string) ( $existing[ $secret_key ] ?? '' );
			}
		}

		return $output;
	}

	/**
	 * Handle AJAX request to test SMS configuration.
	 */
	public function handle_ajax_test_sms(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'You do not have permission to perform this action.', 'neora-core' ) ],
				403
			);
		}

		check_ajax_referer( 'neora_test_sms', 'nonce' );

		$raw_mobile = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mobile'] ) ) : '';
		$country    = isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['country'] ) ) ) : '';
		$country    = 1 === preg_match( '/^[A-Z]{2}$/D', $country ) ? $country : null;
		$mobile     = PhoneNumber::normalize_e164( $raw_mobile, $country );

		if ( null === $mobile ) {
			wp_send_json_error(
				[ 'message' => __( 'The provided mobile number is invalid.', 'neora-core' ) ],
				400
			);
		}

		$result = $this->get_sms_manager()->send_pattern(
			$mobile,
			[ '12345' ],
			null,
			$country,
			null,
			[
				'source' => 'admin_test',
				'is_otp' => true,
			]
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}

		$settings = self::get_settings();
		$message  = ! empty( $settings['sandbox_mode'] )
			? __( 'Sandbox test logged to wp-content/debug.log successfully.', 'neora-core' )
			: __( 'Test SMS dispatched via active SMS gateway successfully.', 'neora-core' );

		wp_send_json_success( [ 'message' => $message ] );
	}

	/**
	 * Handle AJAX queue management actions from the SMS settings page.
	 */
	public function handle_ajax_queue_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'You do not have permission to perform this action.', 'neora-core' ) ],
				403
			);
		}

		check_ajax_referer( 'neora_sms_queue_action', 'nonce' );

		$action = isset( $_POST['queue_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['queue_action'] ) ) : '';
		$queue  = $this->get_sms_queue();

		if ( 'process_due' === $action ) {
			$count   = $queue->process_due_jobs();
			$message = sprintf(
				/* translators: %d: Number of queued SMS jobs processed. */
				__( 'Processed %d due SMS queue job(s).', 'neora-core' ),
				$count
			);
		} elseif ( 'clear_completed' === $action ) {
			$count   = $queue->clear_completed( true );
			$message = sprintf(
				/* translators: %d: Number of finished SMS jobs cleared. */
				__( 'Cleared %d finished SMS queue job(s).', 'neora-core' ),
				$count
			);
		} else {
			wp_send_json_error( [ 'message' => __( 'Invalid SMS queue action.', 'neora-core' ) ], 400 );
			return;
		}

		wp_send_json_success(
			[
				'message' => $message,
				'stats'   => $queue->get_stats(),
			]
		);
	}

	public function render_general_section_description(): void {
		echo '<p>' . esc_html__( 'Customize branding labels when deploying Neora Core on client websites.', 'neora-core' ) . '</p>';
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_general_text_field( array $args ): void {
		$key         = $args['key'] ?? '';
		$placeholder = $args['placeholder'] ?? '';
		$description = $args['description'] ?? '';
		$settings    = self::get_general_settings();
		$value       = (string) ( $settings[ $key ] ?? '' );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( 'neora_general_' . $key ); ?>"
			name="<?php echo esc_attr( self::GENERAL_OPTION_KEY . '[' . $key . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			class="regular-text"
		/>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array{key?: string, options?: array<string, string>, description?: string} $args
	 */
	public function render_general_select_field( array $args ): void {
		$key         = $args['key'] ?? '';
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : [];
		$description = $args['description'] ?? '';
		$settings    = self::get_general_settings();
		$current     = (string) ( $settings[ $key ] ?? '' );
		$id          = 'neora_general_' . $key;
		?>
		<select
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( self::GENERAL_OPTION_KEY . '[' . $key . ']' ); ?>"
		>
			<?php foreach ( $options as $opt_value => $opt_label ) : ?>
				<option value="<?php echo esc_attr( (string) $opt_value ); ?>" <?php selected( $current, (string) $opt_value ); ?>>
					<?php echo esc_html( (string) $opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_general_logo_field( array $args ): void {
		$key         = $args['key'] ?? 'logo_url';
		$description = $args['description'] ?? '';
		$settings    = self::get_general_settings();
		$value       = (string) ( $settings[ $key ] ?? '' );
		$id          = 'neora_general_' . $key;
		?>
		<div class="neora-logo-field">
			<div class="neora-logo-field__preview <?php echo '' !== $value ? 'has-logo' : ''; ?>" id="neora-logo-preview">
				<?php if ( '' !== $value ) : ?>
					<img src="<?php echo esc_url( $value ); ?>" alt="<?php esc_attr_e( 'Brand Logo Preview', 'neora-core' ); ?>" />
				<?php endif; ?>
			</div>
			<div class="neora-logo-field__controls">
				<input
					type="url"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( self::GENERAL_OPTION_KEY . '[' . $key . ']' ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="https://example.com/logo.png"
					class="regular-text"
					dir="ltr"
				/>
				<button type="button" id="neora-select-logo-btn" class="neora-btn neora-btn--secondary">
					<?php esc_html_e( 'Select Logo', 'neora-core' ); ?>
				</button>
				<button type="button" id="neora-remove-logo-btn" class="neora-btn neora-btn--secondary" <?php echo '' === $value ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'Remove', 'neora-core' ); ?>
				</button>
			</div>
		</div>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_general_color_field( array $args ): void {
		$key         = $args['key'] ?? 'brand_color';
		$description = $args['description'] ?? '';
		$settings    = self::get_general_settings();
		$value       = BrandingService::sanitize_hex_color( $settings[ $key ] ?? '' ) ?: BrandingService::DEFAULT_COLOR;
		$id          = 'neora_general_' . $key;
		?>
		<div class="neora-color-field">
			<input
				type="color"
				id="<?php echo esc_attr( $id . '_picker' ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="neora-color-field__picker"
				aria-label="<?php esc_attr_e( 'Brand Color Picker', 'neora-core' ); ?>"
			/>
			<input
				type="text"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::GENERAL_OPTION_KEY . '[' . $key . ']' ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="small-text neora-color-field__hex"
				maxlength="7"
				dir="ltr"
			/>
			<button type="button" id="neora-reset-brand-color" class="neora-btn neora-btn--secondary">
				<?php esc_html_e( 'Reset Default', 'neora-core' ); ?>
			</button>
		</div>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_general_checkbox_field( array $args ): void {
		$key      = $args['key'] ?? '';
		$label    = $args['label'] ?? '';
		$settings = self::get_general_settings();
		$checked  = ! empty( $settings[ $key ] );
		$id       = 'neora_general_' . $key;
		?>
		<label for="<?php echo esc_attr( $id ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::GENERAL_OPTION_KEY . '[' . $key . ']' ); ?>"
				value="1"
				<?php checked( $checked, true ); ?>
			/>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	public function render_regional_section_description(): void {
		$service = $this->get_currency_service();
		$active  = $service->get_currency();
		echo '<p>' . esc_html__( 'Configure active currency, regional numeral formatting, and localization defaults.', 'neora-core' ) . '</p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: active currency code, 2: formatted sample amount */
				__( 'Active effective currency: %1$s (Sample: %2$s)', 'neora-core' ),
				$active['code'],
				$service->format( '1500000' )
			)
		) . '</p>';
	}

	/**
	 * @param array{key?: string, options?: array<string, string>, description?: string} $args
	 */
	public function render_regional_select_field( array $args ): void {
		$key         = $args['key'] ?? '';
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : [];
		$description = $args['description'] ?? '';
		$settings    = self::get_regional_settings();
		$current     = (string) ( $settings[ $key ] ?? '' );
		$id          = 'neora_regional_' . $key;
		?>
		<select
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( self::REGIONAL_OPTION_KEY . '[' . $key . ']' ); ?>"
		>
			<?php foreach ( $options as $opt_value => $opt_label ) : ?>
				<option value="<?php echo esc_attr( (string) $opt_value ); ?>" <?php selected( $current, (string) $opt_value ); ?>>
					<?php echo esc_html( (string) $opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_regional_checkbox_field( array $args ): void {
		$key      = $args['key'] ?? '';
		$label    = $args['label'] ?? '';
		$settings = self::get_regional_settings();
		$checked  = ! empty( $settings[ $key ] );
		$id       = 'neora_regional_' . $key;
		?>
		<label for="<?php echo esc_attr( $id ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::REGIONAL_OPTION_KEY . '[' . $key . ']' ); ?>"
				value="1"
				<?php checked( $checked, true ); ?>
			/>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	public function render_section_description(): void {
		echo '<p>' . esc_html__( 'Configure your primary SMS provider, international fallback routing, and Melipayamak credentials. When Sandbox Mode is enabled, SMS messages are logged to wp-content/debug.log instead of sending over the network.', 'neora-core' ) . '</p>';
	}

	public function render_international_sms_description(): void {
		echo '<p>' . esc_html__( 'Configure international SMS gateways for E.164 numbers outside Iran.', 'neora-core' ) . '</p>';
	}

	/**
	 * @param array{key?: string, options?: array<string, string>, description?: string} $args
	 */
	public function render_sms_select_field( array $args ): void {
		$key         = $args['key'] ?? '';
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : [];
		$description = $args['description'] ?? '';
		$settings    = self::get_settings();
		$current     = (string) ( $settings[ $key ] ?? '' );
		$id          = 'neora_sms_' . $key;
		?>
		<select
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>"
		>
			<?php foreach ( $options as $opt_value => $opt_label ) : ?>
				<option value="<?php echo esc_attr( (string) $opt_value ); ?>" <?php selected( $current, (string) $opt_value ); ?>>
					<?php echo esc_html( (string) $opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_sms_checkbox_field( array $args ): void {
		$key      = $args['key'] ?? '';
		$label    = $args['label'] ?? '';
		$settings = self::get_settings();
		$checked  = ! empty( $settings[ $key ] );
		$id       = 'neora_sms_' . $key;
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
	public function render_secret_field( array $args ): void {
		$key         = $args['key'] ?? '';
		$const_name  = $args['constant'] ?? '';
		$description = $args['description'] ?? '';
		$id          = 'neora_sms_' . $key;

		if ( '' !== $const_name && self::is_secret_defined_in_config( $const_name ) ) :
			?>
			<input type="password" id="<?php echo esc_attr( $id ); ?>" value="" placeholder="••••••••••••••••" class="regular-text" disabled="disabled" dir="ltr" />
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: PHP constant name defined in wp-config.php. */
						__( 'Managed via %s constant in wp-config.php.', 'neora-core' ),
						$const_name
					)
				);
				?>
			</p>
			<?php
			return;
		endif;

		$settings    = self::get_settings();
		$has_secret  = '' !== (string) ( $settings[ $key ] ?? '' );
		$placeholder = $has_secret
			? __( '•••••••• (Saved — enter new value to change)', 'neora-core' )
			: __( 'Enter secret or API token', 'neora-core' );
		?>
		<input
			type="password"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>"
			value=""
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			class="regular-text"
			autocomplete="new-password"
			dir="ltr"
		/>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function get_sms_manager(): SmsManager {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.sms_manager' ) ) {
			/** @var SmsManager $manager */
			$manager = $app->container()->get( 'neora.sms_manager' );
			return $manager;
		}

		return new SmsManager(
			[
				new MelipayamakProvider(),
				new TwilioProvider(),
				new WebhookProvider(),
			]
		);
	}

	private function get_sms_queue(): SmsQueue {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.sms_queue' ) ) {
			/** @var SmsQueue $queue */
			$queue = $app->container()->get( 'neora.sms_queue' );
			return $queue;
		}

		return new SmsQueue( $this->get_sms_manager() );
	}

	public function render_sandbox_field(): void {
		$settings = self::get_settings();
		$checked  = ! empty( $settings['sandbox_mode'] );
		?>
		<label for="neora_sms_sandbox_mode">
			<input
				type="checkbox"
				id="neora_sms_sandbox_mode"
				name="<?php echo esc_attr( self::OPTION_KEY . '[sandbox_mode]' ); ?>"
				value="1"
				<?php checked( $checked, true ); ?>
			/>
			<?php esc_html_e( 'Enable Sandbox Mode (log OTP to debug.log instead of sending real SMS)', 'neora-core' ); ?>
		</label>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_text_field( array $args ): void {
		$key         = $args['key'] ?? '';
		$type        = $args['type'] ?? 'text';
		$placeholder = $args['placeholder'] ?? '';
		$description = $args['description'] ?? '';
		$settings    = self::get_settings();
		$value       = (string) ( $settings[ $key ] ?? '' );
		?>
		<input
			type="<?php echo esc_attr( $type ); ?>"
			id="<?php echo esc_attr( 'neora_sms_' . $key ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			class="regular-text"
			dir="ltr"
		/>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, string> $args
	 */
	public function render_password_field( array $args ): void {
		if ( self::is_password_defined_in_config() ) :
			?>
			<input
				type="password"
				id="neora_sms_password"
				value=""
				placeholder="••••••••••••••••"
				class="regular-text"
				disabled="disabled"
				dir="ltr"
			/>
			<p class="description">
				<?php esc_html_e( 'Managed via NEORA_SMS_PASSWORD constant in wp-config.php.', 'neora-core' ); ?>
			</p>
			<?php
			return;
		endif;

		$description  = $args['description'] ?? '';
		$settings     = self::get_settings();
		$has_password = '' !== (string) ( $settings['password'] ?? '' );
		$placeholder  = $has_password
			? __( '•••••••• (Saved — enter new value to change)', 'neora-core' )
			: __( 'Enter password or API key', 'neora-core' );
		?>
		<input
			type="password"
			id="neora_sms_password"
			name="<?php echo esc_attr( self::OPTION_KEY . '[password]' ); ?>"
			value=""
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			class="regular-text"
			autocomplete="new-password"
			dir="ltr"
		/>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'neora-core' ) );
		}

		$tabs       = self::get_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_tab    = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : '';
		$active_tab = array_key_exists( $raw_tab, $tabs ) ? $raw_tab : (string) array_key_first( $tabs );

		$branding          = $this->get_branding_service();
		$branding_settings = $branding->get_settings();
		$brand_name        = $branding->get_display_brand_name();
		$settings_subtitle = $branding->get_settings_subtitle();
		$brand_logo_url    = (string) ( $branding_settings['logo_url'] ?? '' );
		$brand_monogram    = $branding->get_monogram( $brand_name );

		$view = NEORA_CORE_PATH . 'views/admin/settings.php';
		if ( is_file( $view ) ) {
			require $view;
		}
	}
}