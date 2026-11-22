<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\DependentModuleInterface;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleInterface;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Core\ModuleStateStore;
use PHPUnit\Framework\TestCase;

final class ModuleStateStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeMetaModule(
		string $key,
		bool $is_core = false,
		bool $default_enabled = true,
		bool $has_rewrites = false
	): ModuleMetadataInterface {
		return new class( $key, $is_core, $default_enabled, $has_rewrites ) implements ModuleMetadataInterface {
			public function __construct(
				private string $key,
				private bool $is_core,
				private bool $default_enabled,
				private bool $has_rewrites
			) {}
			public function get_key(): string { return $this->key; }
			public function register( Container $container ): void {}
			public function boot( HookRegistry $hooks ): void {}
			public function get_name(): string { return $this->key; }
			public function get_description(): string { return ''; }
			public function is_core(): bool { return $this->is_core; }
			public function is_default_enabled(): bool { return $this->default_enabled; }
			public function get_missing_dependencies(): array { return []; }
			public function has_rewrite_rules(): bool { return $this->has_rewrites; }
		};
	}

	public function test_core_modules_cannot_be_disabled_by_saved_state_or_filter(): void {
		$modules = [
			'core_mod'    => $this->makeMetaModule( 'core_mod', true, true ),
			'feature_mod' => $this->makeMetaModule( 'feature_mod', false, true ),
		];

		Filters\expectApplied( 'neora_core_enabled_modules' )
			->once()
			->andReturn(
				[
					'core_mod'    => false,
					'feature_mod' => false,
				]
			);

		$store  = new ModuleStateStore( [ 'core_mod' => false, 'feature_mod' => true ] );
		$states = $store->get_effective_states( $modules );

		$this->assertTrue( $states['core_mod'] );
		$this->assertFalse( $states['feature_mod'] );
	}

	public function test_plain_module_interface_defaults_to_enabled(): void {
		$plain = new class() implements ModuleInterface {
			public function get_key(): string { return 'plain'; }
			public function register( Container $container ): void {}
			public function boot( HookRegistry $hooks ): void {}
		};

		$store  = new ModuleStateStore( [] );
		$states = $store->get_effective_states( [ 'plain' => $plain ] );

		$this->assertTrue( $states['plain'] );
	}

	public function test_save_states_schedules_rewrite_flush_when_cpt_module_toggles(): void {
		$modules = [
			'core_mod' => $this->makeMetaModule( 'core_mod', true, true, false ),
			'services' => $this->makeMetaModule( 'services', false, true, true ),
		];

		Functions\when( 'get_option' )->justReturn( [ 'services' => true ] );

		Functions\expect( 'update_option' )
			->once()
			->with( ModuleStateStore::OPTION_KEY, [ 'core_mod' => true, 'services' => false ] )
			->andReturn( true );

		Functions\expect( 'update_option' )
			->once()
			->with( ModuleStateStore::FLUSH_OPTION_KEY, 1, true )
			->andReturn( true );

		$store = new ModuleStateStore();
		$this->assertTrue( $store->save_states( [ 'services' => false ], $modules ) );
	}

	public function test_save_states_schedules_rewrite_flush_when_dependency_of_cpt_module_toggles(): void {
		$dependent_cpt = new class() implements ModuleMetadataInterface, DependentModuleInterface {
			public function get_key(): string { return 'neora/dependent-cpt'; }
			public function register( Container $container ): void {}
			public function boot( HookRegistry $hooks ): void {}
			public function get_name(): string { return 'Dependent CPT'; }
			public function get_description(): string { return ''; }
			public function is_core(): bool { return false; }
			public function is_default_enabled(): bool { return true; }
			public function get_missing_dependencies(): array { return []; }
			public function has_rewrite_rules(): bool { return true; }
			public function get_dependencies(): array { return [ 'neora/base' ]; }
		};

		$modules = [
			'neora/base'          => $this->makeMetaModule( 'neora/base', false, true, false ),
			'neora/dependent-cpt' => $dependent_cpt,
		];

		Functions\when( 'get_option' )->justReturn(
			[
				'neora/base'          => true,
				'neora/dependent-cpt' => true,
			]
		);

		Functions\expect( 'update_option' )
			->once()
			->with(
				ModuleStateStore::OPTION_KEY,
				[
					'neora/base'          => false,
					'neora/dependent-cpt' => true,
				]
			)
			->andReturn( true );

		Functions\expect( 'update_option' )
			->once()
			->with( ModuleStateStore::FLUSH_OPTION_KEY, 1, true )
			->andReturn( true );

		$store = new ModuleStateStore();
		$this->assertTrue(
			$store->save_states(
				[
					'neora/base'          => false,
					'neora/dependent-cpt' => true,
				],
				$modules
			)
		);
	}

	public function test_maybe_flush_rewrite_rules_deletes_flag_and_flushes(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( ModuleStateStore::FLUSH_OPTION_KEY )
			->andReturn( 1 );

		Functions\expect( 'delete_option' )
			->once()
			->with( ModuleStateStore::FLUSH_OPTION_KEY )
			->andReturn( true );

		Functions\expect( 'flush_rewrite_rules' )->once();

		ModuleStateStore::maybe_flush_rewrite_rules();
		$this->addToAssertionCount( 1 );
	}

	public function test_get_saved_states_migrates_legacy_module_keys(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'auth'      => false,
				'portfolio' => true,
			]
		);

		$store = new ModuleStateStore();
		$saved = $store->get_saved_states();

		$this->assertFalse( $saved['neora/auth'] );
		$this->assertTrue( $saved['neora/portfolio'] );
		$this->assertArrayNotHasKey( 'auth', $saved );
		$this->assertArrayNotHasKey( 'portfolio', $saved );
	}

	public function test_save_states_preserves_filter_overridden_module_baseline(): void {
		$modules = [
			'neora/services' => $this->makeMetaModule( 'neora/services', false, true, false ),
			'neora/auth'     => $this->makeMetaModule( 'neora/auth', false, true, false ),
		];

		Functions\when( 'get_option' )->justReturn(
			[
				'neora/services' => true,
				'neora/auth'     => true,
			]
		);

		// Filter temporarily forces neora/services off while saved DB value is true.
		Filters\expectApplied( 'neora_core_enabled_modules' )
			->once()
			->andReturn(
				[
					'neora/services' => false,
					'neora/auth'     => true,
				]
			);

		// Disabled checkbox was not submitted for neora/services, yet saved baseline (true) must be preserved.
		Functions\expect( 'update_option' )
			->once()
			->with(
				ModuleStateStore::OPTION_KEY,
				[
					'neora/services' => true,
					'neora/auth'     => false,
				]
			)
			->andReturn( true );

		$store = new ModuleStateStore();
		$this->assertTrue( $store->save_states( [ 'neora/auth' => false ], $modules ) );
	}
}