<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Modules\Auth\AuthModule;
use NeoraStudio\NeoraCore\Modules\WooCommerce\WooCommerceBridgeModule;
use PHPUnit\Framework\TestCase;

final class WooCommerceBridgeModuleTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registered_in_bootstrap_and_reports_missing_dependency_when_wc_absent(): void {
		$app = Bootstrap::create_application();
		$this->assertTrue( $app->get_module_manager()->has( WooCommerceBridgeModule::KEY ) );

		$module = new WooCommerceBridgeModule();
		if ( WooCommerceBridgeModule::is_woocommerce_active() ) {
			$this->assertSame( [], $module->get_missing_dependencies() );
		} else {
			$this->assertNotEmpty( $module->get_missing_dependencies() );
		}
	}

	public function test_boot_registers_expected_hooks(): void {
		$module = new WooCommerceBridgeModule();
		$module->boot( new HookRegistry() );

		$this->assertNotFalse( has_filter( 'neora_auth_find_user_id', [ $module, 'find_user_by_billing_phone' ] ) );
		$this->assertNotFalse( has_action( 'neora_auth_user_registered', [ $module, 'sync_billing_phone_from_otp' ] ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_processed', [ $module, 'handle_order_created' ] ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_completed', [ $module, 'handle_order_completed' ] ) );
		$this->assertNotFalse( has_filter( 'neora_core_settings_tabs', [ $module, 'register_settings_tab' ] ) );
	}

	public function test_find_user_by_billing_phone_is_disabled_by_default_and_matches_unique_customer_when_enabled(): void {
		$module = new WooCommerceBridgeModule();

		$this->assertSame( 15, $module->find_user_by_billing_phone( 15, '09123456789' ) );

		// Disabled by default for security.
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'get_users' )->never();
		$this->assertNull( $module->find_user_by_billing_phone( null, '09123456789' ) );

		// Enabled explicitly: must require unique non-privileged match.
		Functions\when( 'get_option' )->justReturn( [ 'allow_unverified_billing_login' => true ] );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn(
			(object) [
				'ID'    => 64,
				'roles' => [ 'customer' ],
			]
		);

		Functions\expect( 'get_users' )
			->twice()
			->with(
				Mockery::on(
					static function ( array $args ): bool {
						$values = $args['meta_query'][0]['value'] ?? [];
						return 2 === ( $args['number'] ?? 0 )
							&& in_array( '09123456789', $values, true )
							&& in_array( '+989123456789', $values, true );
					}
				)
			)
			->andReturn( [ 64 ], [ 64, 65 ] ); // 1 match succeeds, 2 ambiguous matches rejected.

		$this->assertSame( 64, $module->find_user_by_billing_phone( null, '09123456789' ) );
		$this->assertNull( $module->find_user_by_billing_phone( null, '09123456789' ) );
	}

	public function test_sync_billing_phone_from_otp_sets_when_empty_and_preserves_valid_existing(): void {
		Functions\when( 'get_option' )->justReturn( [ 'sync_mobile_checkout' => true ] );

		Functions\expect( 'get_user_meta' )
			->twice()
			->with( 10, 'billing_phone', true )
			->andReturn( '', '09351112233' );

		Functions\expect( 'update_user_meta' )
			->once()
			->with( 10, 'billing_phone', '+989123456789' )
			->andReturn( true );

		$module = new WooCommerceBridgeModule();
		$module->sync_billing_phone_from_otp( 10, '۰۹۱۲۳۴۵۶۷۸۹' );
		$module->sync_billing_phone_from_otp( 10, '09123456789' );

		$this->addToAssertionCount( 1 );
	}

	public function test_sync_mobile_from_checkout_prevents_claiming_another_users_mobile(): void {
		Functions\when( 'get_option' )->justReturn( [ 'sync_mobile_checkout' => true ] );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Functions\expect( 'get_users' )
			->once()
			->with(
				Mockery::on(
					static fn( array $args ): bool =>
						AuthModule::META_MOBILE === $args['meta_key']
						&& '+989123456789' === $args['meta_value']
						&& [ 22 ] === $args['exclude']
				)
			)
			->andReturn( [ 99 ] ); // Already owned by user 99.

		Functions\expect( 'update_user_meta' )->never();

		$module = new WooCommerceBridgeModule();
		$module->sync_mobile_from_checkout( 22, [ 'billing_phone' => '09123456789' ] );

		$this->addToAssertionCount( 1 );
	}

	public function test_prefill_checkout_phone_uses_verified_mobile_for_logged_in_user(): void {
		Functions\when( 'get_option' )->justReturn( [ 'sync_mobile_checkout' => true ] );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 33 );
		Functions\when( 'get_user_meta' )->justReturn( '09120001122' );

		$module = new WooCommerceBridgeModule();

		$this->assertSame( '09120001122', $module->prefill_checkout_phone( '', 'billing_phone' ) );
		$this->assertSame( '09359998877', $module->prefill_checkout_phone( '09359998877', 'billing_phone' ) );
		$this->assertSame( '', $module->prefill_checkout_phone( '', 'billing_email' ) );
	}

	public function test_validate_checkout_phone_adds_notice_when_enabled_and_invalid(): void {
		Functions\when( 'get_option' )->justReturn( [ 'enforce_iran_phone' => true ] );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		Functions\expect( 'wc_add_notice' )
			->once()
			->with( Mockery::type('string'), 'error' );

		$_POST['billing_phone'] = '02188776655';

		$module = new WooCommerceBridgeModule();
		$module->validate_checkout_phone();

		$this->addToAssertionCount( 1 );
	}

	public function test_handle_order_created_sends_pattern_once_in_sandbox_and_marks_meta(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ): array {
				if ( WooCommerceBridgeModule::OPTION_KEY === $key ) {
					return [
						'order_created_pattern' => '77889',
						'async_order_sms'       => true,
					];
				}
				if ( 'neora_sms_queue' === $key ) {
					return [];
				}
				return [
					'sandbox_mode' => true,
					'pattern_code' => '11111',
				];
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		$order = new class() {
			public array $meta = [];
			public function get_billing_phone(): string { return '۰۹۱۲۳۴۵۶۷۸۹'; }
			public function get_order_number(): string { return '1045'; }
			public function get_meta( string $key ): string { return $this->meta[ $key ] ?? ''; }
			public function update_meta_data( string $key, string $val ): void { $this->meta[ $key ] = $val; }
			public function save_meta_data(): void {}
		};

		$module = new WooCommerceBridgeModule();
		$module->handle_order_created( $order );
		$module->handle_order_created( $order ); // Second call must not resend.

		$this->assertArrayHasKey( '_neora_sms_sent_created', $order->meta );
	}

	public function test_sanitize_settings_and_tab_registration(): void {
		$module = new WooCommerceBridgeModule();
		$clean  = $module->sanitize_settings(
			[
				'sync_mobile_checkout'    => '1',
				'enforce_iran_phone'      => '',
				'order_created_pattern'   => ' ۱۲۳۴۵ ',
				'order_completed_pattern' => 'abc678',
			]
		);

		$this->assertTrue( $clean['sync_mobile_checkout'] );
		$this->assertFalse( $clean['enforce_iran_phone'] );
		$this->assertFalse( $clean['enforce_international_phone'] );
		$this->assertFalse( $clean['async_order_sms'] );
		$this->assertSame( '12345', $clean['order_created_pattern'] );
		$this->assertSame( '678', $clean['order_completed_pattern'] );

		$tabs = $module->register_settings_tab( [ 'general' => [ 'title' => 'General' ] ] );
		$this->assertArrayHasKey( 'woocommerce', $tabs );
		$this->assertTrue( is_callable( $tabs['woocommerce']['callback'] ) );
	}
}