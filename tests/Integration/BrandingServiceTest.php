<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Admin\AdminMenu;
use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Services\Branding\BrandingService;
use PHPUnit\Framework\TestCase;

final class BrandingServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_bootstrap_registers_branding_service_in_container(): void {
		$app = Bootstrap::create_application();
		$this->assertTrue( $app->container()->has( 'neora.branding' ) );
		$this->assertInstanceOf( BrandingService::class, $app->container()->get( 'neora.branding' ) );
	}

	public function test_sanitize_settings_validates_urls_colors_icons_and_booleans(): void {
		$service = new BrandingService();

		$clean = $service->sanitize_settings(
			[
				'brand_name'         => '  Studio Alpha  ',
				'menu_label'         => '  Alpha Core  ',
				'menu_icon'          => 'dashicons-shield',
				'logo_url'           => 'https://example.com/assets/logo.png',
				'brand_color'        => '#09F',
				'dashboard_subtitle' => '  Client Platform  ',
				'settings_subtitle'  => '  Manage Platform  ',
				'admin_footer_text'  => '  Built by Alpha  ',
				'show_version_badge' => '',
				'show_system_info'   => '1',
			]
		);

		$this->assertSame( 'Studio Alpha', $clean['brand_name'] );
		$this->assertSame( 'Alpha Core', $clean['menu_label'] );
		$this->assertSame( 'dashicons-shield', $clean['menu_icon'] );
		$this->assertSame( 'https://example.com/assets/logo.png', $clean['logo_url'] );
		$this->assertSame( '#0099ff', $clean['brand_color'] );
		$this->assertSame( 'Client Platform', $clean['dashboard_subtitle'] );
		$this->assertSame( 'Manage Platform', $clean['settings_subtitle'] );
		$this->assertSame( 'Built by Alpha', $clean['admin_footer_text'] );
		$this->assertFalse( $clean['show_version_badge'] );
		$this->assertTrue( $clean['show_system_info'] );

		$sanitized_unsafe = $service->sanitize_settings(
			[
				'menu_icon'   => 'fa-solid fa-home',
				'logo_url'    => 'javascript:alert(1)',
				'brand_color' => 'not-a-color',
			]
		);

		$this->assertSame( BrandingService::DEFAULT_ICON, $sanitized_unsafe['menu_icon'] );
		$this->assertSame( '', $sanitized_unsafe['logo_url'] );
		$this->assertSame( BrandingService::DEFAULT_COLOR, $sanitized_unsafe['brand_color'] );
		$this->assertSame( BrandingService::get_default_settings(), $service->sanitize_settings( 'invalid' ) );
	}

	public function test_get_settings_applies_filter_and_revalidates_overrides(): void {
		Filters\expectApplied( 'neora_core_branding_settings' )
			->once()
			->andReturn(
				[
					'brand_name'  => 'Agency Brand',
					'brand_color' => '#10B981',
					'logo_url'    => 'javascript:evil()',
					'menu_icon'   => '<script>',
				]
			);

		$settings = ( new BrandingService() )->get_settings();

		$this->assertSame( 'Agency Brand', $settings['brand_name'] );
		$this->assertSame( '#10b981', $settings['brand_color'] );
		$this->assertSame( '', $settings['logo_url'] );
		$this->assertSame( BrandingService::DEFAULT_ICON, $settings['menu_icon'] );
	}

	public function test_css_variables_and_contrast_adapt_to_brand_color(): void {
		$service = new BrandingService();

		$dark_vars = $service->get_css_variables( [ 'brand_color' => '#4f46e5' ] );
		$this->assertSame( '#4f46e5', $dark_vars['--neora-primary'] );
		$this->assertSame( '#ffffff', $dark_vars['--neora-primary-contrast'] );

		$bright_vars = $service->get_css_variables( [ 'brand_color' => '#facc15' ] );
		$this->assertSame( '#facc15', $bright_vars['--neora-primary'] );
		$this->assertSame( '#0f172a', $bright_vars['--neora-primary-contrast'] );

		$inline_css = $service->build_inline_css( [ 'brand_color' => '#10b981' ] );
		$this->assertStringStartsWith( '.neora-admin-wrap{', $inline_css );
		$this->assertStringContainsString( '--neora-primary:#10b981', $inline_css );
	}

	public function test_get_monogram_handles_latin_persian_and_empty_names(): void {
		$service = new BrandingService();

		$this->assertSame( 'A', $service->get_monogram( 'acme studio' ) );
		$this->assertSame( 'ا', $service->get_monogram( 'استودیو نئورا' ) );
		$this->assertSame( 'N', $service->get_monogram( '' ) );
	}

	public function test_admin_menu_filters_footer_text_only_on_neora_screens(): void {
		Functions\when( 'get_option' )->justReturn( [ 'admin_footer_text' => 'Client Platform Footer' ] );
		$menu = new AdminMenu();

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'toplevel_page_neora-core' ] );
		$this->assertSame( 'Client Platform Footer', $menu->filter_admin_footer_text( 'Thank you for creating with WordPress.' ) );

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'edit-post' ] );
		$this->assertSame( 'Thank you for creating with WordPress.', $menu->filter_admin_footer_text( 'Thank you for creating with WordPress.' ) );
	}

	public function test_translation_files_pot_po_and_l10n_php_are_in_full_sync(): void {
		$lang_dir  = dirname( __DIR__, 2 ) . '/languages/';
		$pot_file  = $lang_dir . 'neora-core.pot';
		$po_file   = $lang_dir . 'neora-core-fa_IR.po';
		$l10n_file = $lang_dir . 'neora-core-fa_IR.l10n.php';

		$this->assertFileExists( $pot_file );
		$this->assertFileExists( $po_file );
		$this->assertFileExists( $l10n_file );

		$pot_entries  = $this->parseGettextCatalog( (string) file_get_contents( $pot_file ) );
		$po_entries   = $this->parseGettextCatalog( (string) file_get_contents( $po_file ) );
		$l10n_payload = require $l10n_file;

		$this->assertIsArray( $l10n_payload );
		$this->assertArrayHasKey( 'messages', $l10n_payload );

		$l10n_messages = $l10n_payload['messages'];

		$this->assertSame( array_keys( $pot_entries ), array_keys( $po_entries ), 'POT and PO keys must match 1-to-1.' );
		$this->assertSame( array_keys( $l10n_messages ), array_keys( $po_entries ), 'PO and l10n.php keys must match 1-to-1.' );

		foreach ( $po_entries as $key => $translation ) {
			$this->assertNotSame( '', $translation, "PO translation must not be empty for: {$key}" );
			$this->assertSame( $l10n_messages[ $key ], $translation, "PO and l10n.php translation mismatch for: {$key}" );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function parseGettextCatalog( string $content ): array {
		$entries = [];
		$blocks  = preg_split( "/\r?\n\r?\n/", trim( $content ) ) ?: [];

		foreach ( $blocks as $block ) {
			$msgctxt = null;
			$msgid   = null;
			$msgstr  = '';

			foreach ( preg_split( "/\r?\n/", $block ) ?: [] as $line ) {
				if ( preg_match( '/^msgctxt\s+"(.*)"$/', $line, $m ) ) {
					$msgctxt = stripcslashes( $m[1] );
				} elseif ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
					$msgid = stripcslashes( $m[1] );
				} elseif ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
					$msgstr = stripcslashes( $m[1] );
				}
			}

			if ( null === $msgid || ( '' === $msgid && null === $msgctxt ) ) {
				continue;
			}

			$key             = null !== $msgctxt ? $msgctxt . "\x04" . $msgid : $msgid;
			$entries[ $key ] = $msgstr;
		}

		return $entries;
	}
}