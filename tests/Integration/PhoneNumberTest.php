<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use NeoraStudio\NeoraCore\Services\Sms\PhoneNumber;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase {

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

	public function test_normalize_e164_handles_iranian_formats_and_rejects_landlines(): void {
		$this->assertSame( '+989123456789', PhoneNumber::normalize_e164( '۰۹۱۲۳۴۵۶۷۸۹' ) );
		$this->assertSame( '+989123456789', PhoneNumber::normalize_e164( '0912 345-6789' ) );
		$this->assertSame( '+989123456789', PhoneNumber::normalize_e164( '+989123456789' ) );
		$this->assertSame( '+989123456789', PhoneNumber::normalize_e164( '00989123456789' ) );
		$this->assertSame( '+989123456789', PhoneNumber::normalize_e164( '+9809123456789' ) );
		$this->assertSame( '+989351112233', PhoneNumber::normalize_e164( '989351112233' ) );

		$this->assertNull( PhoneNumber::normalize_e164( '02188776655' ) );
		$this->assertNull( PhoneNumber::normalize_e164( '+982188776655' ) );
		$this->assertNull( PhoneNumber::normalize_e164( '0912345' ) );
		$this->assertNull( PhoneNumber::normalize_e164( '   ' ) );
	}

	public function test_normalize_e164_supports_international_countries_and_regional_default(): void {
		$this->assertSame( '+447911123456', PhoneNumber::normalize_e164( '07911 123456', 'GB' ) );
		$this->assertSame( '+4915112345678', PhoneNumber::normalize_e164( '0151-12345678', 'DE' ) );
		$this->assertSame( '+14155552671', PhoneNumber::normalize_e164( '(415) 555-2671', 'US' ) );
		$this->assertSame( '+971501234567', PhoneNumber::normalize_e164( '050 123 4567', 'AE' ) );
		$this->assertSame( '+905321234567', PhoneNumber::normalize_e164( '0532 123 4567', 'TR' ) );
		$this->assertSame( '+33612345678', PhoneNumber::normalize_e164( '+33 6 12 34 56 78' ) );
		$this->assertSame( '+447911123456', PhoneNumber::normalize_e164( '00447911123456' ) );

		Functions\when( 'get_option' )->alias(
			static fn( string $key ): array => CurrencyService::OPTION_KEY === $key
				? [ 'default_country' => 'GB' ]
				: []
		);
		$this->assertSame( '+447911123456', PhoneNumber::normalize_e164( '07911123456' ) );
	}

	public function test_helpers_convert_iran_local_detect_country_and_mask(): void {
		$this->assertSame( '09123456789', PhoneNumber::to_iran_local( '+989123456789' ) );
		$this->assertNull( PhoneNumber::to_iran_local( '+447911123456' ) );

		$this->assertSame( 'IR', PhoneNumber::detect_country( '+989123456789' ) );
		$this->assertSame( 'AE', PhoneNumber::detect_country( '+971501234567' ) );
		$this->assertSame( 'GB', PhoneNumber::detect_country( '+447911123456' ) );
		$this->assertNull( PhoneNumber::detect_country( 'invalid' ) );

		$this->assertTrue( PhoneNumber::is_iranian_e164( '+989123456789' ) );
		$this->assertFalse( PhoneNumber::is_iranian_e164( '+447911123456' ) );
		$this->assertSame( '+989*****6789', PhoneNumber::mask( '+989123456789' ) );
	}
}