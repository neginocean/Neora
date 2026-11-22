<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\DependentModuleInterface;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleManager;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Core\ModuleStateStore;
use PHPUnit\Framework\TestCase;

final class ModuleManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeSpyModule(
		string $key,
		array &$log,
		bool $is_core = false,
		array $missing_deps = []
	): ModuleMetadataInterface {
		return new class( $key, $log, $is_core, $missing_deps ) implements ModuleMetadataInterface {
			public function __construct(
				private string $key,
				private array &$log,
				private bool $is_core,
				private array $missing_deps
			) {}
			public function get_key(): string { return $this->key; }
			public function register( Container $container ): void { $this->log[] = 'register:' . $this->key; }
			public function boot( HookRegistry $hooks ): void { $this->log[] = 'boot:' . $this->key; }
			public function get_name(): string { return $this->key; }
			public function get_description(): string { return ''; }
			public function is_core(): bool { return $this->is_core; }
			public function is_default_enabled(): bool { return true; }
			public function get_missing_dependencies(): array { return $this->missing_deps; }
			public function has_rewrite_rules(): bool { return false; }
		};
	}

	public function test_boot_runs_only_enabled_modules_with_satisfied_dependencies(): void {
		$log     = [];
		$manager = new ModuleManager();

		$manager->add( $this->makeSpyModule( 'core', $log, true ) );
		$manager->add( $this->makeSpyModule( 'enabled_feature', $log ) );
		$manager->add( $this->makeSpyModule( 'disabled_feature', $log ) );
		$manager->add( $this->makeSpyModule( 'missing_dep', $log, false, [ 'WooCommerce is required' ] ) );

		$store = new ModuleStateStore(
			[
				'core'             => true,
				'enabled_feature'  => true,
				'disabled_feature' => false,
				'missing_dep'      => true,
			]
		);

		$manager->boot( new Container(), new HookRegistry(), $store );

		$this->assertSame(
			[
				'register:core',
				'register:enabled_feature',
				'boot:core',
				'boot:enabled_feature',
			],
			$log
		);

		$this->assertTrue( $manager->has( 'disabled_feature' ) );
		$this->assertFalse( $manager->is_active( 'disabled_feature' ) );
		$this->assertFalse( $manager->is_active( 'missing_dep' ) );
		$this->assertTrue( $manager->is_active( 'enabled_feature' ) );
		$this->assertCount( 4, $manager->all() );
		$this->assertCount( 2, $manager->get_active_modules() );
	}

	public function test_dependent_module_boots_after_its_dependency(): void {
		$log     = [];
		$manager = new ModuleManager();

		$child = new class( $log ) implements DependentModuleInterface {
			public function __construct( private array &$log ) {}
			public function get_key(): string { return 'child'; }
			public function get_dependencies(): array { return [ 'parent' ]; }
			public function register( Container $container ): void { $this->log[] = 'register:child'; }
			public function boot( HookRegistry $hooks ): void { $this->log[] = 'boot:child'; }
		};

		$manager->add( $child );
		$manager->add( $this->makeSpyModule( 'parent', $log ) );

		$manager->boot( new Container(), new HookRegistry(), new ModuleStateStore( [] ) );

		$this->assertSame(
			[
				'register:parent',
				'register:child',
				'boot:parent',
				'boot:child',
			],
			$log
		);
		$this->assertSame( [ 'parent', 'child' ], $manager->get_boot_order() );
	}
}