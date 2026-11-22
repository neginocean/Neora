<?php
/**
 * Multi-Provider SMS Router and Dispatcher.
 *
 * @package NeoraStudio\NeoraCore\Services\Sms
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms;

use NeoraStudio\NeoraCore\Admin\SettingsPage;
use NeoraStudio\NeoraCore\Services\Sms\Providers\MelipayamakProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\TwilioProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\WebhookProvider;
use WP_Error;

final class SmsManager {

	/** @var array<string, SmsProviderInterface> */
	private array $providers = [];

	private bool $filters_applied = false;

	/**
	 * @param array<int, SmsProviderInterface> $providers Optional custom provider list.
	 */
	public function __construct( array $providers = [] ) {
		if ( empty( $providers ) ) {
			$providers = [
				new MelipayamakProvider(),
				new TwilioProvider(),
				new WebhookProvider(),
			];
		}

		foreach ( $providers as $provider ) {
			if ( $provider instanceof SmsProviderInterface ) {
				$this->register_provider( $provider );
			}
		}
	}

	public function register_provider( SmsProviderInterface $provider ): void {
		$key = sanitize_key( $provider->get_key() );
		if ( '' !== $key ) {
			$this->providers[ $key ] = $provider;
		}
	}

	/**
	 * @return array<string, SmsProviderInterface>
	 */
	public function get_providers(): array {
		if ( ! $this->filters_applied ) {
			$this->filters_applied = true;
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'neora_core_sms_providers', $this->providers );
				if ( is_array( $filtered ) ) {
					$valid = [];
					foreach ( $filtered as $item ) {
						if ( $item instanceof SmsProviderInterface ) {
							$k = sanitize_key( $item->get_key() );
							if ( '' !== $k ) {
								$valid[ $k ] = $item;
							}
						}
					}
					if ( ! empty( $valid ) ) {
						$this->providers = $valid;
					}
				}
			}
		}

		return $this->providers;
	}

	public function get_provider( string $key ): ?SmsProviderInterface {
		$providers = $this->get_providers();
		$clean     = sanitize_key( $key );

		return $providers[ $clean ] ?? null;
	}

	/**
	 * Return provider labels suitable for admin select options.
	 *
	 * @return array<string, string>
	 */
	public function get_provider_options( bool $international_only = false ): array {
		$options = [];
		foreach ( $this->get_providers() as $key => $provider ) {
			if ( $international_only && ! $provider->supports_international() ) {
				continue;
			}
			$options[ $key ] = $provider->get_name();
		}

		return $options;
	}

	/**
	 * Resolve the appropriate provider for a normalized E.164 recipient.
	 *
	 * @param array<string, mixed>|null $settings Optional settings override.
	 */
	public function resolve_provider(
		string $recipient_e164,
		?string $preferred_provider = null,
		?array $settings = null
	): SmsProviderInterface|WP_Error {
		$effective    = $settings ?? SettingsPage::get_settings();
		$is_iranian   = PhoneNumber::is_iranian_e164( $recipient_e164 );
		$preferred    = null !== $preferred_provider ? sanitize_key( $preferred_provider ) : '';
		$primary_key  = sanitize_key( (string) ( $effective['default_provider'] ?? 'melipayamak' ) );
		$intl_key     = sanitize_key( (string) ( $effective['international_provider'] ?? '' ) );

		if ( '' !== $preferred ) {
			$provider = $this->get_provider( $preferred );
			if ( null === $provider ) {
				return new WP_Error(
					'neora_sms_unknown_provider',
					__( 'The requested SMS provider is not registered.', 'neora-core' )
				);
			}
			if ( ! $is_iranian && ! $provider->supports_international() ) {
				return new WP_Error(
					'neora_sms_international_unsupported',
					__( 'The selected SMS provider does not support international recipients.', 'neora-core' )
				);
			}

			return $provider;
		}

		$primary = $this->get_provider( '' !== $primary_key ? $primary_key : 'melipayamak' );

		if ( $is_iranian ) {
			if ( null !== $primary ) {
				return $primary;
			}
		} else {
			if ( null !== $primary && $primary->supports_international() ) {
				return $primary;
			}
			if ( '' !== $intl_key && 'none' !== $intl_key ) {
				$intl = $this->get_provider( $intl_key );
				if ( null !== $intl && $intl->supports_international() ) {
					return $intl;
				}
			}

			return new WP_Error(
				'neora_sms_international_unsupported',
				__( 'No international SMS provider is configured for non-Iranian recipients.', 'neora-core' )
			);
		}

		return new WP_Error(
			'neora_sms_unknown_provider',
			__( 'No valid SMS provider is available.', 'neora-core' )
		);
	}

	/**
	 * Send a pattern/OTP SMS immediately using the routed provider.
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $context
	 * @return true|WP_Error
	 */
	public function send_pattern(
		string $recipient,
		array $args,
		?string $pattern_id = null,
		?string $country = null,
		?string $provider_key = null,
		array $context = []
	): bool|WP_Error {
		$e164 = PhoneNumber::normalize_e164( $recipient, $country );
		if ( null === $e164 ) {
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

		$settings = SettingsPage::get_settings();
		if ( ! empty( $settings['sandbox_mode'] ) ) {
			$resolved_key = $this->resolve_sandbox_provider_label( $e164, $provider_key, $settings );
			$this->log_sandbox( $resolved_key, $e164, 'pattern', implode( ' | ', array_map( 'strval', $args ) ), $pattern_id );
			return true;
		}

		$provider = $this->resolve_provider( $e164, $provider_key, $settings );
		if ( $provider instanceof WP_Error ) {
			return $provider;
		}

		$result = $provider->send_pattern( $e164, $args, $pattern_id, $settings, $context );
		$this->fire_dispatched_action( $e164, 'pattern', $provider->get_key(), $result, $context );

		return $result;
	}

	/**
	 * Send a plain text SMS immediately using the routed provider.
	 *
	 * @param array<string, mixed> $context
	 * @return true|WP_Error
	 */
	public function send_text(
		string $recipient,
		string $message,
		?string $from = null,
		?string $country = null,
		?string $provider_key = null,
		array $context = []
	): bool|WP_Error {
		$e164 = PhoneNumber::normalize_e164( $recipient, $country );
		if ( null === $e164 ) {
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

		$settings = SettingsPage::get_settings();
		if ( ! empty( $settings['sandbox_mode'] ) ) {
			$resolved_key = $this->resolve_sandbox_provider_label( $e164, $provider_key, $settings );
			$this->log_sandbox( $resolved_key, $e164, 'text', $body_text, $from );
			return true;
		}

		$provider = $this->resolve_provider( $e164, $provider_key, $settings );
		if ( $provider instanceof WP_Error ) {
			return $provider;
		}

		$result = $provider->send_text( $e164, $body_text, $from, $settings, $context );
		$this->fire_dispatched_action( $e164, 'text', $provider->get_key(), $result, $context );

		return $result;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function resolve_sandbox_provider_label( string $e164, ?string $preferred, array $settings ): string {
		$provider = $this->resolve_provider( $e164, $preferred, $settings );
		return $provider instanceof WP_Error ? 'sandbox' : $provider->get_key();
	}

	private function log_sandbox( string $provider_key, string $e164, string $type, string $content, ?string $meta ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[Neora Core SMS Sandbox] Provider: %s | Type: %s | To: %s | Meta: %s | Payload: %s',
				$provider_key,
				$type,
				$e164,
				( null !== $meta && '' !== $meta ) ? $meta : 'N/A',
				$content
			)
		);
	}

	/**
	 * @param true|WP_Error        $result
	 * @param array<string, mixed> $context
	 */
	private function fire_dispatched_action( string $e164, string $type, string $provider_key, bool|WP_Error $result, array $context ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'neora_sms_dispatched', $e164, $type, $provider_key, $result, $context );
		}
	}
}