<?php
/**
 * Melipayamak SMS Gateway Client & Sandbox Logger.
 *
 * @package NeoraStudio\NeoraCore
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms;

use NeoraStudio\NeoraCore\Admin\SettingsPage;
use WP_Error;

final class MelipayamakClient {

	private const API_PATTERN_ENDPOINT  = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
	private const API_STANDARD_ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';

	/**
	 * Send an OTP or pattern-based SMS message.
	 *
	 * @param string             $recipient Mobile number (e.g., 09123456789).
	 * @param array<int, string> $args      Variables to inject into the pattern.
	 * @param string|null        $body_id   Optional pattern code override.
	 * @return true|WP_Error
	 */
	public function send_pattern( string $recipient, array $args, ?string $body_id = null ): bool|WP_Error {
		$mobile = self::normalize_mobile( $recipient );
		if ( null === $mobile ) {
			return new WP_Error(
				'neora_sms_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		if ( empty( $args ) ) {
			return new WP_Error(
				'neora_sms_empty_args',
				__( 'Pattern arguments cannot be empty.', 'neora-core' )
			);
		}

		$settings     = SettingsPage::get_settings();
		$sandbox_mode = ! empty( $settings['sandbox_mode'] );
		$pattern_code = null !== $body_id && '' !== trim( $body_id )
			? trim( $body_id )
			: (string) ( $settings['pattern_code'] ?? '' );

		if ( $sandbox_mode ) {
			$this->log_sandbox_message( $mobile, $args, $pattern_code );
			return true;
		}

		$username = (string) ( $settings['username'] ?? '' );
		$password = (string) ( $settings['password'] ?? '' );

		if ( '' === $username || '' === $password || '' === $pattern_code ) {
			return new WP_Error(
				'neora_sms_missing_config',
				__( 'SMS gateway credentials or pattern code are not configured.', 'neora-core' )
			);
		}

		return $this->dispatch_request(
			self::API_PATTERN_ENDPOINT,
			[
				'username' => $username,
				'password' => $password,
				'text'     => implode( ';', array_map( 'strval', $args ) ),
				'to'       => $mobile,
				'bodyId'   => (int) $pattern_code,
			]
		);
	}

	/**
	 * Send a standard text SMS using the configured sender_number.
	 *
	 * @return true|WP_Error
	 */
	public function send_sms( string $recipient, string $message, ?string $from = null ): bool|WP_Error {
		$mobile = self::normalize_mobile( $recipient );
		if ( null === $mobile ) {
			return new WP_Error(
				'neora_sms_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		$message = trim( $message );
		if ( '' === $message ) {
			return new WP_Error(
				'neora_sms_empty_message',
				__( 'SMS message cannot be empty.', 'neora-core' )
			);
		}

		$settings     = SettingsPage::get_settings();
		$sandbox_mode = ! empty( $settings['sandbox_mode'] );
		$sender       = null !== $from && '' !== trim( $from )
			? trim( $from )
			: (string) ( $settings['sender_number'] ?? '' );

		if ( $sandbox_mode ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[Neora Core SMS Sandbox] To: %s | From: %s | Message: %s',
					$mobile,
					'' !== $sender ? $sender : 'N/A',
					$message
				)
			);
			return true;
		}

		$username = (string) ( $settings['username'] ?? '' );
		$password = (string) ( $settings['password'] ?? '' );

		if ( '' === $username || '' === $password || '' === $sender ) {
			return new WP_Error(
				'neora_sms_missing_config',
				__( 'SMS gateway credentials or sender number are not configured.', 'neora-core' )
			);
		}

		return $this->dispatch_request(
			self::API_STANDARD_ENDPOINT,
			[
				'username' => $username,
				'password' => $password,
				'to'       => $mobile,
				'from'     => $sender,
				'text'     => $message,
				'isFlash'  => false,
			]
		);
	}

	/**
	 * Normalize Iranian mobile number (converts Persian/Arabic digits and +98/0098).
	 */
	public static function normalize_mobile( string $raw ): ?string {
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return null;
		}

		$latin = strtr(
			$trimmed,
			[
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			]
		);

		if ( str_starts_with( $latin, '+98' ) ) {
			$latin = '0' . substr( $latin, 3 );
		} elseif ( str_starts_with( $latin, '0098' ) ) {
			$latin = '0' . substr( $latin, 4 );
		}

		if ( 1 !== preg_match( '/^09[0-9]{9}$/D', $latin ) ) {
			return null;
		}

		return $latin;
	}

	/**
	 * @param array<string, mixed> $payload JSON body payload.
	 * @return true|WP_Error
	 */
	private function dispatch_request( string $endpoint, array $payload ): bool|WP_Error {
		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 10,
				'headers' => [
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				],
				'body'    => (string) wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'neora_sms_http_error',
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( 200 !== $status_code || ! is_array( $decoded ) ) {
			return new WP_Error(
				'neora_sms_gateway_http_status',
				__( 'Unexpected response from the SMS gateway.', 'neora-core' )
			);
		}

		// Melipayamak returns a positive numeric RecId (> 10 digits) on success;
		// negative or short integers indicate gateway rejection codes.
		$ret_status = (int) ( $decoded['RetStatus'] ?? 0 );
		$value      = trim( (string) ( $decoded['Value'] ?? '' ) );

		if ( 1 === $ret_status && ctype_digit( $value ) && strlen( $value ) > 10 ) {
			return true;
		}

		return new WP_Error(
			'neora_sms_gateway_rejected',
			self::describe_error_code( $value ),
			[
				'gateway_code' => $value,
				'ret_status'   => $ret_status,
			]
		);
	}

	/**
	 * Map official Melipayamak REST error codes to clear diagnostic messages.
	 */
	public static function describe_error_code( string $code ): string {
		$normalized = trim( $code );

		$known = [
			'-110' => __( 'Melipayamak requires an API Key instead of your panel password.', 'neora-core' ),
			'-109' => __( 'Server IP address is not allowlisted in Melipayamak panel settings.', 'neora-core' ),
			'-108' => __( 'Server IP temporarily blocked by Melipayamak due to failed attempts.', 'neora-core' ),
			'-5'   => __( 'Pattern variables do not match the approved Melipayamak pattern.', 'neora-core' ),
			'-4'   => __( 'Pattern code (BodyId) is invalid or not yet approved in Melipayamak.', 'neora-core' ),
			'-1'   => __( 'Melipayamak web service pattern access is disabled for this account.', 'neora-core' ),
			'0'    => __( 'Invalid Melipayamak username, password, or API key.', 'neora-core' ),
			'2'    => __( 'Insufficient SMS credit in your Melipayamak account.', 'neora-core' ),
			'12'   => __( 'Melipayamak user account verification documents are incomplete.', 'neora-core' ),
			'19'   => __( 'Hourly SMS sending limit reached in Melipayamak.', 'neora-core' ),
			'35'   => __( 'The recipient mobile number is in the telecom blacklist (Code 35).', 'neora-core' ),
		];

		if ( isset( $known[ $normalized ] ) ) {
			return $known[ $normalized ];
		}

		return sprintf(
			/* translators: %s: Gateway error code or status string. */
			__( 'SMS gateway rejected the request (Code: %s).', 'neora-core' ),
			'' !== $normalized ? $normalized : 'unknown'
		);
	}

	/**
	 * @param array<int, string> $args
	 */
	private function log_sandbox_message( string $mobile, array $args, string $pattern_code ): void {
		$joined_args = implode( ' | ', array_map( 'strval', $args ) );
		$pattern_str = '' !== $pattern_code ? $pattern_code : 'N/A';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[Neora Core SMS Sandbox] To: %s | Pattern: %s | Args: %s',
				$mobile,
				$pattern_str,
				$joined_args
			)
		);
	}
}