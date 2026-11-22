<?php
/**
 * Neora Core — Module Manager.
 *
 * Coordinates the two-phase module lifecycle for enabled modules in
 * topological dependency order:
 *
 *   Phase 1 — register(): every active module binds its services into the container.
 *   Phase 2 — boot():     every active module attaches its WordPress hooks.
 *
 * Disabled modules or modules with unmet external/internal dependencies remain
 * inspectable via all() / get(), but never execute register() or boot().
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

use InvalidArgumentException;
use LogicException;

final class ModuleManager {

	/** @var array<string, ModuleInterface> Registered modules keyed by module key. */
	private array $modules = [];

	/** @var array<string, true> Keys of modules that passed state & dependency checks and booted. */
	private array $active_keys = [];

	/** @var array<int, string> Ordered list of booted module keys (dependencies first). */
	private array $boot_order = [];

	/** @var array<string, array<int, string>> Internal dependency errors recorded per module key. */
	private array $dependency_errors = [];

	/** Prevents double-boot and late registration. */
	private bool $booted = false;

	/**
	 * Register a module into the catalog.
	 *
	 * @throws LogicException           If called after the lifecycle has booted.
	 * @throws InvalidArgumentException If a module with the same key already exists.
	 */
	public function add( ModuleInterface $module ): void {
		if ( $this->booted ) {
			throw new LogicException(
				sprintf(
					'[Neora Core] Cannot register module "%s": the lifecycle has already booted.',
					$module->get_key()
				)
			);
		}

		$key = $module->get_key();

		if ( isset( $this->modules[ $key ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'[Neora Core] A module with key "%s" is already registered. Each module key must be unique.',
					$key
				)
			);
		}

		$this->modules[ $key ] = $module;
	}

	/**
	 * Run the two-phase lifecycle for all enabled modules whose dependencies are met.
	 */
	public function boot(
		Container $container,
		HookRegistry $hooks,
		?ModuleStateStore $state_store = null
	): void {
		if ( $this->booted ) {
			return;
		}

		$store  = $state_store ?? new ModuleStateStore();
		$states = $store->get_effective_states( $this->modules );
		$plan   = $this->resolve_boot_plan( $states );

		$this->boot_order        = $plan['boot_order'];
		$this->dependency_errors = $plan['errors'];
		$this->active_keys       = $plan['bootable'];

		// Phase 1: bind services for active modules in topological order.
		foreach ( $this->boot_order as $key ) {
			$this->modules[ $key ]->register( $container );
		}

		// Phase 2: attach hooks for active modules in topological order.
		foreach ( $this->boot_order as $key ) {
			$this->modules[ $key ]->boot( $hooks );
		}

		$this->booted = true;
	}

	/**
	 * Resolve topological boot order and internal dependency errors for the
	 * registered modules under a given effective state map.
	 *
	 * @param array<string, bool> $effective_states
	 * @return array{
	 *     boot_order: array<int, string>,
	 *     errors: array<string, array<int, string>>,
	 *     bootable: array<string, true>
	 * }
	 */
	public function resolve_boot_plan( array $effective_states ): array {
		return self::resolve_modules_boot_plan( $this->modules, $effective_states );
	}

	/**
	 * Pure dependency graph & cycle resolver for an arbitrary module map.
	 *
	 * @param array<string, ModuleInterface> $modules
	 * @param array<string, bool>            $effective_states
	 * @return array{
	 *     boot_order: array<int, string>,
	 *     errors: array<string, array<int, string>>,
	 *     bootable: array<string, true>
	 * }
	 */
	public static function resolve_modules_boot_plan( array $modules, array $effective_states ): array {
		$boot_order   = [];
		$errors       = [];
		$bootable     = [];
		$color        = []; // 0/unset = unvisited, 1 = visiting, 2 = visited.
		$can_boot     = [];
		$cycle_errors = [];

		$visit = null;
		$visit = static function ( string $key, array $path ) use (
			&$visit,
			&$boot_order,
			&$errors,
			&$bootable,
			&$color,
			&$can_boot,
			&$cycle_errors,
			$modules,
			$effective_states
		): bool {
			if ( isset( $color[ $key ] ) && 2 === $color[ $key ] ) {
				return $can_boot[ $key ] ?? false;
			}

			if ( isset( $color[ $key ] ) && 1 === $color[ $key ] ) {
				$cycle_start   = array_search( $key, $path, true );
				$cycle_nodes   = false !== $cycle_start ? array_slice( $path, (int) $cycle_start ) : $path;
				$cycle_nodes[] = $key;
				$cycle_trace   = implode( ' -> ', $cycle_nodes );

				$template = function_exists( '__' )
					/* translators: %s: dependency cycle path */
					? __( 'Circular dependency detected: %s.', 'neora-core' )
					: 'Circular dependency detected: %s.';
				$message = sprintf( $template, $cycle_trace );

				foreach ( array_unique( $cycle_nodes ) as $cycle_member ) {
					$cycle_errors[ $cycle_member ] = $cycle_errors[ $cycle_member ] ?? [];
					if ( ! in_array( $message, $cycle_errors[ $cycle_member ], true ) ) {
						$cycle_errors[ $cycle_member ][] = $message;
					}
				}

				return false;
			}

			$module = $modules[ $key ] ?? null;
			if ( null === $module ) {
				return false;
			}

			if ( empty( $effective_states[ $key ] ) ) {
				$color[ $key ]    = 2;
				$can_boot[ $key ] = false;
				return false;
			}

			if ( $module instanceof ModuleMetadataInterface && ! empty( $module->get_missing_dependencies() ) ) {
				$color[ $key ]    = 2;
				$can_boot[ $key ] = false;
				return false;
			}

			$color[ $key ] = 1;
			$path[]        = $key;

			$module_errors = [];
			$all_ok        = true;

			foreach ( self::extract_dependencies( $module ) as $dep_key ) {
				if ( $dep_key === $key ) {
					$template = function_exists( '__' )
						/* translators: %s: module key */
						? __( 'Module "%s" cannot depend on itself.', 'neora-core' )
						: 'Module "%s" cannot depend on itself.';
					$module_errors[] = sprintf( $template, $key );
					$all_ok          = false;
					continue;
				}

				$dep_module = $modules[ $dep_key ] ?? null;
				if ( null === $dep_module ) {
					$template = function_exists( '__' )
						/* translators: %s: missing required module key */
						? __( 'Requires unregistered module "%s".', 'neora-core' )
						: 'Requires unregistered module "%s".';
					$module_errors[] = sprintf( $template, $dep_key );
					$all_ok          = false;
					continue;
				}

				$dep_label = $dep_module instanceof ModuleMetadataInterface
					? $dep_module->get_name()
					: $dep_key;

				if ( empty( $effective_states[ $dep_key ] ) ) {
					$template = function_exists( '__' )
						/* translators: 1: required module name, 2: required module key */
						? __( 'Requires active module "%1$s" (%2$s).', 'neora-core' )
						: 'Requires active module "%1$s" (%2$s).';
					$module_errors[] = sprintf( $template, $dep_label, $dep_key );
					$all_ok          = false;
					continue;
				}

				if ( $dep_module instanceof ModuleMetadataInterface && ! empty( $dep_module->get_missing_dependencies() ) ) {
					$template = function_exists( '__' )
						/* translators: 1: required module name, 2: required module key */
						? __( 'Required module "%1$s" (%2$s) has unmet external dependencies.', 'neora-core' )
						: 'Required module "%1$s" (%2$s) has unmet external dependencies.';
					$module_errors[] = sprintf( $template, $dep_label, $dep_key );
					$all_ok          = false;
					continue;
				}

				$dep_ok = $visit( $dep_key, $path );
				if ( ! $dep_ok ) {
					$all_ok = false;
					if ( empty( $cycle_errors[ $key ] ) ) {
						$template = function_exists( '__' )
							/* translators: 1: required module name, 2: required module key */
							? __( 'Required module "%1$s" (%2$s) could not be activated.', 'neora-core' )
							: 'Required module "%1$s" (%2$s) could not be activated.';
						$module_errors[] = sprintf( $template, $dep_label, $dep_key );
					}
				}
			}

			$color[ $key ] = 2;

			if ( ! empty( $cycle_errors[ $key ] ) ) {
				$all_ok        = false;
				$module_errors = array_values( array_unique( array_merge( $cycle_errors[ $key ], $module_errors ) ) );
			}

			if ( $all_ok ) {
				$can_boot[ $key ] = true;
				$bootable[ $key ] = true;
				$boot_order[]     = $key;
				return true;
			}

			$can_boot[ $key ] = false;
			if ( ! empty( $module_errors ) ) {
				$errors[ $key ] = array_values( array_unique( $module_errors ) );
			}

			return false;
		};

		foreach ( array_keys( $modules ) as $key ) {
			if ( empty( $effective_states[ $key ] ) ) {
				continue;
			}

			$visit( $key, [] );
		}

		return [
			'boot_order' => $boot_order,
			'errors'     => $errors,
			'bootable'   => $bootable,
		];
	}

	/**
	 * Return unique normalized dependency keys declared by a registered module.
	 *
	 * @return array<int, string>
	 */
	public function get_dependencies( string $key ): array {
		$module = $this->modules[ $key ] ?? null;
		if ( null === $module ) {
			return [];
		}

		return self::extract_dependencies( $module );
	}

	/**
	 * Return keys of registered modules that declare $key as a dependency.
	 *
	 * @return array<int, string>
	 */
	public function get_dependents( string $key ): array {
		$dependents = [];

		foreach ( $this->modules as $candidate_key => $module ) {
			if ( $candidate_key !== $key && in_array( $key, self::extract_dependencies( $module ), true ) ) {
				$dependents[] = $candidate_key;
			}
		}

		return $dependents;
	}

	/**
	 * Return internal dependency errors detected for a module during boot().
	 *
	 * @return array<int, string>
	 */
	public function get_dependency_errors( string $key ): array {
		return $this->dependency_errors[ $key ] ?? [];
	}

	/**
	 * Return the topological boot order of active modules.
	 *
	 * @return array<int, string>
	 */
	public function get_boot_order(): array {
		return $this->boot_order;
	}

	/**
	 * Check whether a module key exists in the catalog.
	 */
	public function has( string $key ): bool {
		return isset( $this->modules[ $key ] );
	}

	/**
	 * Retrieve a registered module by key, or null if not found.
	 */
	public function get( string $key ): ?ModuleInterface {
		return $this->modules[ $key ] ?? null;
	}

	/**
	 * Return all registered modules (both active and inactive).
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Return only modules that were booted in the current lifecycle (in boot order).
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function get_active_modules(): array {
		$active = [];
		foreach ( $this->active_keys as $key => $_ ) {
			if ( isset( $this->modules[ $key ] ) ) {
				$active[ $key ] = $this->modules[ $key ];
			}
		}

		return $active;
	}

	/**
	 * Whether a module is active and booted.
	 */
	public function is_active( string $key ): bool {
		return isset( $this->active_keys[ $key ] );
	}

	/**
	 * Whether the lifecycle has completed its boot phase.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Extract normalized unique dependency keys from a module instance.
	 *
	 * @return array<int, string>
	 */
	private static function extract_dependencies( ModuleInterface $module ): array {
		if ( ! $module instanceof DependentModuleInterface ) {
			return [];
		}

		$deps = [];
		foreach ( $module->get_dependencies() as $dep ) {
			if ( ! is_string( $dep ) ) {
				continue;
			}
			$dep = trim( $dep );
			if ( '' !== $dep && ! in_array( $dep, $deps, true ) ) {
				$deps[] = $dep;
			}
		}

		return $deps;
	}
}