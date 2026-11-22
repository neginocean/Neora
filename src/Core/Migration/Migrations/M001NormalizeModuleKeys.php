<?php
/**
 * Migration 001 — Permanently normalize legacy module keys in wp_options.
 *
 * Converts historical 'auth' and 'portfolio' keys in neora_active_modules
 * to their canonical 'neora/auth' and 'neora/portfolio' identifiers.
 *
 * @package NeoraStudio\NeoraCore\Core\Migration\Migrations
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core\Migration\Migrations;

use NeoraStudio\NeoraCore\Core\Migration\MigrationInterface;
use NeoraStudio\NeoraCore\Core\ModuleStateStore;

final class M001NormalizeModuleKeys implements MigrationInterface {

	/**
	 * Historical key mapping snapshot for this migration.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_MAP = [
		'auth'      => 'neora/auth',
		'portfolio' => 'neora/portfolio',
	];

	public function get_id(): string {
		return '001_normalize_module_keys';
	}

	public function get_version(): string {
		return '1.0.0';
	}

	public function get_description(): string {
		return 'Normalize legacy module keys (auth, portfolio) to canonical neora/* keys in neora_active_modules.';
	}

	public function up(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$saved = get_option( ModuleStateStore::OPTION_KEY, null );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return;
		}

		$normalized = [];
		$changed    = false;

		foreach ( $saved as $key => $enabled ) {
			if ( ! is_string( $key ) || '' === $key ) {
				$changed = true;
				continue;
			}

			$canonical = self::LEGACY_MAP[ $key ] ?? $key;
			if ( $canonical !== $key ) {
				$changed = true;
				if ( array_key_exists( $canonical, $saved ) ) {
					// Canonical key already present; keep canonical entry.
					continue;
				}
			}

			$normalized[ $canonical ] = (bool) $enabled;
		}

		if ( $changed || $normalized !== $saved ) {
			update_option( ModuleStateStore::OPTION_KEY, $normalized );
		}
	}
}