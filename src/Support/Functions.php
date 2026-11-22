<?php
/**
 * Neora Core — Public Theme & Template API Functions.
 *
 * Stable procedural helpers for themes and extensions to interact with
 * Neora Core modules without coupling to internal class paths or meta keys.
 *
 * @package NeoraStudio\NeoraCore\Support
 */

declare(strict_types=1);

use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Modules\Auth\AuthModule;
use NeoraStudio\NeoraCore\Modules\Multilingual\PolylangModule;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use NeoraStudio\NeoraCore\Modules\WooCommerce\WooCommerceBridgeModule;
use NeoraStudio\NeoraCore\Services\Branding\BrandingService;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use NeoraStudio\NeoraCore\Services\Sms\SmsQueue;

if ( ! function_exists( 'neora_is_module_active' ) ) {
	/**
	 * Check whether a Neora Core module is active and booted.
	 */
	function neora_is_module_active( string $module_key ): bool {
		$app     = Bootstrap::app();
		$manager = $app?->module_manager();

		return $manager ? $manager->is_active( $module_key ) : false;
	}
}

if ( ! function_exists( 'neora_is_woocommerce_active' ) ) {
	/**
	 * Check whether WooCommerce is installed and active.
	 */
	function neora_is_woocommerce_active(): bool {
		return WooCommerceBridgeModule::is_woocommerce_active();
	}
}

if ( ! function_exists( 'neora_is_polylang_active' ) ) {
	/**
	 * Check whether Polylang is installed and active.
	 */
	function neora_is_polylang_active(): bool {
		return PolylangModule::is_polylang_active();
	}
}

if ( ! function_exists( 'neora_current_lang' ) ) {
	/**
	 * Return the active Polylang language slug/locale or WordPress locale fallback.
	 */
	function neora_current_lang( string $field = 'slug' ): string {
		return PolylangModule::current_language( $field );
	}
}

if ( ! function_exists( 'neora_translate_string' ) ) {
	/**
	 * Translate a dynamic string via Polylang when active.
	 */
	function neora_translate_string( string $text ): string {
		return PolylangModule::translate_string( $text );
	}
}

if ( ! function_exists( 'neora_branding_service' ) ) {
	/**
	 * Resolve the shared BrandingService instance from the container when booted.
	 */
	function neora_branding_service(): BrandingService {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.branding' ) ) {
			/** @var BrandingService $service */
			$service = $app->container()->get( 'neora.branding' );
			return $service;
		}

		return new BrandingService();
	}
}

if ( ! function_exists( 'neora_get_branding_settings' ) ) {
	/**
	 * Return normalized white-label branding configuration.
	 *
	 * @return array<string, string|bool>
	 */
	function neora_get_branding_settings(): array {
		return neora_branding_service()->get_settings();
	}
}

if ( ! function_exists( 'neora_get_brand_name' ) ) {
	/**
	 * Return the configured white-label brand name, site title, or default label.
	 */
	function neora_get_brand_name(): string {
		$general = neora_get_branding_settings();
		$brand   = trim( (string) ( $general['brand_name'] ?? '' ) );

		if ( '' !== $brand ) {
			return $brand;
		}

		if ( function_exists( 'get_bloginfo' ) ) {
			$site_name = trim( (string) get_bloginfo( 'name' ) );
			if ( '' !== $site_name ) {
				return $site_name;
			}
		}

		return __( 'Neora Core', 'neora-core' );
	}
}

if ( ! function_exists( 'neora_get_brand_logo_url' ) ) {
	/**
	 * Return the configured white-label brand logo URL (empty string if unset).
	 */
	function neora_get_brand_logo_url(): string {
		$branding = neora_get_branding_settings();

		return (string) ( $branding['logo_url'] ?? '' );
	}
}

if ( ! function_exists( 'neora_get_brand_color' ) ) {
	/**
	 * Return the configured white-label primary brand color hex code.
	 */
	function neora_get_brand_color(): string {
		$branding = neora_get_branding_settings();

		return (string) ( $branding['brand_color'] ?? BrandingService::DEFAULT_COLOR );
	}
}

if ( ! function_exists( 'neora_sms_manager' ) ) {
	/**
	 * Resolve the shared SmsManager instance from the container when booted.
	 */
	function neora_sms_manager(): SmsManager {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.sms_manager' ) ) {
			/** @var SmsManager $manager */
			$manager = $app->container()->get( 'neora.sms_manager' );
			return $manager;
		}

		return new SmsManager();
	}
}

if ( ! function_exists( 'neora_sms_queue' ) ) {
	/**
	 * Resolve the shared SmsQueue instance from the container when booted.
	 */
	function neora_sms_queue(): SmsQueue {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.sms_queue' ) ) {
			/** @var SmsQueue $queue */
			$queue = $app->container()->get( 'neora.sms_queue' );
			return $queue;
		}

		return new SmsQueue( neora_sms_manager() );
	}
}

if ( ! function_exists( 'neora_normalize_phone_e164' ) ) {
	/**
	 * Normalize a local or international mobile number to E.164 format.
	 */
	function neora_normalize_phone_e164( string $phone, ?string $default_country = null ): ?string {
		return PhoneNumber::normalize_e164( $phone, $default_country );
	}
}

if ( ! function_exists( 'neora_send_sms' ) ) {
	/**
	 * Dispatch a plain text SMS immediately via the active SMS provider router.
	 *
	 * @param array{from?: string|null, country?: string|null, provider?: string|null, context?: array<string, mixed>} $args
	 * @return true|\WP_Error
	 */
	function neora_send_sms( string $recipient, string $message, array $args = [] ): bool|\WP_Error {
		return neora_sms_manager()->send_text(
			$recipient,
			$message,
			isset( $args['from'] ) ? (string) $args['from'] : null,
			isset( $args['country'] ) ? (string) $args['country'] : null,
			isset( $args['provider'] ) ? (string) $args['provider'] : null,
			isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : []
		);
	}
}

if ( ! function_exists( 'neora_queue_sms' ) ) {
	/**
	 * Enqueue a plain text SMS for asynchronous delivery with automatic retry.
	 *
	 * @param array{from?: string|null, country?: string|null, provider?: string|null, context?: array<string, mixed>} $args
	 */
	function neora_queue_sms( string $recipient, string $message, array $args = [] ): string|\WP_Error {
		return neora_sms_queue()->enqueue_text(
			$recipient,
			$message,
			isset( $args['from'] ) ? (string) $args['from'] : null,
			isset( $args['country'] ) ? (string) $args['country'] : null,
			isset( $args['provider'] ) ? (string) $args['provider'] : null,
			isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : []
		);
	}
}

if ( ! function_exists( 'neora_get_user_mobile' ) ) {
	/**
	 * Return a user's normalized mobile number (OTP meta first, billing_phone fallback).
	 *
	 * @param string $format Output format: 'e164' (default) or 'local' (09xx for Iran).
	 */
	function neora_get_user_mobile( ?int $user_id = null, string $format = 'e164' ): string {
		$uid = $user_id ?? ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		if ( $uid <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return '';
		}

		$resolved = null;
		$mobile   = (string) get_user_meta( $uid, AuthModule::META_MOBILE, true );
		if ( '' !== trim( $mobile ) ) {
			$resolved = PhoneNumber::normalize_e164( $mobile );
		}

		if ( null === $resolved ) {
			$billing_phone = (string) get_user_meta( $uid, 'billing_phone', true );
			$resolved      = PhoneNumber::normalize_e164( $billing_phone );
		}

		if ( null === $resolved ) {
			return '';
		}

		if ( 'local' === strtolower( trim( $format ) ) ) {
			return PhoneNumber::to_iran_local( $resolved ) ?? $resolved;
		}

		return $resolved;
	}
}

if ( ! function_exists( 'neora_get_auth_config' ) ) {
	/**
	 * Return OTP REST endpoints, nonce, and regional dialing config for theme login forms.
	 *
	 * @return array{
	 *     send_otp_url: string,
	 *     verify_otp_url: string,
	 *     countries_url: string,
	 *     rest_nonce: string,
	 *     default_country: string,
	 *     calling_codes: array<string, string>
	 * }
	 */
	function neora_get_auth_config(): array {
		$ns = AuthModule::REST_NAMESPACE;

		return [
			'send_otp_url'    => function_exists( 'rest_url' ) ? (string) rest_url( $ns . '/auth/send-otp' ) : '',
			'verify_otp_url'  => function_exists( 'rest_url' ) ? (string) rest_url( $ns . '/auth/verify-otp' ) : '',
			'countries_url'   => function_exists( 'rest_url' ) ? (string) rest_url( $ns . '/auth/countries' ) : '',
			'rest_nonce'      => function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( 'wp_rest' ) : '',
			'default_country' => PhoneNumber::get_default_country(),
			'calling_codes'   => PhoneNumber::get_country_calling_codes(),
		];
	}
}

if ( ! function_exists( 'neora_get_service_code' ) ) {
	/**
	 * Return the identifier code for a Service post.
	 */
	function neora_get_service_code( int $post_id = 0 ): string {
		$id = $post_id > 0 ? $post_id : ( function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0 );
		if ( $id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		return (string) get_post_meta( $id, '_neora_service_code', true );
	}
}

if ( ! function_exists( 'neora_currency_service' ) ) {
	/**
	 * Resolve the shared CurrencyService instance from the container when booted.
	 */
	function neora_currency_service(): CurrencyService {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.currency' ) ) {
			/** @var CurrencyService $service */
			$service = $app->container()->get( 'neora.currency' );
			return $service;
		}

		return new CurrencyService();
	}
}

if ( ! function_exists( 'neora_get_currencies' ) ) {
	/**
	 * Return the registered currency catalog.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	function neora_get_currencies(): array {
		return neora_currency_service()->get_currencies();
	}
}

if ( ! function_exists( 'neora_get_currency' ) ) {
	/**
	 * Return the active currency code (e.g. 'IRT', 'USD').
	 */
	function neora_get_currency(): string {
		return neora_currency_service()->get_active_currency_code();
	}
}

if ( ! function_exists( 'neora_get_currency_symbol' ) ) {
	/**
	 * Return the localized display symbol for the specified or active currency.
	 */
	function neora_get_currency_symbol( ?string $currency = null ): string {
		return neora_currency_service()->get_currency_symbol( $currency );
	}
}

if ( ! function_exists( 'neora_format_price' ) ) {
	/**
	 * Format a numeric amount using CurrencyService and regional preferences.
	 *
	 * @param array<string, mixed> $args Optional formatting overrides.
	 */
	function neora_format_price( string|int|float $amount, ?string $currency = null, array $args = [] ): string {
		return neora_currency_service()->format( $amount, $currency, $args );
	}
}

if ( ! function_exists( 'neora_get_service_price' ) ) {
	/**
	 * Return the raw normalized price string for a Service post.
	 */
	function neora_get_service_price( int $post_id = 0 ): string {
		$id = $post_id > 0 ? $post_id : ( function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0 );
		if ( $id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$raw        = get_post_meta( $id, '_neora_service_price', true );
		$normalized = ServicesModule::sanitize_price( $raw );

		return $normalized ?? '';
	}
}

if ( ! function_exists( 'neora_get_service_formatted_price' ) ) {
	/**
	 * Return the formatted price string for a Service post in the active currency.
	 *
	 * @param array<string, mixed> $args Optional formatting overrides.
	 */
	function neora_get_service_formatted_price( int $post_id = 0, array $args = [] ): string {
		$price = neora_get_service_price( $post_id );
		if ( '' === $price ) {
			return '';
		}

		return neora_format_price( $price, null, $args );
	}
}

if ( ! function_exists( 'neora_format_price_tomans' ) ) {
	/**
	 * Format a whole-toman numeric string with thousand separators without integer overflow.
	 */
	function neora_format_price_tomans( string|int $amount, bool $with_unit = true ): string {
		$normalized = CurrencyService::normalize_amount( $amount, 0, false );
		if ( null === $normalized || '' === $normalized ) {
			return '';
		}

		return neora_currency_service()->format(
			$normalized,
			'IRT',
			[ 'with_unit' => $with_unit ]
		);
	}
}

if ( ! function_exists( 'neora_get_portfolio_meta' ) ) {
	/**
	 * Return structured metadata for a Portfolio post.
	 *
	 * @return array{client_name: string, project_url: string, completion_year: string, short_description: string}
	 */
	function neora_get_portfolio_meta( int $post_id = 0 ): array {
		$id = $post_id > 0 ? $post_id : ( function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0 );
		if ( $id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return [
				'client_name'       => '',
				'project_url'       => '',
				'completion_year'   => '',
				'short_description' => '',
			];
		}

		return [
			'client_name'       => (string) get_post_meta( $id, '_neora_portfolio_client_name', true ),
			'project_url'       => (string) get_post_meta( $id, '_neora_portfolio_project_url', true ),
			'completion_year'   => (string) get_post_meta( $id, '_neora_portfolio_completion_year', true ),
			'short_description' => (string) get_post_meta( $id, '_neora_portfolio_short_description', true ),
		];
	}
}