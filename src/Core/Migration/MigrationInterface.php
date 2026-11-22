<?php
/**
 * Neora Core — Migration Contract.
 *
 * Every schema or option migration must implement this interface and ensure
 * its up() routine is safe and idempotent.
 *
 * @package NeoraStudio\NeoraCore\Core\Migration
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core\Migration;

interface MigrationInterface {

	/**
	 * Unique immutable identifier for this migration (e.g. '001_normalize_module_keys').
	 */
	public function get_id(): string;

	/**
	 * Schema version introduced or targeted by this migration (e.g. '1.0.0').
	 */
	public function get_version(): string;

	/**
	 * Short human-readable summary of what this migration performs.
	 */
	public function get_description(): string;

	/**
	 * Apply the migration. Must be idempotent.
	 */
	public function up(): void;
}