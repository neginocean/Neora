<?php
/**
 * Integration and unit tests for OtpManager.
 *
 * @package NeoraStudio\NeoraCore\Tests\Integration
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Services\Sms\MelipayamakClient;
use NeoraStudio\NeoraCore\Services\Sms\OtpManager;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Verifies OTP generation, cooldown enforcement, verification, and brute-force lockout.
 */
final class OtpManagerTest extends TestCase {

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
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_request_otp_generates_code_and_stores_transient(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode' => true,
				'pattern_code' => '11111',
			]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		$stored_key    = null;
		$stored_record = null;
		$stored_ttl    = null;

		Functions\when( 'set_transient' )->alias(
			static function ( string $key, mixed $value, int $expiration ) use ( &$stored_key, &$stored_record, &$stored_ttl ): bool {
				$stored_key    = $key;
				$stored_record = $value;
				$stored_ttl    = $expiration;
				return true;
			}
		);

		$manager = new OtpManager( new MelipayamakClient(), static fn(): string => '54321' );
		$result  = $manager->request_otp( '۰۹۱۲۳۴۵۶۷۸۹' );

		$this->assertTrue( $result );
		$this->assertIsArray( $stored_record );
		$this->assertSame( 0, $stored_record['attempts'] );
		$this->assertSame( hash( 'sha256', 'neora_salt_login|+989123456789|54321' ), $stored_record['code_hash'] );
	}

	public function test_request_and_verify_international_otp_with_country_parameter(): void {
		Functions\when( 'get_option' )->justReturn( [ 'sandbox_mode' => true ] );
		$transients = [];
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$transients ): mixed {
				return $transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, mixed $val ) use ( &$transients ): bool {
				$transients[ $key ] = $val;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$transients ): bool {
				unset( $transients[ $key ] );
				return true;
			}
		);

		$manager = new OtpManager( new SmsManager(), static fn(): string => '90909' );
		$this->assertTrue( $manager->request_otp( '07911123456', 'login', 'GB' ) );
		$this->assertTrue( $manager->verify_otp( '+447911123456', '۹۰۹۰۹', 'login' ) );
	}

	public function test_request_otp_enforces_cooldown_when_requested_too_soon(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => 'some_hash',
				'attempts'     => 0,
				'requested_at' => time() - 30, // Only 30 seconds elapsed (< 120s cooldown).
			]
		);

		$manager = new OtpManager( new MelipayamakClient() );
		$result  = $manager->request_otp( '09123456789' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'neora_otp_cooldown', $result->get_error_code() );
	}

	public function test_verify_otp_succeeds_with_persian_digits_and_deletes_transient(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => hash( 'sha256', 'neora_salt_login|+989123456789|48291' ),
				'attempts'     => 1,
				'requested_at' => time() - 15,
			]
		);

		Functions\expect( 'delete_transient' )->once()->andReturn( true );

		$manager = new OtpManager( new MelipayamakClient() );
		$result  = $manager->verify_otp( '09123456789', '۴۸۲۹۱' );

		$this->assertTrue( $result );
	}

	public function test_verify_otp_invalidates_transient_after_max_failed_attempts(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'code_hash'    => hash( 'sha256', 'neora_salt_login|+989123456789|48291' ),
				'attempts'     => 4, // 4 previous failures; this 5th failure must trigger lockout.
				'requested_at' => time() - 20,
			]
		);

		Functions\expect( 'delete_transient' )->once()->andReturn( true );

		$manager = new OtpManager( new MelipayamakClient() );
		$result  = $manager->verify_otp( '09123456789', '99999' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'neora_otp_max_attempts', $result->get_error_code() );
	}

	public function test_verify_otp_rejects_code_issued_for_different_purpose(): void {
		$expected_login_key = 'neora_otp_' . md5( 'login|+989123456789' );

		Functions\when( 'get_transient' )->alias(
			static fn( string $key ): mixed => $expected_login_key === $key
				? [
					'code_hash'    => hash( 'sha256', 'neora_salt_login|+989123456789|48291' ),
					'attempts'     => 0,
					'requested_at' => time() - 10,
				]
				: false
		);

		$manager = new OtpManager( new MelipayamakClient() );
		$result  = $manager->verify_otp( '09123456789', '48291', 'change_mobile' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'neora_otp_expired', $result->get_error_code() );
	}
}
