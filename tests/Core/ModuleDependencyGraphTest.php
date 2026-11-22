<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\DependentModuleInterface;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleInterface;
use NeoraStudio\NeoraCore\Core\ModuleManager;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Core\ModuleStateStore;
use PHPUnit\Framework\TestCase;

final class ModuleDependencyGraphTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, string> $deps
	 * @param array<int, string> $external_missing
	 */
	private function makeDependentModule(
		string $key,
		array &$log,
		array $deps = [],
		bool $is_core = false,
		array $external_missing = [],
		bool $has_rewrites = false
	): ModuleMetadataInterface&DependentModuleInterface {
		return new class( $key, $log, $deps, $is_core, $external_missing, $has_rewrites ) implements ModuleMetadataInterface, DependentModuleInterface {
			public function __construct(
				private string $key,
				private array &$log,
				private array $deps,
				private bool $is_core,
				private array $external_missing,
				private bool $has_rewrites
			) {}

			public function get_key(): string { return $this->key; }
			public function register( Container $container ): void { $this->log[] = 'register:' . $this->key; }
			public function boot( HookRegistry $hooks ): void { $this->log[] = 'boot:' . $this->key; }
			public function get_name(): string { return strtoupper( $this->key ); }
			public function get_description(): string { return ''; }
			public function is_core(): bool { return $this->is_core; }
			public function is_default_enabled(): bool { return true; }
			public function get_missing_dependencies(): array { return $this->external_missing; }
			public function has_rewrite_rules(): bool { return $this->has_rewrites; }
			public function get_dependencies(): array { return $this->deps; }
		};
	}

	public function test_boot_sorts_modules_topologically_regardless_of_registration_order(): void {
		$log     = [];
		$manager = new ModuleManager();

		// Register in reverse order: C depends on B, B depends on A.
		$manager->add( $this->makeDependentModule( 'mod_c', $log, [ 'mod_b' ] ) );
		$manager->add( $this->makeDependentModule( 'mod_b', $log, [ 'mod_a' ] ) );
		$manager->add( $this->makeDependentModule( 'mod_a', $log, [] ) );

		$store = new ModuleStateStore(
			[
				'mod_c' => true,
				'mod_b' => true,
				'mod_a' => true,
			]
		);

		$manager->boot( new Container(), new HookRegistry(), $store );

		$this->assertSame( [ 'mod_a', 'mod_b', 'mod_c' ], $manager->get_boot_order() );
		$this->assertSame(
			[
				'register:mod_a',
				'register:mod_b',
				'register:mod_c',
				'boot:mod_a',
				'boot:mod_b',
				'boot:mod_c',
			],
			$log
		);
	}

	public function test_disabling_upstream_module_blocks_transitive_dependents_with_errors(): void {
		$log     = [];
		$manager = new ModuleManager();

		$manager->add( $this->makeDependentModule( 'mod_a', $log, [] ) );
		$manager->add( $this->makeDependentModule( 'mod_b', $log, [ 'mod_a' ] ) );
		$manager->add( $this->makeDependentModule( 'mod_c', $log, [ 'mod_b' ] ) );

		$store = new ModuleStateStore(
			[
				'mod_a' => false,
				'mod_b' => true,
				'mod_c' => true,
			]
		);

		$manager->boot( new Container(), new HookRegistry(), $store );

		$this->assertSame( [], $log );
		$this->assertFalse( $manager->is_active( 'mod_a' ) );
		$this->assertFalse( $manager->is_active( 'mod_b' ) );
		$this->assertFalse( $manager->is_active( 'mod_c' ) );

		$this->assertNotEmpty( $manager->get_dependency_errors( 'mod_b' ) );
		$this->assertNotEmpty( $manager->get_dependency_errors( 'mod_c' ) );
		$this->assertSame( [], $manager->get_dependency_errors( 'mod_a' ) );
	}

	public function test_unregistered_and_external_unmet_dependencies_prevent_booting(): void {
		$log     = [];
		$manager = new ModuleManager();

		$manager->add( $this->makeDependentModule( 'ext_blocked', $log, [], false, [ 'WooCommerce required' ] ) );
		$manager->add( $this->makeDependentModule( 'needs_ext', $log, [ 'ext_blocked' ] ) );
		$manager->add( $this->makeDependentModule( 'needs_ghost', $log, [ 'neora/nonexistent' ] ) );

		$store = new ModuleStateStore(
			[
				'ext_blocked' => true,
				'needs_ext'   => true,
				'needs_ghost' => true,
			]
		);

		$manager->boot( new Container(), new HookRegistry(), $store );

		$this->assertSame( [], $manager->get_active_modules() );
		$this->assertStringContainsString( 'external dependencies', $manager->get_dependency_errors( 'needs_ext' )[0] );
		$this->assertStringContainsString( 'neora/nonexistent', $manager->get_dependency_errors( 'needs_ghost' )[0] );
	}

	public function test_circular_and_self_dependencies_are_detected_and_blocked(): void {
		$log     = [];
		$manager = new ModuleManager();

		$manager->add( $this->makeDependentModule( 'self_dep', $log, [ 'self_dep' ] ) );
		$manager->add( $this->makeDependentModule( 'cyc_a', $log, [ 'cyc_b' ] ) );
		$manager->add( $this->makeDependentModule( 'cyc_b', $log, [ 'cyc_c' ] ) );
		$manager->add( $this->makeDependentModule( 'cyc_c', $log, [ 'cyc_a' ] ) );
		$manager->add( $this->makeDependentModule( 'outside', $log, [ 'cyc_a' ] ) );
		$manager->add( $this->makeDependentModule( 'healthy', $log, [] ) );

		$store = new ModuleStateStore(
			[
				'self_dep' => true,
				'cyc_a'    => true,
				'cyc_b'    => true,
				'cyc_c'    => true,
				'outside'  => true,
				'healthy'  => true,
			]
		);

		$manager->boot( new Container(), new HookRegistry(), $store );

		$this->assertSame( [ 'healthy' ], $manager->get_boot_order() );
		$this->assertStringContainsString( 'itself', $manager->get_dependency_errors( 'self_dep' )[0] );
		$this->assertStringContainsString( 'Circular dependency', $manager->get_dependency_errors( 'cyc_a' )[0] );
		$this->assertStringContainsString( 'Circular dependency', $manager->get_dependency_errors( 'cyc_b' )[0] );
		$this->assertStringContainsString( 'Circular dependency', $manager->get_dependency_errors( 'cyc_c' )[0] );
		$this->assertNotEmpty( $manager->get_dependency_errors( 'outside' ) );
	}

	public function test_get_dependents_and_plain_module_compatibility(): void {
		$log     = [];
		$manager = new ModuleManager();

		$plain = new class( $log ) implements ModuleInterface {
			public function __construct( private array &$log ) {}
			public function get_key(): string { return 'plain_base'; }
			public function register( Container $container ): void { $this->log[] = 'register:plain_base'; }
			public function boot( HookRegistry $hooks ): void { $this->log[] = 'boot:plain_base'; }
		};

		$child = $this->makeDependentModule( 'child_mod', $log, [ 'plain_base', 'plain_base', ' ' ] );

		$manager->add( $child );
		$manager->add( $plain );

		$this->assertSame( [ 'plain_base' ], $manager->get_dependencies( 'child_mod' ) );
		$this->assertSame( [ 'child_mod' ], $manager->get_dependents( 'plain_base' ) );
		$this->assertSame( [], $manager->get_dependents( 'child_mod' ) );

		$manager->boot( new Container(), new HookRegistry(), new ModuleStateStore( [] ) );
		$this->assertSame( [ 'plain_base', 'child_mod' ], $manager->get_boot_order() );
	}
}