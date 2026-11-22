<?php
/**
 * Integration tests for SettingsPage module.
 *
 * @package NeoraStudio\NeoraCore\Tests\Integration
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Admin\SettingsPage;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies SettingsPage hook wiring and sanitization logic.
 */
final class SettingsPageIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_module_key_is_correct(): void {
		$module = new SettingsPage();
		$this->assertSame( 'neora/settings-page', $module->get_key() );
	}

	public function test_boot_registers_admin_menu_and_admin_init_hooks(): void {
		$module = new SettingsPage();
		$hooks  = new HookRegistry();

		$module->boot( $hooks );

		$this->assertNotFalse( has_action( 'admin_menu', [ $module, 'register_submenu' ] ) );
		$this->assertNotFalse( has_action( 'admin_init', [ $module, 'register_settings' ] ) );
		$this->assertNotFalse( has_action( 'wp_ajax_neora_sms_queue_action', [ $module, 'handle_ajax_queue_action' ] ) );
	}

	public function test_sanitize_preserves_existing_password_when_submitted_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'sandbox_mode'  => true,
				'username'      => '09120000000',
				'password'      => 'secret-api-key',
				'sender_number' => '50001',
				'pattern_code'  => '12345',
			]
		);

		$module = new SettingsPage();
		$result = $module->sanitize_settings(
			[
				'sandbox_mode'  => '1',
				'username'      => ' 09121111111 ',
				'password'      => '   ', // Empty password submitted.
				'sender_number' => '50002',
				'pattern_code'  => '۹۸۷۶۵', // Persian digits.
			]
		);

		$this->assertTrue( $result['sandbox_mode'] );
		$this->assertSame( '09121111111', $result['username'] );
		$this->assertSame( 'secret-api-key', $result['password'] );
		$this->assertSame( '50002', $result['sender_number'] );
		$this->assertSame( '98765', $result['pattern_code'] );
	}

	public function test_sanitize_updates_password_when_new_value_provided(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'password' => 'old-secret',
			]
		);

		$module = new SettingsPage();
		$result = $module->sanitize_settings(
			[
				'password' => 'new-secret-key',
			]
		);

		$this->assertFalse( $result['sandbox_mode'] );
		$this->assertSame( 'new-secret-key', $result['password'] );
	}
	public function test_get_tabs_is_extensible_via_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, array $tabs ): array {
				if ( 'neora_core_settings_tabs' === $hook ) {
					$tabs['woocommerce'] = [ 'title' => 'WooCommerce' ];
				}
				return $tabs;
			}
		);

		$tabs = SettingsPage::get_tabs();

		$this->assertArrayHasKey( 'general', $tabs );
		$this->assertArrayHasKey( 'sms', $tabs );
		$this->assertArrayHasKey( 'woocommerce', $tabs );
	}

	public function test_sanitize_general_settings_trims_and_sanitizes_labels(): void {
		$module = new SettingsPage();
		$result = $module->sanitize_general_settings(
			[
				'brand_name' => '  Client Studio  ',
				'menu_label' => '  Studio Core  ',
			]
		);

		$this->assertSame( 'Client Studio', $result['brand_name'] );
		$this->assertSame( 'Studio Core', $result['menu_label'] );
	}

	public function test_sanitize_general_settings_supports_white_label_v2_fields(): void {
		$module = new SettingsPage();
		$result = $module->sanitize_general_settings(
			[
				'brand_name'         => 'Client Studio',
				'menu_label'         => 'Client Core',
				'menu_icon'          => 'dashicons-building',
				'logo_url'           => 'https://client.test/logo.svg',
				'brand_color'        => '#0EA5E9',
				'dashboard_subtitle' => '  Custom Dashboard Subtitle  ',
				'settings_subtitle'  => '  Custom Settings Subtitle  ',
				'admin_footer_text'  => '  Powered by Client  ',
				'show_version_badge' => '',
				'show_system_info'   => '1',
			]
		);

		$this->assertSame( 'dashicons-building', $result['menu_icon'] );
		$this->assertSame( 'https://client.test/logo.svg', $result['logo_url'] );
		$this->assertSame( '#0ea5e9', $result['brand_color'] );
		$this->assertSame( 'Custom Dashboard Subtitle', $result['dashboard_subtitle'] );
		$this->assertSame( 'Custom Settings Subtitle', $result['settings_subtitle'] );
		$this->assertSame( 'Powered by Client', $result['admin_footer_text'] );
		$this->assertFalse( $result['show_version_badge'] );
		$this->assertTrue( $result['show_system_info'] );
	}

	public function test_regional_tab_and_sanitize_regional_settings_work_cleanly(): void {
		$tabs = SettingsPage::get_tabs();
		$this->assertArrayHasKey( 'regional', $tabs );

		$module = new SettingsPage();
		$result = $module->sanitize_regional_settings(
			[
				'default_currency'          => 'EUR',
				'default_country'           => 'de',
				'numeral_style'             => 'latin',
				'thousand_separator'        => 'dot',
				'decimal_separator'         => 'comma',
				'symbol_position'           => 'after_space',
				'sync_woocommerce_currency' => '1',
			]
		);

		$this->assertSame( 'EUR', $result['default_currency'] );
		$this->assertSame( 'DE', $result['default_country'] );
		$this->assertSame( 'latin', $result['numeral_style'] );
		$this->assertSame( 'dot', $result['thousand_separator'] );
		$this->assertSame( 'comma', $result['decimal_separator'] );
		$this->assertSame( 'after_space', $result['symbol_position'] );
		$this->assertTrue( $result['sync_woocommerce_currency'] );
	}

	public function test_sanitize_settings_supports_multi_provider_and_international_secrets(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'twilio_auth_token' => 'existing-twilio-token',
				'webhook_secret'    => 'existing-webhook-secret',
			]
		);

		$module = new SettingsPage();
		$result = $module->sanitize_settings(
			[
				'sandbox_mode'           => '1',
				'default_provider'       => 'Twilio',
				'international_provider' => 'Webhook',
				'async_queue_enabled'    => '1',
				'twilio_account_sid'     => '  AC12345  ',
				'twilio_auth_token'      => '',
				'twilio_from'            => ' +15005550006 ',
				'twilio_otp_template'    => 'Code: {code}',
				'webhook_url'            => 'https://sms.example.test/hook',
				'webhook_secret'         => 'new-webhook-token',
			]
		);

		$this->assertSame( 'twilio', $result['default_provider'] );
		$this->assertSame( 'webhook', $result['international_provider'] );
		$this->assertTrue( $result['async_queue_enabled'] );
		$this->assertSame( 'AC12345', $result['twilio_account_sid'] );
		$this->assertSame( 'existing-twilio-token', $result['twilio_auth_token'] );
		$this->assertSame( '+15005550006', $result['twilio_from'] );
		$this->assertSame( 'https://sms.example.test/hook', $result['webhook_url'] );
		$this->assertSame( 'new-webhook-token', $result['webhook_secret'] );

		$invalid_url = $module->sanitize_settings(
			[
				'webhook_url' => 'javascript:alert(1)',
			]
		);
		$this->assertSame( '', $invalid_url['webhook_url'] );
	}
}
