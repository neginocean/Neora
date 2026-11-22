<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Admin\SettingsPage;
use NeoraStudio\NeoraCore\Modules\Auth\AuthModule;
use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			[
				'get_option'          => [],
				'sanitize_text_field' => static fn( string $v ): string => trim( $v ),
				'esc_url_raw'         => static fn( string $v ): string => trim( $v ),
			]
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_neora_is_module_active_returns_false_when_not_booted(): void {
		$this->assertFalse( neora_is_module_active( 'neora/services' ) );
	}

	public function test_neora_get_brand_name_resolves_setting_then_bloginfo_then_default(): void {
		Functions\when( 'get_option' )->justReturn( [ 'brand_name' => 'Studio X' ] );
		$this->assertSame( 'Studio X', neora_get_brand_name() );

		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		$this->assertSame( 'My Site', neora_get_brand_name() );

		Functions\when( 'get_bloginfo' )->justReturn( '' );
		$this->assertSame( 'Neora Core', neora_get_brand_name() );
	}

	public function test_branding_helper_functions_return_logo_color_and_settings(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'brand_name'  => 'Studio X',
				'logo_url'    => 'https://studiox.test/logo.png',
				'brand_color' => '#2563EB',
			]
		);

		$branding = neora_get_branding_settings();
		$this->assertSame( 'Studio X', $branding['brand_name'] );
		$this->assertSame( 'https://studiox.test/logo.png', neora_get_brand_logo_url() );
		$this->assertSame( '#2563eb', neora_get_brand_color() );
	}

	public function test_neora_get_user_mobile_prefers_otp_meta_and_falls_back_to_billing_phone(): void {
		$this->assertSame( '', neora_get_user_mobile( 0 ) );

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key ): string {
				if ( 5 === $uid && AuthModule::META_MOBILE === $key ) {
					return '۰۹۱۲۳۴۵۶۷۸۹';
				}
				if ( 6 === $uid && 'billing_phone' === $key ) {
					return '+989351112233';
				}
				return '';
			}
		);

		$this->assertSame( '+989123456789', neora_get_user_mobile( 5 ) );
		$this->assertSame( '09123456789', neora_get_user_mobile( 5, 'local' ) );
		$this->assertSame( '+989351112233', neora_get_user_mobile( 6 ) );
		$this->assertSame( '', neora_get_user_mobile( 7 ) );
	}

	public function test_neora_get_auth_config_returns_rest_endpoints_and_nonce(): void {
		Functions\when( 'rest_url' )->alias(
			static fn( string $path ): string => 'https://example.test/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-xyz' );

		$config = neora_get_auth_config();

		$this->assertSame( 'https://example.test/wp-json/neora/v1/auth/send-otp', $config['send_otp_url'] );
		$this->assertSame( 'https://example.test/wp-json/neora/v1/auth/verify-otp', $config['verify_otp_url'] );
		$this->assertSame( 'https://example.test/wp-json/neora/v1/auth/countries', $config['countries_url'] );
		$this->assertSame( 'nonce-xyz', $config['rest_nonce'] );
		$this->assertSame( 'IR', $config['default_country'] );
		$this->assertSame( '44', $config['calling_codes']['GB'] );
	}

	public function test_sms_helper_functions_normalize_and_dispatch(): void {
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
		Functions\when( 'get_option' )->justReturn( [ 'sandbox_mode' => true ] );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		$this->assertSame( '+447911123456', neora_normalize_phone_e164( '07911123456', 'GB' ) );
		$this->assertTrue( neora_send_sms( '+447911123456', 'Hello' ) );
		$job_id = neora_queue_sms( '+989123456789', 'Queued' );
		$this->assertIsString( $job_id );
		$this->assertStringStartsWith( 'sms_', $job_id );
	}

	public function test_service_helpers_and_toman_formatting_avoid_overflow(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ): string {
				return match ( $key ) {
					'_neora_service_code'  => 'SEO-01',
					'_neora_service_price' => '1500000',
					default                => '',
				};
			}
		);

		$this->assertSame( 'SEO-01', neora_get_service_code( 10 ) );
		$this->assertSame( '1500000', neora_get_service_price( 10 ) );
		$this->assertSame( '1,500,000', neora_format_price_tomans( '1500000', false ) );
		$this->assertSame( '1,500,000 tomans', neora_format_price_tomans( '۱۵۰۰۰۰۰', true ) );
		$this->assertSame(
			'999,999,999,999,999,999,999,999',
			neora_format_price_tomans( '999999999999999999999999', false )
		);
		$this->assertSame( '', neora_format_price_tomans( '12.5' ) );
	}

	public function test_neora_get_portfolio_meta_returns_expected_keys(): void {
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		$empty = neora_get_portfolio_meta();
		$this->assertSame(
			[
				'client_name'       => '',
				'project_url'       => '',
				'completion_year'   => '',
				'short_description' => '',
			],
			$empty
		);

		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ): string {
				return match ( $key ) {
					'_neora_portfolio_client_name'       => 'Acme',
					'_neora_portfolio_project_url'       => 'https://acme.test',
					'_neora_portfolio_completion_year'   => '2025',
					'_neora_portfolio_short_description' => 'Brand site',
					default                              => '',
				};
			}
		);

		$meta = neora_get_portfolio_meta( 25 );
		$this->assertSame( 'Acme', $meta['client_name'] );
		$this->assertSame( 'https://acme.test', $meta['project_url'] );
		$this->assertSame( '2025', $meta['completion_year'] );
		$this->assertSame( 'Brand site', $meta['short_description'] );
	}

	public function test_currency_helper_functions_work_with_regional_settings(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'default_currency'          => 'USD',
				'sync_woocommerce_currency' => false,
				'numeral_style'             => 'latin',
			]
		);
		Functions\when( 'get_post_meta' )->justReturn( '125.5' );

		$this->assertSame( 'USD', neora_get_currency() );
		$this->assertSame( '$', neora_get_currency_symbol() );
		$this->assertArrayHasKey( 'IRT', neora_get_currencies() );
		$this->assertSame( '$1,250.50', neora_format_price( '1250.5' ) );
		$this->assertSame( '$125.50', neora_get_service_formatted_price( 10 ) );
		$this->assertSame( '1,500,000 tomans', neora_format_price_tomans( '1500000' ) );
	}
}