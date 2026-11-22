<?php
/**
 * Neora Core — Module State Store.
 *
 * Manages persisted module toggle states, code-level filter overrides,
 * and deferred rewrite rule flushing when CPT/taxonomy modules change state.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

final class ModuleStateStore {

	/**
	 * Option key storing [ module_key => bool ].
	 */
	public const OPTION_KEY = 'neora_active_modules';

	/**
	 * Option flag scheduling a rewrite rules flush on the next init hook.
	 */
	public const FLUSH_OPTION_KEY = 'neora_flush_rewrite_rules';

	/**
	 * Legacy module keys mapped to their standardized canonical keys.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_KEY_MAP = [
		'auth'      => 'neora/auth',
		'portfolio' => 'neora/portfolio',
	];

	/**
	 * Optional in-memory states used for isolated unit tests.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $override_states;

	/**
	 * @param array<string, bool>|null $override_states Optional preloaded states for testing.
	 */
	public function __construct( ?array $override_states = null ) {
		$this->override_states = $override_states;
	}

	/**
	 * Retrieve raw saved toggle states from wp_options (or test overrides).
	 *
	 * @return array<string, bool>
	 */
	public function get_saved_states(): array {
		if ( null !== $this->override_states ) {
			return array_map( 'boolval', $this->override_states );
		}

		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}

		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			return [];
		}

		$normalized = [];
		foreach ( $saved as $key => $enabled ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			$canonical = self::LEGACY_KEY_MAP[ $key ] ?? $key;
			if ( $canonical !== $key && array_key_exists( $canonical, $saved ) ) {
				continue;
			}

			$normalized[ $canonical ] = (bool) $enabled;
		}

		return $normalized;
	}

	/**
	 * Compute final effective enabled/disabled map for all registered modules.
	 *
	 * Resolution order:
	 *   1. Module default (via ModuleMetadataInterface::is_default_enabled(), or true)
	 *   2. Persisted option value in wp_options
	 *   3. Code-level override via 'neora_core_enabled_modules' filter
	 *   4. Core modules (is_core() === true) are always forced enabled
	 *
	 * @param array<string, ModuleInterface> $modules Registered modules keyed by module key.
	 * @return array<string, bool>
	 */
	public function get_effective_states( array $modules ): array {
		$saved = $this->get_saved_states();
		$map   = [];

		foreach ( $modules as $key => $module ) {
			if ( $module instanceof ModuleMetadataInterface && $module->is_core() ) {
				$map[ $key ] = true;
				continue;
			}

			if ( array_key_exists( $key, $saved ) ) {
				$map[ $key ] = (bool) $saved[ $key ];
				continue;
			}

			$map[ $key ] = $module instanceof ModuleMetadataInterface
				? $module->is_default_enabled()
				: true;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_enabled_modules', $map );
			if ( is_array( $filtered ) ) {
				foreach ( $modules as $key => $module ) {
					if ( array_key_exists( $key, $filtered ) ) {
						$map[ $key ] = (bool) $filtered[ $key ];
					}
				}
			}
		}

		// Core modules must remain active regardless of filters or saved state.
		foreach ( $modules as $key => $module ) {
			if ( $module instanceof ModuleMetadataInterface && $module->is_core() ) {
				$map[ $key ] = true;
			}
		}

		return $map;
	}

	/**
	 * Persist toggle states for feature modules and schedule rewrite flush if needed.
	 *
	 * @param array<string, mixed>           $submitted_states Incoming [ module_key => bool ].
	 * @param array<string, ModuleInterface> $modules          Registered modules keyed by module key.
	 * @return bool True if option updated or unchanged without error.
	 */
	public function save_states( array $submitted_states, array $modules ): bool {
		$saved           = $this->get_saved_states();
		$previous        = $this->get_effective_states( $modules );
		$sanitized       = [];
		$after_effective = [];

		foreach ( $modules as $key => $module ) {
			if ( $module instanceof ModuleMetadataInterface && $module->is_core() ) {
				$sanitized[ $key ]       = true;
				$after_effective[ $key ] = true;
				continue;
			}

			$baseline = array_key_exists( $key, $saved )
				? (bool) $saved[ $key ]
				: ( $module instanceof ModuleMetadataInterface ? $module->is_default_enabled() : true );

			// Preserve baseline state when a code-level filter overrides the UI checkbox (rendered disabled).
			if ( ( $previous[ $key ] ?? $baseline ) !== $baseline ) {
				$sanitized[ $key ]       = $baseline;
				$after_effective[ $key ] = (bool) $previous[ $key ];
				continue;
			}

			$sanitized[ $key ]       = ! empty( $submitted_states[ $key ] );
			$after_effective[ $key ] = $sanitized[ $key ];
		}

		if ( ! function_exists( 'update_option' ) ) {
			$this->override_states = $sanitized;
			return true;
		}

		$updated = update_option( self::OPTION_KEY, $sanitized );

		$before_plan = ModuleManager::resolve_modules_boot_plan( $modules, $previous );
		$after_plan  = ModuleManager::resolve_modules_boot_plan( $modules, $after_effective );

		// Flush rewrites if any rewrite-aware module changes bootable status (directly or via dependency).
		foreach ( $modules as $key => $module ) {
			$has_rewrites = $module instanceof ModuleMetadataInterface && $module->has_rewrite_rules();
			if ( ! $has_rewrites ) {
				continue;
			}

			$was_bootable = ! empty( $before_plan['bootable'][ $key ] );
			$is_bootable  = ! empty( $after_plan['bootable'][ $key ] );

			if ( $was_bootable !== $is_bootable ) {
				$this->schedule_rewrite_flush();
				break;
			}
		}

		return $updated || $previous === $sanitized;
	}

	/**
	 * Mark rewrite rules to be flushed on the next 'init' cycle after CPTs register.
	 */
	public function schedule_rewrite_flush(): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::FLUSH_OPTION_KEY, 1, true );
		}
	}

	/**
	 * Flush rewrite rules once if scheduled, after all active CPTs/taxonomies have registered.
	 *
	 * Hooked on 'init' at priority 99.
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'flush_rewrite_rules' ) ) {
			return;
		}

		if ( get_option( self::FLUSH_OPTION_KEY ) ) {
			delete_option( self::FLUSH_OPTION_KEY );
			flush_rewrite_rules();
		}
	}
}