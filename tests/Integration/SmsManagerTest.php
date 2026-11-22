<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Services\Sms\Providers\MelipayamakProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\TwilioProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\WebhookProvider;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use NeoraStudio\NeoraCore\Services\Sms\SmsQueue;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class SmsManagerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_bootstrap_registers_sms_manager_and_queue_in_container(): void {
		$app = Bootstrap::create_application();
		$this->assertTrue( $app->container()->has( 'neora.sms_manager' ) );
		$this->assertTrue( $app->container()->has( 'neora.sms_queue' ) );
		$this->assertInstanceOf( SmsManager::class, $app->container()->get( 'neora.sms_manager' ) );
		$this->assertInstanceOf( SmsQueue::class, $app->container()->get( 'neora.sms_queue' ) );
	}

	public function test_routes_international_numbers_to_fallback_provider_and_rejects_when_unconfigured(): void {
		$manager = new SmsManager(
			[
				new MelipayamakProvider(),
				new TwilioProvider(),
				new WebhookProvider(),
			]
		);

		$iran = $manager->resolve_provider(
			'+989123456789',
			null,
			[
				'default_provider'       => 'melipayamak',
				'international_provider' => 'twilio',
			]
		);
		$this->assertInstanceOf( MelipayamakProvider::class, $iran );

		$intl_disabled = $manager->resolve_provider(
			'+447911123456',
			null,
			[
				'default_provider'       => 'melipayamak',
				'international_provider' => '',
			]
		);
		$this->assertInstanceOf( WP_Error::class, $intl_disabled );
		$this->assertSame( 'neora_sms_international_unsupported', $intl_disabled->get_error_code() );

		$intl_twilio = $manager->resolve_provider(
			'+447911123456',
			null,
			[
				'default_provider'       => 'melipayamak',
				'international_provider' => 'twilio',
			]
		);
		$this->assertInstanceOf( TwilioProvider::class, $intl_twilio );
	}

	public function test_twilio_provider_dispatches_pattern_and_webhook_provider_posts_json(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode'           => false,
				'default_provider'       => 'melipayamak',
				'international_provider' => 'twilio',
				'twilio_account_sid'     => 'AC123',
				'twilio_auth_token'      => 'secret-token',
				'twilio_from'            => '+15005550006',
				'twilio_otp_template'    => 'Code: {code}',
				'webhook_url'            => 'https://sms.example.test/send',
				'webhook_secret'         => 'bearer-xyz',
			]
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		Functions\expect( 'wp_remote_post' )
			->twice()
			->andReturn( [ 'response' => [ 'code' => 201 ] ], [ 'response' => [ 'code' => 200 ] ] );
		Functions\expect( 'wp_remote_retrieve_response_code' )
			->twice()
			->andReturn( 201, 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'sid' => 'SM123' ] ) );

		$manager = new SmsManager();
		$this->assertTrue( $manager->send_pattern( '07911123456', [ '54321' ], null, 'GB' ) );
		$this->assertTrue( $manager->send_text( '+14155552671', 'Hello', null, null, 'webhook' ) );
	}

	public function test_sandbox_mode_logs_international_sms_without_http_requests(): void {
		Functions\when( 'get_option' )->justReturn( [ 'sandbox_mode' => true ] );
		Functions\expect( 'wp_remote_post' )->never();

		$manager = new SmsManager();
		$this->assertTrue( $manager->send_pattern( '+447911123456', [ '12345' ] ) );
		$this->assertTrue( $manager->send_text( '+14155552671', 'Test sandbox' ) );
	}
}