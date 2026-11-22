<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Modules\Multilingual\PolylangModule;
use NeoraStudio\NeoraCore\Modules\Portfolio\PortfolioModule;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class PolylangModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_reports_missing_dependency_when_polylang_is_not_active(): void {
		$module = new PolylangModule();

		if ( PolylangModule::is_polylang_active() ) {
			$this->assertSame( [], $module->get_missing_dependencies() );
		} else {
			$this->assertNotEmpty( $module->get_missing_dependencies() );
		}
	}

	public function test_registers_active_cpts_and_taxonomies_with_polylang(): void {
		Functions\when( 'post_type_exists' )->alias(
			static fn( string $pt ): bool => in_array( $pt, [ ServicesModule::CPT, PortfolioModule::CPT ], true )
		);
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $tax ): bool => in_array( $tax, [ ServicesModule::TAX, PortfolioModule::TAX ], true )
		);

		$module = new PolylangModule();

		$post_types = $module->filter_post_types( [ 'post' => 'post' ] );
		$this->assertArrayHasKey( ServicesModule::CPT, $post_types );
		$this->assertArrayHasKey( PortfolioModule::CPT, $post_types );

		$taxonomies = $module->filter_taxonomies( [ 'category' => 'category' ] );
		$this->assertArrayHasKey( ServicesModule::TAX, $taxonomies );
		$this->assertArrayHasKey( PortfolioModule::TAX, $taxonomies );
	}

	public function test_syncs_structured_neora_metas_without_duplication(): void {
		$module = new PolylangModule();
		$metas  = $module->filter_copy_post_metas( [ '_neora_service_code', '_thumbnail_id' ] );

		$this->assertContains( '_neora_service_code', $metas );
		$this->assertContains( '_neora_service_price', $metas );
		$this->assertContains( '_neora_portfolio_completion_year', $metas );
		$this->assertCount( 1, array_keys( $metas, '_neora_service_code', true ) );
	}

	public function test_switches_and_restores_locale_for_neora_rest_routes(): void {
		Functions\when( 'sanitize_key' )->alias( static fn( string $k ): string => strtolower( trim( $k ) ) );

		Functions\expect( 'switch_to_locale' )
			->once()
			->with( 'fa_IR' )
			->andReturn( true );

		Functions\expect( 'restore_previous_locale' )
			->once()
			->andReturn( true );

		$module  = new PolylangModule();
		$request = new WP_REST_Request( [ 'lang' => 'fa' ], '/neora/v1/auth/send-otp' );

		$module->maybe_switch_rest_locale( null, null, $request );
		$module->maybe_restore_rest_locale( [ 'ok' => true ], null, $request );

		$this->addToAssertionCount( 1 );
	}

	public function test_ignores_locale_switch_for_non_neora_rest_routes(): void {
		Functions\expect( 'switch_to_locale' )->never();

		$module  = new PolylangModule();
		$request = new WP_REST_Request( [ 'lang' => 'fa' ], '/wp/v2/posts' );

		$module->maybe_switch_rest_locale( null, null, $request );
		$this->addToAssertionCount( 1 );
	}

	public function test_filter_login_redirect_uses_polylang_localized_home_url(): void {
		Functions\when( 'sanitize_key' )->alias( static fn( string $k ): string => strtolower( trim( $k ) ) );
		Functions\expect( 'pll_home_url' )
			->once()
			->with( 'fa' )
			->andReturn( 'https://neorastudio.ir/fa/' );

		$module  = new PolylangModule();
		$request = new WP_REST_Request( [ 'lang' => 'fa' ], '/neora/v1/auth/verify-otp' );

		$url = $module->filter_login_redirect( 'https://neorastudio.ir/', 42, $request );
		$this->assertSame( 'https://neorastudio.ir/fa/', $url );
	}

	public function test_nested_rest_locale_switching_restores_at_correct_depth(): void {
		Functions\when( 'sanitize_key' )->alias( static fn( string $k ): string => strtolower( trim( $k ) ) );

		Functions\expect( 'switch_to_locale' )
			->twice()
			->andReturn( true );

		Functions\expect( 'restore_previous_locale' )
			->twice()
			->andReturn( true );

		$module  = new PolylangModule();
		$request = new WP_REST_Request( [ 'lang' => 'fa' ], '/neora/v1/auth/send-otp' );

		$module->maybe_switch_rest_locale( null, null, $request );
		$module->maybe_switch_rest_locale( null, null, $request );
		$module->maybe_restore_rest_locale( [ 'ok' => true ], null, $request );
		$module->maybe_restore_rest_locale( [ 'ok' => true ], null, $request );
		$module->maybe_restore_rest_locale( [ 'ok' => true ], null, $request ); // Extra restore must be ignored.

		$this->addToAssertionCount( 1 );
	}

	public function test_register_dynamic_strings_uses_active_currency_from_currency_service(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Neora Studio' );
		Functions\when( 'get_option' )->justReturn(
			[
				'default_currency'          => 'USD',
				'sync_woocommerce_currency' => false,
			]
		);

		$registered = [];
		Functions\when( 'pll_register_string' )->alias(
			static function ( string $name, string $string, string $group, bool $multiline ) use ( &$registered ): void {
				$registered[ $name ] = $string;
			}
		);

		$module = new PolylangModule();
		$module->register_dynamic_strings();

		$this->assertSame( 'Neora Studio', $registered['site_brand_name'] );
		$this->assertSame( '$', $registered['currency_label'] );
		$this->assertSame( 'tomans', $registered['currency_symbol_irt'] );
		$this->assertSame( 'rials', $registered['currency_symbol_irr'] );
		$this->assertSame( 'AED', $registered['currency_symbol_aed'] );
	}
}