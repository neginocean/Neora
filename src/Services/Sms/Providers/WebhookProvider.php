<?php
/**
 * Generic HTTP/JSON Webhook SMS Provider.
 *
 * @package NeoraStudio\NeoraCore\Services\Sms\Providers
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms\Providers;

use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use NeoraStudio\NeoraCore\Services\Sms\SmsProviderInterface;
use WP_Error;

final class WebhookProvider implements SmsProviderInterface {

	public function get_key(): string {
		return 'webhook';
	}

	public function get_name(): string {
		return __( 'Custom Webhook (HTTP JSON)', 'neora-core' );
	}

	public function supports_international(): bool {
		return true;
	}

	public function supports_pattern(): bool {
		return true;
	}

	public function is_configured( array $settings ): bool {
		return '' !== self::extract_valid_url( $settings['webhook_url'] ?? '' );
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

		$values  = array_values( array_map( 'strval', $args ) );
		$message = $this->format_otp_fallback( $values, $settings );

		return $this->post_payload(
			$recipient_e164,
			[
				'type'       => 'pattern',
				'to'         => $recipient_e164,
				'country'    => PhoneNumber::detect_country( $recipient_e164 ) ?? '',
				'pattern_id' => $pattern_id ?? (string) ( $settings['pattern_code'] ?? '' ),
				'args'       => $values,
				'message'    => $message,
				'context'    => $context,
			],
			$settings
		);
	}

	public function send_text(
		string $recipient_e164,
		string $message,
		?string $from = null,
		array $settings = [],
		array $context = []
	): bool|WP_Error {
		$body_text = trim( $message );
		if ( '' === $body_text ) {
			return new WP_Error(
				'neora_sms_empty_message',
				__( 'SMS message cannot be empty.', 'neora-core' )
			);
		}

		return $this->post_payload(
			$recipient_e164,
			[
				'type'    => 'text',
				'to'      => $recipient_e164,
				'country' => PhoneNumber::detect_country( $recipient_e164 ) ?? '',
				'from'    => $from ?? (string) ( $settings['sender_number'] ?? '' ),
				'message' => $body_text,
				'context' => $context,
			],
			$settings
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $settings
	 * @return true|WP_Error
	 */
	private function post_payload( string $recipient_e164, array $payload, array $settings ): bool|WP_Error {
		if ( ! PhoneNumber::is_valid_e164( $recipient_e164 ) ) {
			return new WP_Error(
				'neora_sms_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		$url = self::extract_valid_url( $settings['webhook_url'] ?? '' );
		if ( '' === $url ) {
			return new WP_Error(
				'neora_sms_missing_config',
				__( 'Webhook SMS endpoint URL is not configured or invalid.', 'neora-core' )
			);
		}

		$headers = [
			'Content-Type' => 'application/json; charset=utf-8',
			'Accept'       => 'application/json',
		];

		$secret = trim( (string) ( $settings['webhook_secret'] ?? '' ) );
		if ( '' !== $secret ) {
			$headers['Authorization'] = 'Bearer ' . $secret;
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 12,
				'headers' => $headers,
				'body'    => (string) wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'neora_sms_http_error', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			return true;
		}

		return new WP_Error(
			'neora_sms_gateway_rejected',
			sprintf(
				/* translators: %d: HTTP response status code. */
				__( 'Webhook SMS endpoint returned HTTP status %d.', 'neora-core' ),
				$status_code
			),
			[ 'status_code' => $status_code ]
		);
	}

	/**
	 * @param array<int, string>   $values
	 * @param array<string, mixed> $settings
	 */
	private function format_otp_fallback( array $values, array $settings ): string {
		$template = trim( (string) ( $settings['webhook_otp_template'] ?? '' ) );
		if ( '' === $template ) {
			return sprintf(
				/* translators: %s: One-time verification code. */
				__( 'Your verification code is: %s', 'neora-core' ),
				$values[0] ?? ''
			);
		}

		$replacements = [ '{code}' => $values[0] ?? '' ];
		foreach ( $values as $i => $val ) {
			$replacements[ '{' . $i . '}' ] = $val;
		}

		return strtr( $template, $replacements );
	}

	private static function extract_valid_url( mixed $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$url = trim( (string) $raw );
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, [ 'http', 'https' ], true ) ? $url : '';
	}
}