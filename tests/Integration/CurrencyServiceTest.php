<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use PHPUnit\Framework\TestCase;

final class CurrencyServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->justReturn( [] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_catalog_contains_required_currencies_and_is_filterable(): void {
		$service    = new CurrencyService();
		$currencies = $service->get_currencies();

		foreach ( [ 'IRT', 'IRR', 'USD', 'EUR', 'AED', 'TRY' ] as $code ) {
			$this->assertArrayHasKey( $code, $currencies );
		}

		$this->assertSame( 0, $currencies['IRT']['decimals'] );
		$this->assertSame( 0, $currencies['IRR']['decimals'] );
		$this->assertSame( 2, $currencies['USD']['decimals'] );

		Filters\expectApplied( 'neora_core_currencies' )
			->once()
			->andReturnUsing(
				static function ( array $list ): array {
					$list['GBP'] = [
						'name'            => 'British Pound',
						'symbol'          => '£',
						'decimals'        => 2,
						'symbol_position' => 'before',
					];
					return $list;
				}
			);

		$filtered = $service->get_currencies();
		$this->assertArrayHasKey( 'GBP', $filtered );
		$this->assertSame( '£', $filtered['GBP']['symbol'] );
	}

	public function test_normalize_amount_handles_persian_digits_decimals_and_huge_numbers(): void {
		$this->assertSame( '1500000', CurrencyService::normalize_amount( '۱۵۰۰۰۰۰', 0 ) );
		$this->assertSame( '999999999999999999999999', CurrencyService::normalize_amount( '999999999999999999999999', 0 ) );
		$this->assertSame( '0', CurrencyService::normalize_amount( '۰۰۰', 0 ) );
		$this->assertSame( '', CurrencyService::normalize_amount( '   ', 0 ) );
		$this->assertNull( CurrencyService::normalize_amount( '12.5', 0 ) );
		$this->assertNull( CurrencyService::normalize_amount( 12.5, 0 ) );
		$this->assertNull( CurrencyService::normalize_amount( '-50', 0, false ) );
		$this->assertSame( '-50', CurrencyService::normalize_amount( '-50', 0, true ) );
		$this->assertSame( '0', CurrencyService::normalize_amount( '-0', 0, true ) );

		// Decimal precision (e.g. USD/EUR/AED/TRY).
		$this->assertSame( '150.25', CurrencyService::normalize_amount( '۱۵۰٫۲۵', 2 ) );
		$this->assertSame( '0.5', CurrencyService::normalize_amount( '.5', 2 ) );
		$this->assertSame( '19.99', CurrencyService::normalize_amount( 19.99, 2 ) );
		$this->assertNull( CurrencyService::normalize_amount( '12.345', 2 ) );
		$this->assertNull( CurrencyService::normalize_amount( '12.', 2 ) );
	}

	public function test_format_renders_currencies_numerals_separators_and_negatives(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		$service = new CurrencyService();

		$this->assertSame( '1,500,000 tomans', $service->format( '1500000', 'IRT' ) );
		$this->assertSame(
			'999,999,999,999,999,999,999,999 tomans',
			$service->format( '999999999999999999999999', 'IRT' )
		);
		$this->assertSame(
			'۱٬۵۰۰٬۰۰۰ tomans',
			$service->format(
				'1500000',
				'IRT',
				[
					'numerals'           => 'persian',
					'thousand_separator' => '٬',
				]
			)
		);
		$this->assertSame( '$1,250.50', $service->format( '1250.5', 'USD' ) );
		$this->assertSame( '-$19.90', $service->format( '-19.9', 'USD' ) );
		$this->assertSame( '$100', $service->format( '100', 'USD', [ 'trim_zeros' => true ] ) );
		$this->assertSame( '€99.00', $service->format( '99', 'EUR' ) );
		$this->assertSame( '250.00 AED', $service->format( '250', 'AED' ) );
		$this->assertSame( '₺450.75', $service->format( '450.75', 'TRY' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_format_uses_persian_numerals_automatically_for_persian_locale(): void {
		Functions\when( 'get_option' )->justReturn( [ 'numeral_style' => 'auto' ] );
		Functions\when( 'get_locale' )->justReturn( 'fa_IR' );

		$service = new CurrencyService();
		$this->assertSame( '۱,۵۰۰,۰۰۰ tomans', $service->format( '1500000', 'IRT' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_active_currency_code_syncs_with_woocommerce_and_respects_filter(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_option' )->alias(
			static fn( string $key ): array => []
		);

		$service = new CurrencyService();
		$this->assertSame( 'USD', $service->get_active_currency_code() );

		// Disable sync -> falls back to default_currency.
		Functions\when( 'get_option' )->justReturn(
			[
				'default_currency'          => 'EUR',
				'sync_woocommerce_currency' => false,
			]
		);
		$this->assertSame( 'EUR', $service->get_active_currency_code() );

		// Filter override must validate against catalog.
		Filters\expectApplied( 'neora_core_active_currency' )
			->twice()
			->andReturn( 'AED', 'UNKNOWN' );

		$this->assertSame( 'AED', $service->get_active_currency_code() );
		$this->assertSame( 'EUR', $service->get_active_currency_code() );
	}

	public function test_sanitize_settings_validates_all_fields_and_falls_back_safely(): void {
		$service = new CurrencyService();

		$clean = $service->sanitize_settings(
			[
				'default_currency'          => ' usd ',
				'default_country'           => 'ae',
				'numeral_style'             => 'persian',
				'thousand_separator'        => 'space',
				'decimal_separator'         => 'slash',
				'symbol_position'           => 'before_space',
				'sync_woocommerce_currency' => '',
			]
		);

		$this->assertSame( 'USD', $clean['default_currency'] );
		$this->assertSame( 'AE', $clean['default_country'] );
		$this->assertSame( 'persian', $clean['numeral_style'] );
		$this->assertSame( 'space', $clean['thousand_separator'] );
		$this->assertSame( 'slash', $clean['decimal_separator'] );
		$this->assertSame( 'before_space', $clean['symbol_position'] );
		$this->assertFalse( $clean['sync_woocommerce_currency'] );

		$fallback = $service->sanitize_settings(
			[
				'default_currency'   => 'INVALID',
				'default_country'    => '123',
				'numeral_style'      => 'bad',
				'thousand_separator' => 'bad',
				'decimal_separator'  => 'bad',
				'symbol_position'    => 'bad',
			]
		);

		$expected_fallback                              = CurrencyService::get_default_settings();
		$expected_fallback['sync_woocommerce_currency'] = false;

		$this->assertSame( $expected_fallback, $fallback );
		$this->assertSame( CurrencyService::get_default_settings(), $service->sanitize_settings( 'invalid' ) );
	}

	public function test_bootstrap_registers_currency_service_in_container(): void {
		$app = Bootstrap::create_application();
		$this->assertTrue( $app->container()->has( 'neora.currency' ) );
		$this->assertInstanceOf( CurrencyService::class, $app->container()->get( 'neora.currency' ) );
	}
}