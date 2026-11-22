<?php
/**
 * Neora Core — Authenticated Encryption Helper for Stored Secrets.
 *
 * @package NeoraStudio\NeoraCore\Support
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Support;

final class SecretBox {

	private const PREFIX = 'neora_enc_v1:';
	private const CIPHER = 'aes-256-cbc';

	private static ?string $last_error = null;

	private function __construct() {}

	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'wp_salt' );
	}

	public static function get_last_error(): ?string {
		return self::$last_error;
	}

	public static function clear_last_error(): void {
		self::$last_error = null;
	}

	/**
	 * Encrypt a plaintext secret using WordPress auth salt.
	 * Never returns raw plaintext on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		self::$last_error = null;

		if ( '' === $plaintext ) {
			return '';
		}

		if ( str_starts_with( $plaintext, self::PREFIX ) ) {
			return $plaintext;
		}

		if ( ! self::is_available() ) {
			self::$last_error = 'openssl_unavailable';
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_length || $iv_length <= 0 ) {
			self::$last_error = 'invalid_iv_length';
			return '';
		}

		$key        = self::derive_key();
		$iv         = random_bytes( $iv_length );
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			self::$last_error = 'encrypt_failed';
			return '';
		}

		$hmac = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );

		return self::PREFIX . base64_encode( $iv . $hmac . $ciphertext );
	}

	/**
	 * Decrypt a stored secret. Plaintext legacy values are returned unchanged.
	 */
	public static function decrypt( string $stored ): string {
		self::$last_error = null;

		if ( '' === $stored || ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! self::is_available() ) {
			self::$last_error = 'openssl_unavailable';
			return '';
		}

		$encoded = substr( $stored, strlen( self::PREFIX ) );
		$decoded = base64_decode( $encoded, true );

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $decoded || false === $iv_length || strlen( $decoded ) <= ( $iv_length + 32 ) ) {
			self::$last_error = 'decrypt_malformed';
			return '';
		}

		$key        = self::derive_key();
		$iv         = substr( $decoded, 0, $iv_length );
		$hmac       = substr( $decoded, $iv_length, 32 );
		$ciphertext = substr( $decoded, $iv_length + 32 );

		$expected_hmac = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );
		if ( ! hash_equals( $expected_hmac, $hmac ) ) {
			self::$last_error = 'decrypt_hmac_mismatch';
			return '';
		}

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			self::$last_error = 'decrypt_failed';
			return '';
		}

		return $plaintext;
	}

	private static function derive_key(): string {
		return hash( 'sha256', 'neora_secret_box|' . wp_salt( 'auth' ), true );
	}
}