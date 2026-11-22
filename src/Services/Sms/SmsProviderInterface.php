<?php
/**
 * SMS Provider Contract.
 *
 * @package NeoraStudio\NeoraCore\Services\Sms
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms;

use WP_Error;

interface SmsProviderInterface {

	/**
	 * Unique provider identifier (e.g. 'melipayamak', 'twilio', 'webhook').
	 */
	public function get_key(): string;

	/**
	 * Human-readable provider display name.
	 */
	public function get_name(): string;

	/**
	 * Whether this provider supports non-Iranian international E.164 numbers.
	 */
	public function supports_international(): bool;

	/**
	 * Whether this provider natively supports pattern/template IDs.
	 */
	public function supports_pattern(): bool;

	/**
	 * Whether required provider credentials are present in settings.
	 *
	 * @param array<string, mixed> $settings Effective SMS settings.
	 */
	public function is_configured( array $settings ): bool;

	/**
	 * Send a pattern/OTP SMS to an E.164 recipient.
	 *
	 * @param string               $recipient_e164 Normalized E.164 number (e.g. '+989123456789').
	 * @param array<int, string>   $args           Pattern positional arguments.
	 * @param string|null          $pattern_id     Optional pattern/template identifier.
	 * @param array<string, mixed> $settings       Effective SMS settings.
	 * @param array<string, mixed> $context        Additional dispatch context.
	 * @return true|WP_Error
	 */
	public function send_pattern(
		string $recipient_e164,
		array $args,
		?string $pattern_id = null,
		array $settings = [],
		array $context = []
	): bool|WP_Error;

	/**
	 * Send a plain text SMS to an E.164 recipient.
	 *
	 * @param string               $recipient_e164 Normalized E.164 number.
	 * @param string               $message        SMS body text.
	 * @param string|null          $from           Optional sender ID/number override.
	 * @param array<string, mixed> $settings       Effective SMS settings.
	 * @param array<string, mixed> $context        Additional dispatch context.
	 * @return true|WP_Error
	 */
	public function send_text(
		string $recipient_e164,
		string $message,
		?string $from = null,
		array $settings = [],
		array $context = []
	): bool|WP_Error;
}