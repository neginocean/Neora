<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\Migration\MigrationInterface;
use NeoraStudio\NeoraCore\Core\Migration\MigrationRunner;
use NeoraStudio\NeoraCore\Core\Migration\Migrations\M001NormalizeModuleKeys;
use NeoraStudio\NeoraCore\Core\ModuleStateStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationRunnerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeMigration( string $id, string $version, callable $callback ): MigrationInterface {
		return new class( $id, $version, $callback ) implements MigrationInterface {
			/** @var callable */
			private $callback;

			public function __construct(
				private string $id,
				private string $version,
				callable $callback
			) {
				$this->callback = $callback;
			}

			public function get_id(): string { return $this->id; }
			public function get_version(): string { return $this->version; }
			public function get_description(): string { return $this->id; }
			public function up(): void { ( $this->callback )(); }
		};
	}

	public function test_run_returns_up_to_date_when_schema_version_matches(): void {
		$executed = false;

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $default = false ): mixed =>
				MigrationRunner::VERSION_OPTION_KEY === $key
					? MigrationRunner::CURRENT_SCHEMA_VERSION
					: $default
		);
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		$runner = new MigrationRunner(
			[
				$this->makeMigration( '001_test', '1.0.0', static function () use ( &$executed ): void {
					$executed = true;
				} ),
			]
		);

		$result = $runner->run();

		$this->assertSame( 'up_to_date', $result['status'] );
		$this->assertFalse( $executed );
	}

	public function test_run_aborts_cleanly_when_lock_transient_is_active(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'get_transient' )
			->once()
			->with( MigrationRunner::LOCK_TRANSIENT_KEY )
			->andReturn( time() );
		Functions\expect( 'set_transient' )->never();

		$runner = new MigrationRunner();
		$result = $runner->run();

		$this->assertSame( 'locked', $result['status'] );
	}

	public function test_run_sorts_by_version_skips_applied_and_updates_db_version(): void {
		$log     = [];
		$options = [
			MigrationRunner::VERSION_OPTION_KEY => '',
			MigrationRunner::APPLIED_OPTION_KEY => [ '000_done' => 1700000000 ],
		];

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $default = false ): mixed => $options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once()->with( MigrationRunner::LOCK_TRANSIENT_KEY, Mockery::type( 'int' ), 60 )->andReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( MigrationRunner::LOCK_TRANSIENT_KEY )->andReturn( true );

		$runner = new MigrationRunner(
			[
				$this->makeMigration( '002_second', '2.0.0', static function () use ( &$log ): void { $log[] = '002'; } ),
				$this->makeMigration( '000_done', '0.5.0', static function () use ( &$log ): void { $log[] = '000'; } ),
				$this->makeMigration( '001_first', '1.0.0', static function () use ( &$log ): void { $log[] = '001'; } ),
			]
		);

		$result = $runner->run();

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( [ '001', '002' ], $log );
		$this->assertSame( [ '001_first', '002_second' ], $result['applied'] );
		$this->assertSame( [ '000_done' ], $result['skipped'] );
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( MigrationRunner::CURRENT_SCHEMA_VERSION, $options[ MigrationRunner::VERSION_OPTION_KEY ] );
		$this->assertArrayHasKey( '001_first', $options[ MigrationRunner::APPLIED_OPTION_KEY ] );
		$this->assertArrayHasKey( '002_second', $options[ MigrationRunner::APPLIED_OPTION_KEY ] );
	}

	public function test_run_stops_on_failure_preserves_partial_progress_and_releases_lock(): void {
		$options = [
			MigrationRunner::VERSION_OPTION_KEY => '',
			MigrationRunner::APPLIED_OPTION_KEY => [],
		];

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $default = false ): mixed => $options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once()->andReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( MigrationRunner::LOCK_TRANSIENT_KEY )->andReturn( true );
		Functions\expect( 'error_log' )->once();

		$third_ran = false;

		$runner = new MigrationRunner(
			[
				$this->makeMigration( 'm1', '1.0.0', static function (): void {} ),
				$this->makeMigration( 'm2', '1.1.0', static function (): void {
					throw new RuntimeException( 'Simulated failure' );
				} ),
				$this->makeMigration( 'm3', '1.2.0', static function () use ( &$third_ran ): void {
					$third_ran = true;
				} ),
			]
		);

		$result = $runner->run();

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( [ 'm1' ], $result['applied'] );
		$this->assertSame( [ 'm2' => 'Simulated failure' ], $result['errors'] );
		$this->assertFalse( $third_ran );
		$this->assertSame( '', $options[ MigrationRunner::VERSION_OPTION_KEY ] );
		$this->assertArrayHasKey( 'm1', $options[ MigrationRunner::APPLIED_OPTION_KEY ] );
		$this->assertArrayNotHasKey( 'm2', $options[ MigrationRunner::APPLIED_OPTION_KEY ] );
	}

	public function test_m001_normalizes_legacy_module_keys_and_respects_canonical_precedence(): void {
		$migration = new M001NormalizeModuleKeys();

		Functions\expect( 'get_option' )
			->once()
			->with( ModuleStateStore::OPTION_KEY, null )
			->andReturn(
				[
					'auth'           => false,
					'neora/auth'     => true, // Canonical must win over legacy 'auth'.
					'portfolio'      => false,
					'neora/services' => true,
				]
			);

		Functions\expect( 'update_option' )
			->once()
			->with(
				ModuleStateStore::OPTION_KEY,
				[
					'neora/auth'      => true,
					'neora/portfolio' => false,
					'neora/services'  => true,
				]
			)
			->andReturn( true );

		$migration->up();
		$this->addToAssertionCount( 1 );
	}

	public function test_bootstrap_registers_migrations_service(): void {
		$app = Bootstrap::create_application();
		$this->assertTrue( $app->container()->has( 'neora.migrations' ) );

		/** @var MigrationRunner $runner */
		$runner = $app->container()->get( 'neora.migrations' );
		$this->assertInstanceOf( MigrationRunner::class, $runner );
		$this->assertArrayHasKey( '001_normalize_module_keys', $runner->all() );
	}
}