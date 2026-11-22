<?php
/**
 * Integration tests for AuthModule REST endpoints and user provisioning.
 *
 * @package NeoraStudio\NeoraCore\Tests\Integration
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey\Actions;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Modules\Auth\AuthModule;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AuthModuleTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);

		Functions\when( 'wp_hash' )->alias(
			static fn( string $data ): string => hash( 'sha256', 'neora_salt_' . $data )
		);
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_auth_module_is_registered_in_bootstrap_and_registers_rest_routes(): void {
		$app = Bootstrap::create_application();
		$this->assertTrue( $app->get_module_manager()->has( AuthModule::KEY ) );

		Functions\expect( 'register_rest_route' )
			->times( 3 )
			->andReturn( true );

		$module = new AuthModule();
		$module->register_rest_routes();
	}

	public function test_handle_request_otp_returns_200_on_success(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode' => true,
				'pattern_code' => '11111',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 12345 );
		Functions\when( 'set_transient' )->justReturn( true );

		$app = Bootstrap::create_application();
		/** @var AuthModule $module */
		$module = $app->get_module_manager()->get( AuthModule::KEY );

		$request  = new WP_REST_Request( [ 'mobile' => '۰۹۱۲۳۴۵۶۷۸۹' ] );
		$response = $module->handle_request_otp( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	public function test_handle_request_otp_returns_429_error_during_cooldown(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => 'existing_hash',
				'attempts'     => 0,
				'requested_at' => time() - 10,
			]
		);

		$module   = new AuthModule();
		$request  = new WP_REST_Request( [ 'mobile' => '09123456789' ] );
		$response = $module->handle_request_otp( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'neora_otp_cooldown', $response->get_error_code() );
		$error_data = $response->get_error_data();
		$this->assertSame( 429, $error_data['status'] );
	}

	public function test_handle_request_otp_enforces_ip_rate_limit(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.25';

		Functions\when( 'get_transient' )->justReturn(
			[
				'count'        => 10,
				'window_start' => time() - 30,
			]
		);

		$module   = new AuthModule();
		$request  = new WP_REST_Request( [ 'mobile' => '09123456789' ] );
		$response = $module->handle_request_otp( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'neora_auth_rate_limited', $response->get_error_code() );
		$error_data = $response->get_error_data();
		$this->assertSame( 429, $error_data['status'] );
	}

	public function test_handle_verify_otp_provisions_new_user_without_exposing_mobile_in_login(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => hash( 'sha256', 'neora_salt_login|+989123456789|67890' ),
				'attempts'     => 0,
				'requested_at' => time() - 15,
			]
		);
		Functions\expect( 'delete_transient' )->once()->andReturn( true );

		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'RandomStrongPass!234' );
		Functions\expect( 'wp_insert_user' )
			->once()
			->with(
				Mockery::on(
					static function ( array $userdata ): bool {
						return str_starts_with( $userdata['user_login'], 'neora_' )
							&& '+989123456789' !== $userdata['user_login']
							&& 'subscriber' === $userdata['role'];
					}
				)
			)
			->andReturn( 42 );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 42, AuthModule::META_MOBILE, '+989123456789' )
			->andReturn( true );

		Functions\expect( 'wp_set_current_user' )->once()->with( 42 );
		Functions\expect( 'wp_set_auth_cookie' )->once()->with( 42, true );
		Functions\when( 'home_url' )->justReturn( 'https://neorastudio.ir/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-rest-nonce' );

		$module  = new AuthModule();
		$request = new WP_REST_Request(
			[
				'mobile' => '09123456789',
				'code'   => '۶۷۸۹۰',
			]
		);

		$response = $module->handle_verify_otp( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 42, $data['user_id'] );
		$this->assertTrue( $data['is_new_user'] );
		$this->assertSame( 'fresh-rest-nonce', $data['rest_nonce'] );
	}

	public function test_handle_verify_otp_logs_in_existing_user_found_by_mobile_meta(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => hash( 'sha256', 'neora_salt_login|+989123456789|11223' ),
				'attempts'     => 0,
				'requested_at' => time() - 10,
			]
		);
		Functions\when( 'get_user_meta' )->justReturn( '+989123456789' );
		Functions\expect( 'delete_transient' )->once()->andReturn( true );

		Functions\when( 'get_users' )->justReturn( [ 77 ] );
		Functions\expect( 'wp_insert_user' )->never();
		Functions\expect( 'wp_set_current_user' )->once()->with( 77 );
		Functions\expect( 'wp_set_auth_cookie' )->once()->with( 77, true );
		Functions\when( 'home_url' )->justReturn( 'https://neorastudio.ir/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'rest-nonce-77' );

		$module  = new AuthModule();
		$request = new WP_REST_Request(
			[
				'mobile' => '09123456789',
				'code'   => '11223',
			]
		);

		$response = $module->handle_verify_otp( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 77, $data['user_id'] );
		$this->assertFalse( $data['is_new_user'] );
	}

	public function test_handle_request_otp_hides_internal_gateway_errors_from_public_response(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode' => false,
				'username'     => 'user',
				'password'     => 'pass',
				'pattern_code' => '11111',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 12345 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				[
					'Value'     => '2',
					'RetStatus' => 0,
				]
			)
		);

		Actions\expectDone( 'neora_auth_otp_send_failed' )->once();

		$module   = new AuthModule();
		$request  = new WP_REST_Request( [ 'mobile' => '09123456789' ] );
		$response = $module->handle_request_otp( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'neora_otp_delivery_failed', $response->get_error_code() );
		$this->assertSame( 502, $response->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'credit', strtolower( $response->get_error_message() ) );
	}

	public function test_handle_verify_otp_matches_external_user_filter_and_fires_wp_login(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => hash( 'sha256', 'neora_salt_login|+989123456789|55443' ),
				'attempts'     => 0,
				'requested_at' => time() - 10,
			]
		);
		Functions\expect( 'delete_transient' )->once()->andReturn( true );

		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, mixed $value, mixed ...$args ): mixed {
				if ( 'neora_auth_find_user_id' === $hook ) {
					return 88;
				}
				return $value;
			}
		);
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 88, AuthModule::META_MOBILE, '+989123456789' )
			->andReturn( true );
		Functions\expect( 'wp_insert_user' )->never();
		Functions\expect( 'wp_set_current_user' )->once()->with( 88 );
		Functions\expect( 'wp_set_auth_cookie' )->once()->with( 88, true );

		$wp_user = (object) [
			'ID'         => 88,
			'user_login' => 'neora_existing88',
		];
		Functions\when( 'get_userdata' )->justReturn( $wp_user );
		Actions\expectDone( 'wp_login' )->once()->with( 'neora_existing88', $wp_user );

		Functions\when( 'home_url' )->justReturn( 'https://neorastudio.ir/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'rest-nonce-88' );

		$module  = new AuthModule();
		$request = new WP_REST_Request(
			[
				'mobile' => '09123456789',
				'code'   => '55443',
			]
		);

		$response = $module->handle_verify_otp( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 88, $response->get_data()['user_id'] );
		$this->assertFalse( $response->get_data()['is_new_user'] );
	}

	public function test_handle_verify_otp_blocks_privileged_role_login(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => hash( 'sha256', 'neora_salt_login|+989123456789|99887' ),
				'attempts'     => 0,
				'requested_at' => time() - 10,
			]
		);
		Functions\when( 'get_user_meta' )->justReturn( '+989123456789' );
		Functions\expect( 'delete_transient' )->once()->andReturn( true );
		Functions\when( 'get_users' )->justReturn( [ 1 ] );
		Functions\when( 'get_userdata' )->justReturn(
			(object) [
				'ID'         => 1,
				'user_login' => 'admin',
				'roles'      => [ 'administrator' ],
			]
		);
		Functions\expect( 'wp_set_auth_cookie' )->never();

		$module  = new AuthModule();
		$request = new WP_REST_Request(
			[
				'mobile' => '09123456789',
				'code'   => '99887',
			]
		);

		$response = $module->handle_verify_otp( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'neora_auth_role_not_allowed', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	public function test_handle_verify_otp_enforces_ip_rate_limit(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.44';

		Functions\when( 'get_transient' )->justReturn(
			[
				'count'        => 15,
				'window_start' => time() - 20,
			]
		);

		$module  = new AuthModule();
		$request = new WP_REST_Request(
			[
				'mobile' => '09123456789',
				'code'   => '12345',
			]
		);

		$response = $module->handle_verify_otp( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'neora_auth_rate_limited', $response->get_error_code() );
		$this->assertSame( 429, $response->get_error_data()['status'] );
	}

	public function test_handle_request_otp_supports_international_country_and_countries_endpoint(): void {
		Functions\when( 'get_option' )->justReturn( [ 'sandbox_mode' => true ] );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$module   = new AuthModule();
		$response = $module->handle_request_otp(
			new WP_REST_Request(
				[
					'mobile'  => '07911123456',
					'country' => 'GB',
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '+447911123456', $response->get_data()['mobile_e164'] );

		$countries = $module->handle_get_countries();
		$this->assertSame( 200, $countries->get_status() );
		$this->assertSame( 'IR', $countries->get_data()['default_country'] );
		$this->assertNotEmpty( $countries->get_data()['countries'] );
	}
}