<?php
/**
 * Neora Core — Optional Module Metadata Interface.
 *
 * Modules implementing this interface expose metadata for the admin UI,
 * dependency checks, toggle state defaults, and rewrite-rule management.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

interface ModuleMetadataInterface extends ModuleInterface {

	/**
	 * Human-readable module name (translatable).
	 */
	public function get_name(): string;

	/**
	 * Short description of what the module provides (translatable).
	 */
	public function get_description(): string;

	/**
	 * Core modules are always active and cannot be toggled off.
	 */
	public function is_core(): bool;

	/**
	 * Default enabled state when no saved preference exists.
	 */
	public function is_default_enabled(): bool;

	/**
	 * List of human-readable unmet dependency messages (empty when ready).
	 *
	 * @return array<int, string>
	 */
	public function get_missing_dependencies(): array;

	/**
	 * Whether toggling this module requires flushing WordPress rewrite rules.
	 */
	public function has_rewrite_rules(): bool;
}