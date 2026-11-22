<?php
/**
 * Twilio International SMS Provider.
 *
 * @package NeoraStudio\NeoraCore\Services\Sms\Providers
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms\Providers;

use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use NeoraStudio\NeoraCore\Services\Sms\SmsProviderInterface;
use WP_Error;

final class TwilioProvider implements SmsProviderInterface {

	private const API_BASE = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

	public function get_key(): string {
		return 'twilio';
	}

	public function get_name(): string {
		return __( 'Twilio (International)', 'neora-core' );
	}

	public function supports_international(): bool {
		return true;
	}

	public function supports_pattern(): bool {
		return false;
	}

	public function is_configured( array $settings ): bool {
		$sid   = trim( (string) ( $settings['twilio_account_sid'] ?? '' ) );
		$token = trim( (string) ( $settings['twilio_auth_token'] ?? '' ) );
		$from  = trim( (string) ( $settings['twilio_from'] ?? '' ) );

		return '' !== $sid && '' !== $token && '' !== $from;
	}

	public function send_pattern(
		string $recipient_e164,
		array $args,
		?string $pattern_id = null,
		array $settings = [],
		array $context = []
	): bool|WP_Error {
		if ( empty( $args ) ) {
			return new WP_Error(
				'neora_sms_empty_args',
				__( 'Pattern arguments cannot be empty.', 'neora-core' )
			);
		}

		$message = $this->format_pattern_message( $args, $pattern_id, $settings, $context );

		return $this->send_text( $recipient_e164, $message, null, $settings, $context );
	}

	public function send_text(
		string $recipient_e164,
		string $message,
		?string $from = null,
		array $settings = [],
		array $context = []
	): bool|WP_Error {
		if ( ! PhoneNumber::is_valid_e164( $recipient_e164 ) ) {
			return new WP_Error(
				'neora_sms_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		$body_text = trim( $message );
		if ( '' === $body_text ) {
			return new WP_Error(
				'neora_sms_empty_message',
				__( 'SMS message cannot be empty.', 'neora-core' )
			);
		}

		$sid    = trim( (string) ( $settings['twilio_account_sid'] ?? '' ) );
		$token  = trim( (string) ( $settings['twilio_auth_token'] ?? '' ) );
		$sender = null !== $from && '' !== trim( $from )
			? trim( $from )
			: trim( (string) ( $settings['twilio_from'] ?? '' ) );

		if ( '' === $sid || '' === $token || '' === $sender ) {
			return new WP_Error(
				'neora_sms_missing_config',
				__( 'Twilio SMS credentials or sender number are not configured.', 'neora-core' )
			);
		}

		$payload = [
			'To'   => $recipient_e164,
			'Body' => $body_text,
		];

		if ( str_starts_with( $sender, 'MG' ) && 34 === strlen( $sender ) ) {
			$payload['MessagingServiceSid'] = $sender;
		} else {
			$payload['From'] = $sender;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth header for Twilio REST API.
		$auth_header = 'Basic ' . base64_encode( $sid . ':' . $token );

		$response = wp_remote_post(
			sprintf( self::API_BASE, rawurlencode( $sid ) ),
			[
				'timeout' => 12,
				'headers' => [
					'Authorization' => $auth_header,
					'Accept'        => 'application/json',
				],
				'body'    => $payload,
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

		if ( $status_code >= 200 && $status_code < 300 && is_array( $decoded ) && ! empty( $decoded['sid'] ) ) {
			return true;
		}

		$error_message = is_array( $decoded ) && ! empty( $decoded['message'] )
			? (string) $decoded['message']
			: __( 'Unexpected response from the Twilio SMS gateway.', 'neora-core' );

		return new WP_Error(
			'neora_sms_gateway_rejected',
			$error_message,
			[
				'status_code'  => $status_code,
				'gateway_code' => is_array( $decoded ) ? (string) ( $decoded['code'] ?? '' ) : '',
			]
		);
	}

	/**
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $context
	 */
	private function format_pattern_message( array $args, ?string $pattern_id, array $settings, array $context ): string {
		$values       = array_values( array_map( 'strval', $args ) );
		$template     = trim( (string) ( $settings['twilio_otp_template'] ?? '' ) );
		$replacements = [
			'{code}' => $values[0] ?? '',
		];

		foreach ( $values as $index => $val ) {
			$replacements[ '{' . $index . '}' ] = $val;
		}

		if ( '' !== $template ) {
			$formatted = strtr( $template, $replacements );
		} else {
			$formatted = sprintf(
				/* translators: %s: One-time verification code. */
				__( 'Your verification code is: %s', 'neora-core' ),
				$values[0] ?? ''
			);
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_sms_twilio_pattern_message', $formatted, $values, $pattern_id, $context );
			if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
				return $filtered;
			}
		}

		return $formatted;
	}
}