<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use NeoraStudio\NeoraCore\Services\Sms\SmsProviderInterface;
use NeoraStudio\NeoraCore\Services\Sms\SmsQueue;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class SmsQueueTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var array<string, mixed> */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);

		$this->options    = [];
		$this->transients = [];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $default = false ): mixed => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( string $key ): mixed => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enqueue_and_process_job_completes_successfully_in_sandbox(): void {
		$this->options['neora_sms_settings'] = [ 'sandbox_mode' => true ];

		Actions\expectDone( 'neora_sms_job_queued' )->once();
		Actions\expectDone( 'neora_sms_job_completed' )->once();

		$queue  = new SmsQueue( new SmsManager() );
		$job_id = $queue->enqueue_pattern( '09123456789', [ '1001' ], '77889' );

		$this->assertIsString( $job_id );
		$this->assertSame( 1, $queue->get_stats()['pending'] );

		$this->assertSame( 1, $queue->process_due_jobs() );
		$stats = $queue->get_stats();
		$this->assertSame( 0, $stats['pending'] );
		$this->assertSame( 1, $stats['completed'] );

		$this->assertSame( 1, $queue->clear_completed() );
		$this->assertSame( 0, $queue->get_stats()['total'] );
	}

	public function test_transient_failures_retry_with_backoff_until_max_attempts(): void {
		$this->options['neora_sms_settings'] = [
			'sandbox_mode'     => false,
			'default_provider' => 'failing_mock',
		];

		$failing_provider = new class() implements SmsProviderInterface {
			public function get_key(): string { return 'failing_mock'; }
			public function get_name(): string { return 'Failing Mock'; }
			public function supports_international(): bool { return true; }
			public function supports_pattern(): bool { return true; }
			public function is_configured( array $settings ): bool { return true; }
			public function send_pattern( string $recipient_e164, array $args, ?string $pattern_id = null, array $settings = [], array $context = [] ): bool|WP_Error {
				return new WP_Error( 'neora_sms_http_error', 'Timeout' );
			}
			public function send_text( string $recipient_e164, string $message, ?string $from = null, array $settings = [], array $context = [] ): bool|WP_Error {
				return new WP_Error( 'neora_sms_http_error', 'Timeout' );
			}
		};

		$queue  = new SmsQueue( new SmsManager( [ $failing_provider ] ) );
		$job_id = $queue->enqueue_text( '+447911123456', 'Order shipped' );
		$this->assertIsString( $job_id );

		$this->assertFalse( $queue->process_job( $job_id ) );
		$job = $queue->get_jobs()[0];
		$this->assertSame( 'pending', $job['status'] );
		$this->assertSame( 1, $job['attempts'] );
		$this->assertGreaterThan( time(), $job['next_attempt_at'] );

		$this->assertFalse( $queue->process_job( $job_id ) );
		$this->assertFalse( $queue->process_job( $job_id ) );
		$final = $queue->get_jobs()[0];
		$this->assertSame( 'failed', $final['status'] );
		$this->assertSame( 3, $final['attempts'] );
	}

	public function test_permanent_error_marks_job_failed_without_retries(): void {
		$this->options['neora_sms_settings'] = [
			'sandbox_mode'           => false,
			'default_provider'       => 'melipayamak',
			'international_provider' => '',
		];

		$queue  = new SmsQueue( new SmsManager() );
		$job_id = $queue->enqueue_text( '+447911123456', 'International test' );
		$this->assertIsString( $job_id );

		$this->assertFalse( $queue->process_job( $job_id ) );
		$job = $queue->get_jobs()[0];
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 1, $job['attempts'] );
	}
}