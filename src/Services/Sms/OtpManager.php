<?php
/**
 * OTP Generation, Rate-Limiting, and Verification Manager.
 *
 * @package NeoraStudio\NeoraCore
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms;

use WP_Error;

/**
 * Manages OTP lifecycle using WordPress transients with hashed storage and brute-force protection.
 */
final class OtpManager {

	/**
	 * Transient prefix for OTP records.
	 */
	private const TRANSIENT_PREFIX = 'neora_otp_';

	/**
	 * OTP validity duration in seconds (5 minutes).
	 */
	private const EXPIRATION_SECONDS = 300;

	/**
	 * Minimum wait time between consecutive SMS requests in seconds (2 minutes).
	 */
	private const COOLDOWN_SECONDS = 120;

	/**
	 * Maximum failed verification attempts before invalidating the OTP.
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * SMS dispatcher (SmsManager or legacy MelipayamakClient).
	 */
	private SmsManager|MelipayamakClient $sms_client;

	/** @var callable(): string */
	private $code_generator;

	public function __construct( SmsManager|MelipayamakClient $sms_client, ?callable $code_generator = null ) {
		$this->sms_client     = $sms_client;
		$this->code_generator = $code_generator ?? static fn(): string => (string) random_int( 10000, 99999 );
	}

	/**
	 * Generate, store, and send a 5-digit OTP to the given mobile number.
	 *
	 * @param string      $recipient Raw local or international mobile number.
	 * @param string      $purpose   OTP purpose scope (e.g. 'login', 'change_mobile').
	 * @param string|null $country   Optional ISO-2 default country code override.
	 * @return true|WP_Error True on success, WP_Error on validation/cooldown/gateway failure.
	 */
	public function request_otp( string $recipient, string $purpose = 'login', ?string $country = null ): bool|WP_Error {
		$mobile = PhoneNumber::normalize_e164( $recipient, $country );
		if ( null === $mobile ) {
			return new WP_Error(
				'neora_otp_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		$normalized_purpose = self::normalize_purpose( $purpose );
		$transient_key      = self::get_transient_key( $mobile, $normalized_purpose );
		$existing           = get_transient( $transient_key );
		$now                = time();

		// Enforce rate-limiting cooldown between requests.
		if ( is_array( $existing ) && isset( $existing['requested_at'] ) ) {
			$elapsed = $now - (int) $existing['requested_at'];
			if ( $elapsed < self::COOLDOWN_SECONDS ) {
				$remaining = self::COOLDOWN_SECONDS - $elapsed;
				return new WP_Error(
					'neora_otp_cooldown',
					sprintf(
						/* translators: %d: Remaining seconds before next OTP request. */
						__( 'Please wait %d seconds before requesting a new code.', 'neora-core' ),
						$remaining
					),
					[ 'retry_after' => $remaining ]
				);
			}
		}

		$otp_code = (string) ( $this->code_generator )();

		if ( $this->sms_client instanceof SmsManager ) {
			$send_result = $this->sms_client->send_pattern(
				$mobile,
				[ $otp_code ],
				null,
				$country,
				null,
				[
					'purpose' => $normalized_purpose,
					'is_otp'  => true,
				]
			);
		} else {
			$local       = PhoneNumber::to_iran_local( $mobile );
			$send_result = null !== $local
				? $this->sms_client->send_pattern( $local, [ $otp_code ] )
				: new WP_Error(
					'neora_sms_international_unsupported',
					__( 'The active SMS client only supports Iranian mobile numbers.', 'neora-core' )
				);
		}

		if ( is_wp_error( $send_result ) ) {
			return $send_result;
		}

		$record = [
			'code_hash'    => wp_hash( self::build_hash_payload( $mobile, $otp_code, $normalized_purpose ) ),
			'attempts'     => 0,
			'requested_at' => $now,
		];

		set_transient( $transient_key, $record, self::EXPIRATION_SECONDS );

		return true;
	}

	/**
	 * Verify a user-submitted OTP code against the stored transient.
	 *
	 * @param string      $recipient Raw local or international mobile number.
	 * @param string      $code      Raw OTP code (supports Persian/Arabic digits).
	 * @param string      $purpose   OTP purpose scope (must match request_otp).
	 * @param string|null $country   Optional ISO-2 default country code override.
	 * @return true|WP_Error True if valid, WP_Error if expired, invalid, or locked out.
	 */
	public function verify_otp( string $recipient, string $code, string $purpose = 'login', ?string $country = null ): bool|WP_Error {
		$mobile = PhoneNumber::normalize_e164( $recipient, $country );
		if ( null === $mobile ) {
			return new WP_Error(
				'neora_otp_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		$normalized_code = self::normalize_digits( $code );
		if ( 1 !== preg_match( '/^[0-9]{5}$/D', $normalized_code ) ) {
			return new WP_Error(
				'neora_otp_invalid_format',
				__( 'The verification code must be a 5-digit number.', 'neora-core' )
			);
		}

		$normalized_purpose = self::normalize_purpose( $purpose );
		$transient_key      = self::get_transient_key( $mobile, $normalized_purpose );
		$record             = get_transient( $transient_key );

		if ( ! is_array( $record ) || empty( $record['code_hash'] ) ) {
			return new WP_Error(
				'neora_otp_expired',
				__( 'Verification code has expired or does not exist. Please request a new code.', 'neora-core' )
			);
		}

		$attempts      = (int) ( $record['attempts'] ?? 0 );
		$code_hash     = (string) $record['code_hash'];
		$expected_hash = wp_hash( self::build_hash_payload( $mobile, $normalized_code, $normalized_purpose ) );

		if ( ! hash_equals( $code_hash, $expected_hash ) ) {
			++$attempts;

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				delete_transient( $transient_key );
				return new WP_Error(
					'neora_otp_max_attempts',
					__( 'Too many failed attempts. Please request a new verification code.', 'neora-core' )
				);
			}

			$record['attempts'] = $attempts;
			$remaining_ttl      = max( 1, self::EXPIRATION_SECONDS - ( time() - (int) ( $record['requested_at'] ?? time() ) ) );
			set_transient( $transient_key, $record, $remaining_ttl );

			return new WP_Error(
				'neora_otp_mismatch',
				__( 'The verification code entered is incorrect.', 'neora-core' )
			);
		}

		// One-time use: consume and delete the transient immediately upon success.
		delete_transient( $transient_key );

		return true;
	}

	/**
	 * Convert Persian/Arabic numerals in OTP input to Latin digits.
	 *
	 * @param string $raw Raw code input.
	 * @return string Trimmed string with Latin digits.
	 */
	public static function normalize_digits( string $raw ): string {
		return strtr(
			trim( $raw ),
			[
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			]
		);
	}

	/**
	 * Build deterministic purpose-scoped transient key for a normalized mobile number.
	 */
	private static function get_transient_key( string $normalized_mobile, string $purpose ): string {
		return self::TRANSIENT_PREFIX . md5( $purpose . '|' . $normalized_mobile );
	}

	private static function build_hash_payload( string $normalized_mobile, string $code, string $purpose ): string {
		return $purpose . '|' . $normalized_mobile . '|' . $code;
	}

	private static function normalize_purpose( string $purpose ): string {
		$clean = preg_replace( '/[^a-z0-9_-]/', '', strtolower( trim( $purpose ) ) ) ?? '';
		return '' !== $clean ? $clean : 'login';
	}
}
