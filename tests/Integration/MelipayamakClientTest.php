<?php
/**
 * Integration and unit tests for MelipayamakClient.
 *
 * @package NeoraStudio\NeoraCore\Tests\Integration
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NeoraStudio\NeoraCore\Services\Sms\MelipayamakClient;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Verifies mobile normalization, sandbox mode, and Melipayamak HTTP handling.
 */
final class MelipayamakClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();

		
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_normalize_mobile_handles_persian_digits_and_country_code(): void {
		$this->assertSame( '09123456789', MelipayamakClient::normalize_mobile( '۰۹۱۲۳۴۵۶۷۸۹' ) );
		$this->assertSame( '09123456789', MelipayamakClient::normalize_mobile( '+989123456789' ) );
		$this->assertSame( '09351112233', MelipayamakClient::normalize_mobile( '00989351112233' ) );
		$this->assertNull( MelipayamakClient::normalize_mobile( '02188776655' ) );
		$this->assertNull( MelipayamakClient::normalize_mobile( '0912345' ) );
	}

	public function test_send_pattern_returns_true_in_sandbox_mode_without_http_call(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode' => true,
				'pattern_code' => '11111',
			]
		);

		Functions\expect( 'wp_remote_post' )->never();

		$client = new MelipayamakClient();
		$result = $client->send_pattern( '۰۹۱۲۳۴۵۶۷۸۹', [ '482910' ] );

		$this->assertTrue( $result );
	}

	public function test_send_pattern_returns_wp_error_when_credentials_missing_in_production(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode' => false,
				'username'     => '',
				'password'     => '',
				'pattern_code' => '',
			]
		);

		$client = new MelipayamakClient();
		$result = $client->send_pattern( '09123456789', [ '123456' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'neora_sms_missing_config', $result->get_error_code() );
	}

	public function test_send_pattern_succeeds_when_gateway_returns_valid_rec_id(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode' => false,
				'username'     => 'my_user',
				'password'     => 'my_pass',
				'pattern_code' => '54321',
			]
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
				Mockery::on(
					static function ( array $args ): bool {
						$body = json_decode( $args['body'], true );
						return 'my_user' === $body['username']
							&& '09123456789' === $body['to']
							&& '998877' === $body['text']
							&& 54321 === $body['bodyId'];
					}
				)
			)
			->andReturn( [ 'response' => [ 'code' => 200 ] ] );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				[
					'Value'      => '849302849302849302',
					'RetStatus'  => 1,
					'StrRetStatus' => 'Ok',
				]
			)
		);

		$client = new MelipayamakClient();
		$result = $client->send_pattern( '09123456789', [ '998877' ] );

		$this->assertTrue( $result );
	}

	public function test_send_sms_returns_true_in_sandbox_mode(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode'  => true,
				'sender_number' => '5000400',
			]
		);

		Functions\expect( 'wp_remote_post' )->never();

		$client = new MelipayamakClient();
		$result = $client->send_sms( '09123456789', 'Test notification' );

		$this->assertTrue( $result );
	}

	public function test_send_sms_dispatches_standard_endpoint_with_sender_number(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode'  => false,
				'username'      => 'my_user',
				'password'      => 'my_pass',
				'sender_number' => '5000400',
			]
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
				Mockery::on(
					static function ( array $args ): bool {
						$body = json_decode( $args['body'], true );
						return '5000400' === $body['from']
							&& '09123456789' === $body['to']
							&& 'Hello' === $body['text'];
					}
				)
			)
			->andReturn( [ 'response' => [ 'code' => 200 ] ] );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				[
					'Value'     => '1234567890123456',
					'RetStatus' => 1,
				]
			)
		);

		$client = new MelipayamakClient();
		$this->assertTrue( $client->send_sms( '09123456789', 'Hello' ) );
	}

	public function test_send_pattern_maps_known_gateway_rejection_codes(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode' => false,
				'username'     => 'my_user',
				'password'     => 'my_pass',
				'pattern_code' => '54321',
			]
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( [ 'response' => [ 'code' => 200 ] ] );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				[
					'Value'     => '-110',
					'RetStatus' => 1,
				]
			)
		);

		$client = new MelipayamakClient();
		$result = $client->send_pattern( '09123456789', [ '998877' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'neora_sms_gateway_rejected', $result->get_error_code() );
		$this->assertStringContainsString( 'API Key', $result->get_error_message() );
		$this->assertSame( '-110', $result->get_error_data()['gateway_code'] );
	}
}
