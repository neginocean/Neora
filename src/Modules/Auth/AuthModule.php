<?php
/**
 * Neora Core — Passwordless OTP Authentication Module.
 *
 * @package NeoraStudio\NeoraCore\Modules\Auth
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Modules\Auth;

use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use NeoraStudio\NeoraCore\Services\Sms\OtpManager;
use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AuthModule implements ModuleMetadataInterface {

	/**
	 * Module identifier.
	 */
	public const KEY = 'neora/auth';

	/**
	 * User meta key storing the normalized mobile number.
	 */
	public const META_MOBILE = '_neora_mobile';

	/**
	 * REST API namespace.
	 */
	public const REST_NAMESPACE = 'neora/v1';

	/**
	 * Maximum OTP requests allowed per IP within the rate-limit window.
	 */
	private const IP_RATE_LIMIT_MAX = 10;

	/**
	 * Maximum OTP verification attempts allowed per IP within the rate-limit window.
	 */
	private const VERIFY_IP_RATE_LIMIT_MAX = 15;

	/**
	 * IP rate-limit window in seconds (10 minutes).
	 */
	private const IP_RATE_LIMIT_WINDOW = 600;

	private ?OtpManager $otp_manager;

	public function __construct( ?OtpManager $otp_manager = null ) {
		$this->otp_manager = $otp_manager;
	}

	public function get_key(): string {      
		return self::KEY;      
	}

    public function get_name(): string {
        return __( 'OTP Authentication', 'neora-core' );
    }

	 public function get_description(): string {
        return __( 'Passwordless mobile OTP login and user provisioning via REST API.', 'neora-core' );
    }

	public function is_core(): bool {
		return false;
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
	/**
	 * Resolve shared services from the DI container.
	 */
	public function register( Container $container ): void {
		if ( null === $this->otp_manager && $container->has( 'neora.otp' ) ) {
			/** @var OtpManager $otp */
			$otp               = $container->get( 'neora.otp' );
			$this->otp_manager = $otp;
		}
	}

	/**
	 * Attach WordPress hooks.
	 */
	public function boot( HookRegistry $hooks ): void {
		$hooks->action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Register OTP authentication REST endpoints.
	 */
	public function register_rest_routes(): void {
		$mobile_arg = [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( mixed $param, mixed $request = null ): bool {
				if ( ! is_scalar( $param ) ) {
					return false;
				}
				$country = ( $request instanceof WP_REST_Request )
					? self::sanitize_country_param( $request->get_param( 'country' ) )
					: null;
				return null !== PhoneNumber::normalize_e164( (string) $param, $country );
			},
		];

		$country_arg = [
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => static fn( mixed $param ): string => self::sanitize_country_param( $param ) ?? '',
			'validate_callback' => static fn( mixed $param ): bool =>
				'' === trim( (string) $param ) || 1 === preg_match( '/^[A-Za-z]{2}$/D', trim( (string) $param ) ),
		];

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/send-otp',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_request_otp' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'mobile'  => $mobile_arg,
					'country' => $country_arg,
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/verify-otp',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_verify_otp' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'mobile'  => $mobile_arg,
					'country' => $country_arg,
					'code'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( mixed $param ): bool =>
							is_scalar( $param ) && 1 === preg_match(
								'/^[0-9]{5}$/D',
								OtpManager::normalize_digits( (string) $param )
							),
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/countries',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_get_countries' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Return supported countries and international calling codes for auth UI.
	 */
	public function handle_get_countries(): WP_REST_Response {
		$currency_service = new CurrencyService();
		$names            = $currency_service->get_supported_countries();
		$calling_codes    = PhoneNumber::get_country_calling_codes();
		$countries        = [];

		foreach ( $calling_codes as $iso => $dial ) {
			$countries[] = [
				'code'      => $iso,
				'name'      => (string) ( $names[ $iso ] ?? $iso ),
				'dial_code' => '+' . $dial,
			];
		}

		return new WP_REST_Response(
			[
				'default_country' => PhoneNumber::get_default_country(),
				'countries'       => $countries,
			],
			200
		);
	}

	/**
	 * Handle OTP generation and SMS dispatch.
	 */
	public function handle_request_otp( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw_mobile = (string) $request->get_param( 'mobile' );
		$country    = self::sanitize_country_param( $request->get_param( 'country' ) );
		$mobile     = PhoneNumber::normalize_e164( $raw_mobile, $country );

		if ( null === $mobile ) {
			return new WP_Error(
				'neora_otp_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' ),
				[ 'status' => 400 ]
			);
		}

		$ip_check = $this->check_ip_rate_limit( 'send', self::IP_RATE_LIMIT_MAX );
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		$this->increment_ip_rate_limit( 'send' );

		$result = $this->get_otp_manager()->request_otp( $mobile, 'login', $country );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$data = $result->get_error_data();
			$meta = is_array( $data ) ? $data : [];

			if ( in_array( $code, [ 'neora_otp_cooldown', 'neora_otp_invalid_mobile', 'neora_sms_invalid_mobile' ], true ) ) {
				$meta['status'] = 'neora_otp_cooldown' === $code ? 429 : 400;
				return new WP_Error( $code, $result->get_error_message(), $meta );
			}

			do_action( 'neora_auth_otp_send_failed', $mobile, $result );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[Neora Core Auth] OTP delivery failed for %s (%s): %s',
						$mobile,
						$code,
						$result->get_error_message()
					)
				);
			}

			$status = in_array( $code, [ 'neora_sms_http_error', 'neora_sms_gateway_http_status', 'neora_sms_gateway_rejected' ], true )
				? 502
				: 500;

			return new WP_Error(
				'neora_otp_delivery_failed',
				__( 'Unable to send verification code at the moment. Please try again later.', 'neora-core' ),
				[ 'status' => $status ]
			);
		}

		return new WP_REST_Response(
			[
				'success'     => true,
				'message'     => __( 'Verification code sent successfully.', 'neora-core' ),
				'mobile_e164' => $mobile,
			],
			200
		);
	}

	/**
	 * Verify OTP and authenticate (or register) the WordPress user.
	 */
	public function handle_verify_otp( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw_mobile = (string) $request->get_param( 'mobile' );
		$raw_code   = (string) $request->get_param( 'code' );
		$country    = self::sanitize_country_param( $request->get_param( 'country' ) );
		$mobile     = PhoneNumber::normalize_e164( $raw_mobile, $country );

		if ( null === $mobile ) {
			return new WP_Error(
				'neora_otp_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' ),
				[ 'status' => 400 ]
			);
		}

		$ip_check = $this->check_ip_rate_limit( 'verify', self::VERIFY_IP_RATE_LIMIT_MAX );
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		$verify_result = $this->get_otp_manager()->verify_otp( $mobile, $raw_code, 'login', $country );

		if ( is_wp_error( $verify_result ) ) {
			$this->increment_ip_rate_limit( 'verify' );

			$code   = $verify_result->get_error_code();
			$status = 'neora_otp_max_attempts' === $code ? 429 : 400;

			return new WP_Error(
				$code,
				$verify_result->get_error_message(),
				[ 'status' => $status ]
			);
		}

		$user_id     = $this->find_user_id_by_mobile( $mobile );
		$is_new_user = false;

		if ( null === $user_id ) {
			$created = $this->create_user_for_mobile( $mobile );
			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$user_id     = $created;
			$is_new_user = true;
		} elseif ( ! self::is_user_allowed_otp_login( $user_id ) ) {
			return new WP_Error(
				'neora_auth_role_not_allowed',
				__( 'Passwordless OTP login is not permitted for this account role.', 'neora-core' ),
				[ 'status' => 403 ]
			);
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		if ( is_object( $user ) && isset( $user->user_login ) ) {
			do_action( 'wp_login', (string) $user->user_login, $user );
		}

		do_action( 'neora_auth_user_logged_in', $user_id, $mobile, $is_new_user );

		$redirect_url = apply_filters( 'neora_auth_login_redirect', home_url( '/' ), $user_id, $request );
		$rest_nonce   = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';

		return new WP_REST_Response(
			[
				'success'      => true,
				'message'      => __( 'Logged in successfully.', 'neora-core' ),
				'user_id'      => $user_id,
				'mobile_e164'  => $mobile,
				'is_new_user'  => $is_new_user,
				'redirect_url' => $redirect_url,
				'rest_nonce'   => $rest_nonce,
			],
			200
		);
	}

	/**
	 * Lazy fallback ensures standalone instantiation works in isolated tests.
	 */
	private function get_otp_manager(): OtpManager {
		if ( null === $this->otp_manager ) {
			$this->otp_manager = new OtpManager( new SmsManager() );
		}

		return $this->otp_manager;
	}

	/**
	 * Locate an existing user by E.164 mobile meta (with legacy 09xx migration), login, or external filter.
	 */
	private function find_user_id_by_mobile( string $mobile ): ?int {
		$candidates = [ $mobile ];
		$iran_local = PhoneNumber::to_iran_local( $mobile );
		if ( null !== $iran_local ) {
			$candidates[] = $iran_local;
		}

		$query_args = 1 === count( $candidates )
			? [
				'meta_key'   => self::META_MOBILE,
				'meta_value' => $mobile,
				'number'     => 1,
				'fields'     => 'ID',
			]
			: [
				'meta_query' => [
					[
						'key'     => self::META_MOBILE,
						'value'   => $candidates,
						'compare' => 'IN',
					],
				],
				'number'     => 1,
				'fields'     => 'ID',
			];

		$users = get_users( $query_args );

		if ( is_array( $users ) && ! empty( $users ) ) {
			$first   = $users[0];
			$user_id = is_object( $first ) ? (int) $first->ID : (int) $first;
			if ( $user_id > 0 && count( $candidates ) > 1 && function_exists( 'get_user_meta' ) ) {
				$stored = (string) get_user_meta( $user_id, self::META_MOBILE, true );
				if ( $stored !== $mobile ) {
					update_user_meta( $user_id, self::META_MOBILE, $mobile );
				}
			}
			return $user_id;
		}

		foreach ( $candidates as $login_candidate ) {
			$legacy_user = get_user_by( 'login', $login_candidate );
			if ( is_object( $legacy_user ) && isset( $legacy_user->ID ) ) {
				update_user_meta( (int) $legacy_user->ID, self::META_MOBILE, $mobile );
				return (int) $legacy_user->ID;
			}
		}

		$matched_user_id = apply_filters( 'neora_auth_find_user_id', null, $mobile );
		if ( is_numeric( $matched_user_id ) && (int) $matched_user_id > 0 ) {
			$user_id = (int) $matched_user_id;
			update_user_meta( $user_id, self::META_MOBILE, $mobile );
			return $user_id;
		}

		return null;
	}

	private static function sanitize_country_param( mixed $raw ): ?string {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$country = strtoupper( trim( (string) $raw ) );
		return 1 === preg_match( '/^[A-Z]{2}$/D', $country ) ? $country : null;
	}
	/**
	 * Provision a new WordPress subscriber without exposing the mobile in user_login.
	 */
	private function create_user_for_mobile( string $mobile ): int|WP_Error {
		$opaque_login = 'neora_' . bin2hex( random_bytes( 6 ) );

		$user_id = wp_insert_user(
			[
				'user_login'    => $opaque_login,
				'user_nicename' => $opaque_login,
				'user_pass'     => wp_generate_password( 24, true ),
				'role'          => 'subscriber',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( (int) $user_id, self::META_MOBILE, $mobile );

		do_action( 'neora_auth_user_registered', (int) $user_id, $mobile );

		return (int) $user_id;
	}

	private function get_client_ip(): string {
		$raw = $_SERVER['REMOTE_ADDR'] ?? '';
		$ip  = is_string( $raw ) ? trim( $raw ) : '';

		if ( '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}

		$filtered = apply_filters( 'neora_auth_client_ip', $ip );
		if ( is_string( $filtered ) ) {
			$filtered = trim( $filtered );
			if ( '' === $filtered || false !== filter_var( $filtered, FILTER_VALIDATE_IP ) ) {
				return $filtered;
			}
		}

		return $ip;
	}

	/**
	 * Role-aware security policy: privileged accounts must not bypass password/2FA via SMS OTP.
	 */
	public static function is_user_allowed_otp_login( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;

		$allowed_roles = [ 'subscriber', 'customer' ];
		if ( function_exists( 'apply_filters' ) ) {
			$filtered_roles = apply_filters( 'neora_auth_allowed_roles', $allowed_roles );
			if ( is_array( $filtered_roles ) ) {
				$allowed_roles = $filtered_roles;
			}
		}

		$allowed = true;

		if ( is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) ) {
			$allowed = ! empty( array_intersect( $user->roles, $allowed_roles ) );
		}

		if (
			$allowed
			&& function_exists( 'user_can' )
			&& (
				user_can( $user_id, 'manage_options' )
				|| user_can( $user_id, 'edit_others_posts' )
				|| user_can( $user_id, 'manage_woocommerce' )
			)
		) {
			$allowed = false;
		}

		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'neora_auth_can_user_login_with_otp', $allowed, $user_id, $user );
		}

		return $allowed;
	}

	private function get_ip_rate_limit_key( string $ip, string $scope ): string {
		return 'send' === $scope
			? 'neora_otp_ip_' . md5( $ip )
			: 'neora_otp_ip_' . $scope . '_' . md5( $ip );
	}

	private function check_ip_rate_limit( string $scope = 'send', int $default_max = self::IP_RATE_LIMIT_MAX ): true|WP_Error {
		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			return true;
		}

		$filter_name  = 'send' === $scope ? 'neora_auth_ip_rate_limit_max' : 'neora_auth_verify_ip_rate_limit_max';
		$max_requests = (int) apply_filters( $filter_name, $default_max );
		if ( $max_requests <= 0 ) {
			return true;
		}

		$key    = $this->get_ip_rate_limit_key( $ip, $scope );
		$record = get_transient( $key );
		$now    = time();

		if (
			is_array( $record )
			&& isset( $record['count'], $record['window_start'] )
			&& ( $now - (int) $record['window_start'] ) < self::IP_RATE_LIMIT_WINDOW
			&& (int) $record['count'] >= $max_requests
		) {
			$retry_after = self::IP_RATE_LIMIT_WINDOW - ( $now - (int) $record['window_start'] );

			return new WP_Error(
				'neora_auth_rate_limited',
				__( 'Too many requests from your IP address. Please try again later.', 'neora-core' ),
				[
					'status'      => 429,
					'retry_after' => max( 1, $retry_after ),
				]
			);
		}

		return true;
	}

	private function increment_ip_rate_limit( string $scope = 'send' ): void {
		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			return;
		}

		$key    = $this->get_ip_rate_limit_key( $ip, $scope );
		$record = get_transient( $key );
		$now    = time();

		if (
			! is_array( $record )
			|| ! isset( $record['count'], $record['window_start'] )
			|| ( $now - (int) $record['window_start'] ) >= self::IP_RATE_LIMIT_WINDOW
		) {
			set_transient(
				$key,
				[
					'count'        => 1,
					'window_start' => $now,
				],
				self::IP_RATE_LIMIT_WINDOW
			);
			return;
		}

		$elapsed         = $now - (int) $record['window_start'];
		$remaining_ttl   = max( 1, self::IP_RATE_LIMIT_WINDOW - $elapsed );
		$record['count'] = (int) $record['count'] + 1;

		set_transient( $key, $record, $remaining_ttl );
	}
}