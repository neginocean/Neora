<?php
/**
 * Asynchronous SMS Queue with Exponential Backoff Retry.
 *
 * @package NeoraStudio\NeoraCore\Services\Sms
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Sms;

use NeoraStudio\NeoraCore\Core\HookRegistry;
use WP_Error;

final class SmsQueue {

	public const OPTION_KEY           = 'neora_sms_queue';
	public const LOCK_TRANSIENT       = 'neora_sms_queue_lock';
	public const CRON_HOOK            = 'neora_sms_process_queue';
	public const CRON_JOB_HOOK        = 'neora_sms_process_queue_job';
	public const CRON_SCHEDULE        = 'neora_five_minutes';
	public const DEFAULT_MAX_ATTEMPTS = 3;
	private const MAX_HISTORY_JOBS    = 100;

	private const PERMANENT_ERROR_CODES = [
		'neora_sms_invalid_mobile',
		'neora_sms_empty_args',
		'neora_sms_empty_message',
		'neora_sms_missing_config',
		'neora_sms_unknown_provider',
		'neora_sms_international_unsupported',
	];

	private SmsManager $sms_manager;

	public function __construct( ?SmsManager $sms_manager = null ) {
		$this->sms_manager = $sms_manager ?? new SmsManager();
	}

	public function boot( HookRegistry $hooks ): void {
		$hooks->filter( 'cron_schedules', [ $this, 'register_cron_schedule' ] );
		$hooks->action( self::CRON_HOOK, [ $this, 'process_due_jobs' ] );
		$hooks->action( self::CRON_JOB_HOOK, [ $this, 'process_job' ], 10, 1 );
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = [
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes (Neora SMS Queue)', 'neora-core' ),
			];
		}

		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
			if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 300, self::CRON_SCHEDULE, self::CRON_HOOK );
			}
		}
	}

	/**
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $context
	 */
	public function enqueue_pattern(
		string $recipient,
		array $args,
		?string $pattern_id = null,
		?string $country = null,
		?string $provider_key = null,
		array $context = []
	): string|WP_Error {
		$e164 = PhoneNumber::normalize_e164( $recipient, $country );
		if ( null === $e164 ) {
			return new WP_Error(
				'neora_sms_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		if ( empty( $args ) ) {
			return new WP_Error(
				'neora_sms_empty_args',
				__( 'Pattern arguments cannot be empty.', 'neora-core' )
			);
		}

		return $this->store_job(
			[
				'type'       => 'pattern',
				'recipient'  => $e164,
				'args'       => array_values( array_map( 'strval', $args ) ),
				'pattern_id' => $pattern_id,
				'message'    => '',
				'from'       => null,
				'provider'   => $provider_key,
				'context'    => $context,
			]
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function enqueue_text(
		string $recipient,
		string $message,
		?string $from = null,
		?string $country = null,
		?string $provider_key = null,
		array $context = []
	): string|WP_Error {
		$e164 = PhoneNumber::normalize_e164( $recipient, $country );
		if ( null === $e164 ) {
			return new WP_Error(
				'neora_sms_invalid_mobile',
				__( 'The provided mobile number is invalid.', 'neora-core' )
			);
		}

		$body_text = trim( $message );
		if ( '' === $body_text ) {
			return new WP_Error(
				'neora_sms_empty_message',
				__( 'SMS message cannot be empty.', 'neora-core' )
			);
		}

		return $this->store_job(
			[
				'type'       => 'text',
				'recipient'  => $e164,
				'args'       => [],
				'pattern_id' => null,
				'message'    => $body_text,
				'from'       => $from,
				'provider'   => $provider_key,
				'context'    => $context,
			]
		);
	}

	public function process_job( string $job_id ): bool {
		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			$jobs = $this->load_raw_jobs();
			if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) {
				return false;
			}

			$job = $jobs[ $job_id ];
			if ( in_array( (string) ( $job['status'] ?? '' ), [ 'completed', 'failed' ], true ) ) {
				return false;
			}

			$job['status']   = 'processing';
			$job['attempts'] = (int) ( $job['attempts'] ?? 0 ) + 1;
			$now             = time();

			if ( 'text' === ( $job['type'] ?? 'pattern' ) ) {
				$result = $this->sms_manager->send_text(
					(string) $job['recipient'],
					(string) ( $job['message'] ?? '' ),
					isset( $job['from'] ) ? (string) $job['from'] : null,
					null,
					isset( $job['provider'] ) ? (string) $job['provider'] : null,
					is_array( $job['context'] ?? null ) ? $job['context'] : []
				);
			} else {
				$result = $this->sms_manager->send_pattern(
					(string) $job['recipient'],
					is_array( $job['args'] ?? null ) ? $job['args'] : [],
					isset( $job['pattern_id'] ) ? (string) $job['pattern_id'] : null,
					null,
					isset( $job['provider'] ) ? (string) $job['provider'] : null,
					is_array( $job['context'] ?? null ) ? $job['context'] : []
				);
			}

			$job['updated_at'] = $now;

			if ( true === $result ) {
				$job['status']     = 'completed';
				$job['last_error'] = '';
				$jobs[ $job_id ]   = $job;
				$this->save_jobs( $jobs );
				$this->fire_action( 'neora_sms_job_completed', $job );
				return true;
			}

			$error_code        = $result->get_error_code();
			$job['last_error'] = $result->get_error_message();
			$max_attempts      = max( 1, (int) ( $job['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ) );
			$is_permanent      = in_array( $error_code, self::PERMANENT_ERROR_CODES, true );

			if ( ! $is_permanent && $job['attempts'] < $max_attempts ) {
				$delay                  = $this->calculate_retry_delay( (int) $job['attempts'] );
				$job['status']          = 'pending';
				$job['next_attempt_at'] = $now + $delay;
				$jobs[ $job_id ]        = $job;
				$this->save_jobs( $jobs );
				$this->schedule_single( (int) $job['next_attempt_at'], $job_id );
				$this->fire_action( 'neora_sms_job_retrying', $job, $result );
				return false;
			}

			$job['status']   = 'failed';
			$jobs[ $job_id ] = $job;
			$this->save_jobs( $jobs );
			$this->fire_action( 'neora_sms_job_failed', $job, $result );

			return false;
		} finally {
			$this->release_lock();
		}
	}

	public function process_due_jobs( int $limit = 10 ): int {
		$now       = time();
		$jobs      = $this->load_raw_jobs();
		$processed = 0;

		foreach ( $jobs as $id => $job ) {
			if ( $processed >= $limit ) {
				break;
			}
			if ( ! is_array( $job ) || 'pending' !== ( $job['status'] ?? '' ) ) {
				continue;
			}
			if ( (int) ( $job['next_attempt_at'] ?? 0 ) > $now ) {
				continue;
			}

			$this->process_job( (string) $id );
			++$processed;
		}

		return $processed;
	}

	public function retry_job( string $job_id ): bool {
		$jobs = $this->load_raw_jobs();
		if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) {
			return false;
		}

		$now                              = time();
		$jobs[ $job_id ]['status']        = 'pending';
		$jobs[ $job_id ]['attempts']      = 0;
		$jobs[ $job_id ]['last_error']    = '';
		$jobs[ $job_id ]['next_attempt_at'] = $now;
		$jobs[ $job_id ]['updated_at']    = $now;
		$this->save_jobs( $jobs );

		return $this->process_job( $job_id );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_jobs( ?string $status = null ): array {
		$jobs = [];
		foreach ( $this->load_raw_jobs() as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			if ( null !== $status && ( $job['status'] ?? '' ) !== $status ) {
				continue;
			}
			$jobs[] = $job;
		}

		usort(
			$jobs,
			static fn( array $a, array $b ): int => (int) ( $b['created_at'] ?? 0 ) <=> (int) ( $a['created_at'] ?? 0 )
		);

		return $jobs;
	}

	/**
	 * @return array{pending: int, processing: int, completed: int, failed: int, total: int}
	 */
	public function get_stats(): array {
		$stats = [
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'total'      => 0,
		];

		foreach ( $this->load_raw_jobs() as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$status = (string) ( $job['status'] ?? 'pending' );
			if ( isset( $stats[ $status ] ) ) {
				++$stats[ $status ];
			}
			++$stats['total'];
		}

		return $stats;
	}

	public function clear_completed( bool $include_failed = false ): int {
		$jobs    = $this->load_raw_jobs();
		$removed = 0;

		foreach ( $jobs as $id => $job ) {
			$status = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
			if ( 'completed' === $status || ( $include_failed && 'failed' === $status ) ) {
				unset( $jobs[ $id ] );
				++$removed;
			}
		}

		if ( $removed > 0 ) {
			$this->save_jobs( $jobs );
		}

		return $removed;
	}

	/**
	 * @param array<string, mixed> $base
	 */
	private function store_job( array $base ): string {
		$now       = time();
		$id        = 'sms_' . bin2hex( random_bytes( 8 ) );
		$job       = array_merge(
			$base,
			[
				'id'              => $id,
				'status'          => 'pending',
				'attempts'        => 0,
				'max_attempts'    => $this->get_max_attempts(),
				'next_attempt_at' => $now,
				'last_error'      => '',
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		$jobs        = $this->load_raw_jobs();
		$jobs[ $id ] = $job;
		$this->save_jobs( $jobs );

		$this->schedule_single( $now, $id );
		$this->ensure_scheduled();
		$this->fire_action( 'neora_sms_job_queued', $job );

		return $id;
	}

	private function calculate_retry_delay( int $attempt ): int {
		$delay = (int) ( 60 * ( 2 ** max( 0, $attempt - 1 ) ) );
		if ( function_exists( 'apply_filters' ) ) {
			$delay = (int) apply_filters( 'neora_sms_queue_retry_delay', $delay, $attempt );
		}

		return max( 10, $delay );
	}

	private function get_max_attempts(): int {
		$max = self::DEFAULT_MAX_ATTEMPTS;
		if ( function_exists( 'apply_filters' ) ) {
			$max = (int) apply_filters( 'neora_sms_queue_max_attempts', $max );
		}

		return max( 1, min( 10, $max ) );
	}

	private function schedule_single( int $timestamp, string $job_id ): void {
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $timestamp, self::CRON_JOB_HOOK, [ $job_id ] );
		}
	}

	private function acquire_lock(): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, '1', 60 );
		return true;
	}

	private function release_lock(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function load_raw_jobs(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, [] ) : [];
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $jobs
	 */
	private function save_jobs( array $jobs ): void {
		if ( count( $jobs ) > self::MAX_HISTORY_JOBS ) {
			$active   = [];
			$finished = [];
			foreach ( $jobs as $id => $job ) {
				$status = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
				if ( in_array( $status, [ 'pending', 'processing' ], true ) ) {
					$active[ $id ] = $job;
				} else {
					$finished[ $id ] = $job;
				}
			}
			$keep     = max( 0, self::MAX_HISTORY_JOBS - count( $active ) );
			$finished = $keep > 0 ? array_slice( $finished, -$keep, null, true ) : [];
			$jobs     = $active + $finished;
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $jobs, false );
		}
	}

	private function fire_action( string $hook, mixed ...$args ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( $hook, ...$args );
		}
	}
}