<?php
/**
 * International E.164 Phone Number Parser, Validator, and Formatter.
 *
 * @package NeoraStudio\NeoraCore\Services\Sms
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms;

use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;

final class PhoneNumber {

	private const DIGIT_MAP = [
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	];

	private function __construct() {}

	/**
	 * Return country calling code and national validation rules.
	 *
	 * @return array<string, array{calling_code: string, trunk: string, national_pattern: string}>
	 */
	public static function get_country_rules(): array {
		$rules = [
			'IR' => [
				'calling_code'     => '98',
				'trunk'            => '0',
				'national_pattern' => '/^9[0-9]{9}$/D',
			],
			'AE' => [
				'calling_code'     => '971',
				'trunk'            => '0',
				'national_pattern' => '/^5[0-9]{8}$/D',
			],
			'TR' => [
				'calling_code'     => '90',
				'trunk'            => '0',
				'national_pattern' => '/^5[0-9]{9}$/D',
			],
			'DE' => [
				'calling_code'     => '49',
				'trunk'            => '0',
				'national_pattern' => '/^[1-9][0-9]{6,11}$/D',
			],
			'GB' => [
				'calling_code'     => '44',
				'trunk'            => '0',
				'national_pattern' => '/^7[0-9]{9}$/D',
			],
			'US' => [
				'calling_code'     => '1',
				'trunk'            => '',
				'national_pattern' => '/^[2-9][0-9]{9}$/D',
			],
			'CA' => [
				'calling_code'     => '1',
				'trunk'            => '',
				'national_pattern' => '/^[2-9][0-9]{9}$/D',
			],
		];

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_phone_country_rules', $rules );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				return $filtered;
			}
		}

		return $rules;
	}

	/**
	 * Return calling codes keyed by ISO-3166 alpha-2 country code.
	 *
	 * @return array<string, string>
	 */
	public static function get_country_calling_codes(): array {
		$codes = [];
		foreach ( self::get_country_rules() as $iso => $rule ) {
			if ( isset( $rule['calling_code'] ) && '' !== (string) $rule['calling_code'] ) {
				$codes[ strtoupper( (string) $iso ) ] = (string) $rule['calling_code'];
			}
		}

		return $codes;
	}

	public static function get_default_country(): string {
		$settings = CurrencyService::get_settings();
		$country  = strtoupper( trim( (string) ( $settings['default_country'] ?? CurrencyService::DEFAULT_COUNTRY ) ) );

		return 1 === preg_match( '/^[A-Z]{2}$/D', $country ) ? $country : CurrencyService::DEFAULT_COUNTRY;
	}

	/**
	 * Normalize a local or international phone number to strict E.164 (+[1-9]...).
	 */
	public static function normalize_e164( string $raw, ?string $default_country = null ): ?string {
		$ascii = strtr( trim( $raw ), self::DIGIT_MAP );
		$clean = preg_replace( '/[\s\-\.\(\)\x{00A0}\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '', $ascii );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return null;
		}

		if ( str_starts_with( $clean, '+' ) ) {
			return self::validate_international_digits( substr( $clean, 1 ) );
		}

		if ( str_starts_with( $clean, '00' ) ) {
			return self::validate_international_digits( substr( $clean, 2 ) );
		}

		if ( 1 !== preg_match( '/^[0-9]+$/D', $clean ) ) {
			return null;
		}

		$country = null !== $default_country && '' !== trim( $default_country )
			? strtoupper( trim( $default_country ) )
			: self::get_default_country();
		$rules   = self::get_country_rules();
		$rule    = $rules[ $country ] ?? null;

		if ( ! is_array( $rule ) ) {
			return null;
		}

		$cc = (string) $rule['calling_code'];
		if ( str_starts_with( $clean, $cc ) ) {
			$candidate = self::validate_national_for_rule( substr( $clean, strlen( $cc ) ), $rule );
			if ( null !== $candidate ) {
				return '+' . $cc . $candidate;
			}
		}

		$candidate = self::validate_national_for_rule( $clean, $rule );
		return null !== $candidate ? '+' . $cc . $candidate : null;
	}

	public static function is_valid_e164( string $value ): bool {
		return 1 === preg_match( '/^\+[1-9][0-9]{6,14}$/D', $value );
	}

	public static function is_iranian_e164( string $e164 ): bool {
		return 1 === preg_match( '/^\+989[0-9]{9}$/D', $e164 );
	}

	/**
	 * Convert an Iranian mobile number (local or E.164) to 09xxxxxxxxx format.
	 */
	public static function to_iran_local( string $raw ): ?string {
		$e164 = self::normalize_e164( $raw, 'IR' );
		if ( null === $e164 || ! self::is_iranian_e164( $e164 ) ) {
			return null;
		}

		return '0' . substr( $e164, 3 );
	}

	/**
	 * Detect ISO-2 country code from a normalized E.164 number when possible.
	 */
	public static function detect_country( string $e164 ): ?string {
		if ( ! self::is_valid_e164( $e164 ) ) {
			return null;
		}

		$digits = substr( $e164, 1 );
		$codes  = self::get_country_calling_codes();
		uasort( $codes, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		foreach ( $codes as $iso => $cc ) {
			if ( str_starts_with( $digits, $cc ) ) {
				return $iso;
			}
		}

		return null;
	}

	/**
	 * Mask a phone number for safe logs and non-sensitive UI output.
	 */
	public static function mask( string $phone ): string {
		$len = strlen( $phone );
		if ( $len <= 6 ) {
			return str_repeat( '*', $len );
		}

		return substr( $phone, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $phone, -4 );
	}

	private static function validate_international_digits( string $digits ): ?string {
		if ( 1 !== preg_match( '/^[1-9][0-9]{6,14}$/D', $digits ) ) {
			return null;
		}

		if ( str_starts_with( $digits, '98' ) ) {
			$national = substr( $digits, 2 );
			if ( str_starts_with( $national, '0' ) ) {
				$national = substr( $national, 1 );
			}
			return 1 === preg_match( '/^9[0-9]{9}$/D', $national ) ? '+98' . $national : null;
		}

		return '+' . $digits;
	}

	/**
	 * @param array{calling_code: string, trunk: string, national_pattern: string} $rule
	 */
	private static function validate_national_for_rule( string $raw_national, array $rule ): ?string {
		$national = $raw_national;
		$trunk    = (string) ( $rule['trunk'] ?? '' );

		if ( '' !== $trunk && str_starts_with( $national, $trunk ) ) {
			$national = substr( $national, strlen( $trunk ) );
		}

		return 1 === preg_match( (string) $rule['national_pattern'], $national ) ? $national : null;
	}
}