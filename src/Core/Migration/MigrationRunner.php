<?php
/**
 * Neora Core — Idempotent Schema & Option Migration Runner.
 *
 * Tracks applied migration IDs and current schema version in wp_options,
 * uses a transient concurrency lock, and sorts pending migrations by version.
 *
 * @package NeoraStudio\NeoraCore\Core\Migration
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core\Migration;

use InvalidArgumentException;
use Throwable;

final class MigrationRunner {

	public const CURRENT_SCHEMA_VERSION = '1.0.0';
	public const VERSION_OPTION_KEY     = 'neora_core_db_version';
	public const APPLIED_OPTION_KEY     = 'neora_core_migrations';
	public const LOCK_TRANSIENT_KEY     = 'neora_core_migration_lock';

	private const LOCK_TTL_SECONDS = 60;

	/** @var array<string, MigrationInterface> */
	private array $migrations = [];

	/**
	 * @param array<int, MigrationInterface> $migrations
	 */
	public function __construct( array $migrations = [] ) {
		foreach ( $migrations as $migration ) {
			$this->add( $migration );
		}
	}

	/**
	 * Register a migration into the runner.
	 *
	 * @throws InvalidArgumentException If ID is empty or duplicated.
	 */
	public function add( MigrationInterface $migration ): void {
		$id = trim( $migration->get_id() );
		if ( '' === $id ) {
			throw new InvalidArgumentException( '[Neora Core] Migration ID cannot be empty.' );
		}

		if ( isset( $this->migrations[ $id ] ) ) {
			throw new InvalidArgumentException(
				sprintf( '[Neora Core] Duplicate migration ID "%s".', $id )
			);
		}

		$this->migrations[ $id ] = $migration;
	}

	/**
	 * @return array<string, MigrationInterface>
	 */
	public function all(): array {
		return $this->migrations;
	}

	public function get_db_version(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$version = get_option( self::VERSION_OPTION_KEY, '' );
		return is_scalar( $version ) ? (string) $version : '';
	}

	/**
	 * @return array<string, int> Map of migration_id => applied_timestamp.
	 */
	public function get_applied_migrations(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}

		$raw = get_option( self::APPLIED_OPTION_KEY, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$applied = [];
		foreach ( $raw as $id => $timestamp ) {
			if ( is_string( $id ) && '' !== $id ) {
				$applied[ $id ] = (int) $timestamp;
			}
		}

		return $applied;
	}

	public function needs_upgrade(): bool {
		return self::CURRENT_SCHEMA_VERSION !== $this->get_db_version();
	}

	/**
	 * Execute any pending migrations safely with transient locking.
	 *
	 * @param bool $force Bypass the schema version fast-path check.
	 * @return array{
	 *     status: string,
	 *     applied: array<int, string>,
	 *     skipped: array<int, string>,
	 *     errors: array<string, string>
	 * }
	 */
	public function run( bool $force = false ): array {
		if ( ! $force && ! $this->needs_upgrade() ) {
			return [
				'status'  => 'up_to_date',
				'applied' => [],
				'skipped' => [],
				'errors'  => [],
			];
		}

		if ( function_exists( 'get_transient' ) && get_transient( self::LOCK_TRANSIENT_KEY ) ) {
			return [
				'status'  => 'locked',
				'applied' => [],
				'skipped' => [],
				'errors'  => [],
			];
		}

		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::LOCK_TRANSIENT_KEY, time(), self::LOCK_TTL_SECONDS );
		}

		$applied_ids = $this->get_applied_migrations();
		$newly_done  = [];
		$skipped     = [];
		$errors      = [];

		try {
			$ordered = array_values( $this->migrations );
			usort(
				$ordered,
				static function ( MigrationInterface $a, MigrationInterface $b ): int {
					$cmp = version_compare( $a->get_version(), $b->get_version() );
					return 0 !== $cmp ? $cmp : strcmp( $a->get_id(), $b->get_id() );
				}
			);

			foreach ( $ordered as $migration ) {
				$id = $migration->get_id();

				if ( isset( $applied_ids[ $id ] ) ) {
					$skipped[] = $id;
					continue;
				}

				try {
					$migration->up();
					$applied_ids[ $id ] = time();
					$newly_done[]       = $id;

					if ( function_exists( 'update_option' ) ) {
						update_option( self::APPLIED_OPTION_KEY, $applied_ids, false );
					}
				} catch ( Throwable $e ) {
					$errors[ $id ] = $e->getMessage();
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[Neora Core Migration] Migration "%s" failed: %s',
							$id,
							$e->getMessage()
						)
					);
					break;
				}
			}

			if ( empty( $errors ) && function_exists( 'update_option' ) ) {
				update_option( self::VERSION_OPTION_KEY, self::CURRENT_SCHEMA_VERSION, true );
			}
		} finally {
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::LOCK_TRANSIENT_KEY );
			}
		}

		return [
			'status'  => empty( $errors ) ? 'completed' : 'partial',
			'applied' => $newly_done,
			'skipped' => $skipped,
			'errors'  => $errors,
		];
	}
}