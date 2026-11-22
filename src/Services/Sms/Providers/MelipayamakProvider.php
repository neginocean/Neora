<?php
/**
 * Melipayamak SMS Provider (Iranian Gateway Driver).
 *
 * @package NeoraStudio\NeoraCore\Services\Sms\Providers
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms\Providers;

use NeoraStudio\NeoraCore\Services\Sms\MelipayamakClient;
use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use NeoraStudio\NeoraCore\Services\Sms\SmsProviderInterface;
use WP_Error;

final class MelipayamakProvider implements SmsProviderInterface {

	private MelipayamakClient $client;

	public function __construct( ?MelipayamakClient $client = null ) {
		$this->client = $client ?? new MelipayamakClient();
	}

	public function get_key(): string {
		return 'melipayamak';
	}

	public function get_name(): string {
		return __( 'Melipayamak (Iran)', 'neora-core' );
	}

	public function supports_international(): bool {
		return false;
	}

	public function supports_pattern(): bool {
		return true;
	}

	public function is_configured( array $settings ): bool {
		$username = trim( (string) ( $settings['username'] ?? '' ) );
		$password = trim( (string) ( $settings['password'] ?? '' ) );

		return '' !== $username && '' !== $password;
	}

	public function send_pattern(
		string $recipient_e164,
		array $args,
		?string $pattern_id = null,
		array $settings = [],
		array $context = []
	): bool|WP_Error {
		$local = PhoneNumber::to_iran_local( $recipient_e164 );
		if ( null === $local ) {
			return new WP_Error(
				'neora_sms_international_unsupported',
				__( 'Melipayamak only supports Iranian (+98) mobile numbers.', 'neora-core' )
			);
		}

		return $this->client->send_pattern( $local, $args, $pattern_id );
	}

	public function send_text(
		string $recipient_e164,
		string $message,
		?string $from = null,
		array $settings = [],
		array $context = []
	): bool|WP_Error {
		$local = PhoneNumber::to_iran_local( $recipient_e164 );
		if ( null === $local ) {
			return new WP_Error(
				'neora_sms_international_unsupported',
				__( 'Melipayamak only supports Iranian (+98) mobile numbers.', 'neora-core' )
			);
		}

		return $this->client->send_sms( $local, $message, $from );
	}
}