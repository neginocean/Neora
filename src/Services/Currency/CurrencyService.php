<?php
/**
 * Multi-Currency Catalog, Regional Settings, and Arbitrary-Precision Formatter.
 *
 * @package NeoraStudio\NeoraCore\Services\Currency
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Currency;

use NeoraStudio\NeoraCore\Modules\Multilingual\PolylangModule;

final class CurrencyService {

	public const OPTION_KEY       = 'neora_core_regional_settings';
	public const DEFAULT_CURRENCY = 'IRT';
	public const DEFAULT_COUNTRY  = 'IR';

	private const THOUSAND_SEPARATORS = [
		'comma'         => ',',
		'persian_comma' => '٬',
		'space'         => ' ',
		'dot'           => '.',
		'none'          => '',
	];

	private const DECIMAL_SEPARATORS = [
		'dot'             => '.',
		'slash'           => '/',
		'persian_decimal' => '٫',
		'comma'           => ',',
	];

	private const SYMBOL_POSITIONS = [
		'default',
		'before',
		'before_space',
		'after',
		'after_space',
	];

	private const DIGIT_MAP_TO_LATIN = [
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	];

	private const DIGIT_MAP_TO_PERSIAN = [
		'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
		'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
	];

	/**
	 * @return array<string, mixed>
	 */
	public static function get_default_settings(): array {
		return [
			'default_currency'          => self::DEFAULT_CURRENCY,
			'default_country'           => self::DEFAULT_COUNTRY,
			'numeral_style'             => 'auto',
			'thousand_separator'        => 'comma',
			'decimal_separator'         => 'dot',
			'symbol_position'           => 'default',
			'sync_woocommerce_currency' => true,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, [] ) : [];
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return array_merge( self::get_default_settings(), $saved );
	}

	/**
	 * @param mixed $input Raw form input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $input ): array {
		$defaults = self::get_default_settings();
		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$currency = isset( $input['default_currency'] ) && is_scalar( $input['default_currency'] )
			? strtoupper( trim( (string) $input['default_currency'] ) )
			: self::DEFAULT_CURRENCY;
		if ( ! $this->is_supported_currency( $currency ) ) {
			$currency = self::DEFAULT_CURRENCY;
		}

		$country = isset( $input['default_country'] ) && is_scalar( $input['default_country'] )
			? strtoupper( trim( (string) $input['default_country'] ) )
			: self::DEFAULT_COUNTRY;
		if ( 1 !== preg_match( '/^[A-Z]{2}$/D', $country ) ) {
			$country = self::DEFAULT_COUNTRY;
		}

		$numeral = isset( $input['numeral_style'] ) && is_scalar( $input['numeral_style'] )
			? strtolower( trim( (string) $input['numeral_style'] ) )
			: 'auto';
		if ( ! in_array( $numeral, [ 'auto', 'latin', 'persian' ], true ) ) {
			$numeral = 'auto';
		}

		$thousand = isset( $input['thousand_separator'] ) && is_scalar( $input['thousand_separator'] )
			? strtolower( trim( (string) $input['thousand_separator'] ) )
			: 'comma';
		if ( ! array_key_exists( $thousand, self::THOUSAND_SEPARATORS ) ) {
			$thousand = 'comma';
		}

		$decimal = isset( $input['decimal_separator'] ) && is_scalar( $input['decimal_separator'] )
			? strtolower( trim( (string) $input['decimal_separator'] ) )
			: 'dot';
		if ( ! array_key_exists( $decimal, self::DECIMAL_SEPARATORS ) ) {
			$decimal = 'dot';
		}

		$position = isset( $input['symbol_position'] ) && is_scalar( $input['symbol_position'] )
			? strtolower( trim( (string) $input['symbol_position'] ) )
			: 'default';
		if ( ! in_array( $position, self::SYMBOL_POSITIONS, true ) ) {
			$position = 'default';
		}

		return [
			'default_currency'          => $currency,
			'default_country'           => $country,
			'numeral_style'             => $numeral,
			'thousand_separator'        => $thousand,
			'decimal_separator'         => $decimal,
			'symbol_position'           => $position,
			'sync_woocommerce_currency' => ! empty( $input['sync_woocommerce_currency'] ),
		];
	}

	/**
	 * @return array<string, array{
	 *     code: string,
	 *     name: string,
	 *     symbol: string,
	 *     decimals: int,
	 *     symbol_position: string,
	 *     thousand_separator: string,
	 *     decimal_separator: string
	 * }>
	 */
	public function get_currencies(): array {
		$currencies = [
			'IRT' => [
				'code'               => 'IRT',
				'name'               => __( 'Iranian Toman', 'neora-core' ),
				'symbol'             => __( 'tomans', 'neora-core' ),
				'decimals'           => 0,
				'symbol_position'    => 'after_space',
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
			],
			'IRR' => [
				'code'               => 'IRR',
				'name'               => __( 'Iranian Rial', 'neora-core' ),
				'symbol'             => __( 'rials', 'neora-core' ),
				'decimals'           => 0,
				'symbol_position'    => 'after_space',
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
			],
			'USD' => [
				'code'               => 'USD',
				'name'               => __( 'US Dollar', 'neora-core' ),
				'symbol'             => '$',
				'decimals'           => 2,
				'symbol_position'    => 'before',
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
			],
			'EUR' => [
				'code'               => 'EUR',
				'name'               => __( 'Euro', 'neora-core' ),
				'symbol'             => '€',
				'decimals'           => 2,
				'symbol_position'    => 'before',
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
			],
			'AED' => [
				'code'               => 'AED',
				'name'               => __( 'UAE Dirham', 'neora-core' ),
				'symbol'             => __( 'AED', 'neora-core' ),
				'decimals'           => 2,
				'symbol_position'    => 'after_space',
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
			],
			'TRY' => [
				'code'               => 'TRY',
				'name'               => __( 'Turkish Lira', 'neora-core' ),
				'symbol'             => '₺',
				'decimals'           => 2,
				'symbol_position'    => 'before',
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
			],
		];

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_currencies', $currencies );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$normalized = [];
				foreach ( $filtered as $code => $item ) {
					if ( ! is_string( $code ) || ! is_array( $item ) ) {
						continue;
					}
					$upper = strtoupper( trim( $code ) );
					if ( '' === $upper ) {
						continue;
					}
					$decimals             = isset( $item['decimals'] ) ? (int) $item['decimals'] : 0;
					$pos                  = (string) ( $item['symbol_position'] ?? 'after_space' );
					$normalized[ $upper ] = [
						'code'               => $upper,
						'name'               => (string) ( $item['name'] ?? $upper ),
						'symbol'             => (string) ( $item['symbol'] ?? $upper ),
						'decimals'           => max( 0, min( 6, $decimals ) ),
						'symbol_position'    => in_array( $pos, self::SYMBOL_POSITIONS, true ) && 'default' !== $pos ? $pos : 'after_space',
						'thousand_separator' => (string) ( $item['thousand_separator'] ?? ',' ),
						'decimal_separator'  => (string) ( $item['decimal_separator'] ?? '.' ),
					];
				}
				if ( ! empty( $normalized ) ) {
					return $normalized;
				}
			}
		}

		return $currencies;
	}

	/**
	 * @return array<string, string>
	 */
	public function get_supported_countries(): array {
		$countries = [
			'IR' => __( 'Iran (IR)', 'neora-core' ),
			'AE' => __( 'United Arab Emirates (AE)', 'neora-core' ),
			'TR' => __( 'Turkey (TR)', 'neora-core' ),
			'DE' => __( 'Germany (DE)', 'neora-core' ),
			'GB' => __( 'United Kingdom (GB)', 'neora-core' ),
			'US' => __( 'United States (US)', 'neora-core' ),
			'CA' => __( 'Canada (CA)', 'neora-core' ),
		];

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_regional_countries', $countries );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				return $filtered;
			}
		}

		return $countries;
	}

	public function is_supported_currency( string $code ): bool {
		$currencies = $this->get_currencies();
		return isset( $currencies[ strtoupper( trim( $code ) ) ] );
	}

	/**
	 * @return array{
	 *     code: string,
	 *     name: string,
	 *     symbol: string,
	 *     decimals: int,
	 *     symbol_position: string,
	 *     thousand_separator: string,
	 *     decimal_separator: string
	 * }
	 */
	public function get_currency( ?string $code = null ): array {
		$currencies = $this->get_currencies();
		$target     = null !== $code && '' !== trim( $code )
			? strtoupper( trim( $code ) )
			: $this->get_active_currency_code();

		if ( isset( $currencies[ $target ] ) ) {
			return $currencies[ $target ];
		}

		if ( isset( $currencies[ self::DEFAULT_CURRENCY ] ) ) {
			return $currencies[ self::DEFAULT_CURRENCY ];
		}

		return (array) reset( $currencies );
	}

	public function get_active_currency_code(): string {
		$settings   = self::get_settings();
		$currencies = $this->get_currencies();
		$resolved   = strtoupper( trim( (string) ( $settings['default_currency'] ?? self::DEFAULT_CURRENCY ) ) );

		if ( ! isset( $currencies[ $resolved ] ) ) {
			$resolved = self::DEFAULT_CURRENCY;
		}

		if ( ! empty( $settings['sync_woocommerce_currency'] ) && function_exists( 'get_woocommerce_currency' ) ) {
			$wc_currency = strtoupper( trim( (string) get_woocommerce_currency() ) );
			if ( isset( $currencies[ $wc_currency ] ) ) {
				$resolved = $wc_currency;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_active_currency', $resolved, $settings );
			if ( is_string( $filtered ) ) {
				$candidate = strtoupper( trim( $filtered ) );
				if ( isset( $currencies[ $candidate ] ) ) {
					$resolved = $candidate;
				}
			}
		}

		return $resolved;
	}

	public function get_currency_symbol( ?string $code = null, bool $translate = true ): string {
		$currency = $this->get_currency( $code );
		$symbol   = $currency['symbol'];

		if ( $translate && PolylangModule::is_polylang_active() ) {
			return PolylangModule::translate_string( $symbol );
		}

		return $symbol;
	}

	public static function convert_digits( string $value, string $target = 'latin' ): string {
		if ( 'persian' === $target ) {
			return strtr( strtr( $value, self::DIGIT_MAP_TO_LATIN ), self::DIGIT_MAP_TO_PERSIAN );
		}

		return strtr( $value, self::DIGIT_MAP_TO_LATIN );
	}

	public function should_use_persian_numerals(): bool {
		$settings = self::get_settings();
		$style    = (string) ( $settings['numeral_style'] ?? 'auto' );

		if ( 'persian' === $style ) {
			return true;
		}

		if ( 'latin' === $style ) {
			return false;
		}

		$locale = PolylangModule::is_polylang_active()
			? PolylangModule::current_language( 'locale' )
			: ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );

		$normalized = strtolower( trim( $locale ) );
		$is_persian = str_starts_with( $normalized, 'fa' );

		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'neora_core_use_persian_numerals', $is_persian, $locale );
		}

		return $is_persian;
	}

	/**
	 * Normalize an amount to an ASCII numeric string without int/float overflow.
	 *
	 * @return string|null Normalized string, '' for blank, or null if invalid.
	 */
	public static function normalize_amount( mixed $value, int $decimals = 0, bool $allow_negative = false ): ?string {
		if ( 0 === $decimals ) {
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return null;
			}
			$raw = (string) $value;
		} else {
			if ( is_float( $value ) ) {
				if ( ! is_finite( $value ) ) {
					return null;
				}
				$raw = rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
			} elseif ( is_string( $value ) || is_int( $value ) ) {
				$raw = (string) $value;
			} else {
				return null;
			}
		}

		$ascii = trim( self::convert_digits( $raw, 'latin' ) );
		if ( '' === $ascii ) {
			return '';
		}

		if ( $decimals > 0 ) {
			$ascii = str_replace( '٫', '.', $ascii );
		}

		$negative = false;
		if ( str_starts_with( $ascii, '-' ) ) {
			if ( ! $allow_negative ) {
				return null;
			}
			$negative = true;
			$ascii    = substr( $ascii, 1 );
		}

		if ( 0 === $decimals ) {
			if ( 1 !== preg_match( '/^[0-9]+$/D', $ascii ) ) {
				return null;
			}
			$digits = ltrim( $ascii, '0' );
			$digits = '' === $digits ? '0' : $digits;
			return ( $negative && '0' !== $digits ) ? '-' . $digits : $digits;
		}

		if ( 1 !== preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D', $ascii ) ) {
			return null;
		}

		$parts    = explode( '.', $ascii, 2 );
		$int_part = ltrim( $parts[0], '0' );
		$int_part = '' === $int_part ? '0' : $int_part;

		if ( ! isset( $parts[1] ) ) {
			return ( $negative && '0' !== $int_part ) ? '-' . $int_part : $int_part;
		}

		if ( strlen( $parts[1] ) > $decimals ) {
			return null;
		}

		$combined = $int_part . '.' . $parts[1];
		$is_zero  = '0' === $int_part && '' === trim( $parts[1], '0' );

		return ( $negative && ! $is_zero ) ? '-' . $combined : $combined;
	}

	/**
	 * Format an amount string/number according to currency and regional settings.
	 *
	 * @param array{
	 *     with_unit?: bool,
	 *     numerals?: string|null,
	 *     thousand_separator?: string|null,
	 *     decimal_separator?: string|null,
	 *     symbol_position?: string|null,
	 *     trim_zeros?: bool,
	 *     translate_symbol?: bool
	 * } $args Formatting overrides.
	 */
	public function format( string|int|float $amount, ?string $currency_code = null, array $args = [] ): string {
		$currency = $this->get_currency( $currency_code );
		$decimals = $currency['decimals'];

		$normalized = self::normalize_amount( $amount, $decimals, true );
		if ( null === $normalized || '' === $normalized ) {
			return '';
		}

		$settings = self::get_settings();
		$negative = str_starts_with( $normalized, '-' );
		$unsigned = $negative ? substr( $normalized, 1 ) : $normalized;

		$parts    = explode( '.', $unsigned, 2 );
		$int_part = $parts[0];
		$frac     = $parts[1] ?? '';

		$thousand_key = (string) ( $settings['thousand_separator'] ?? 'comma' );
		$thousand_sep = $args['thousand_separator']
			?? ( self::THOUSAND_SEPARATORS[ $thousand_key ] ?? $currency['thousand_separator'] );

		if ( '' !== $thousand_sep ) {
			$int_part = preg_replace( '/\B(?=(\d{3})+(?!\d))/', $thousand_sep, $int_part ) ?? $int_part;
		}

		$number = $int_part;
		if ( $decimals > 0 ) {
			$padded     = str_pad( $frac, $decimals, '0', STR_PAD_RIGHT );
			$trim_zeros = ! empty( $args['trim_zeros'] );
			$final_frac = $trim_zeros ? rtrim( $padded, '0' ) : $padded;

			if ( '' !== $final_frac ) {
				$decimal_key = (string) ( $settings['decimal_separator'] ?? 'dot' );
				$decimal_sep = $args['decimal_separator']
					?? ( self::DECIMAL_SEPARATORS[ $decimal_key ] ?? $currency['decimal_separator'] );
				$number     .= $decimal_sep . $final_frac;
			}
		}

		$numerals = $args['numerals'] ?? null;
		$persian  = match ( $numerals ) {
			'persian' => true,
			'latin'   => false,
			default   => $this->should_use_persian_numerals(),
		};

		if ( $persian ) {
			$number = self::convert_digits( $number, 'persian' );
		}

		$sign      = $negative ? '-' : '';
		$with_unit = $args['with_unit'] ?? true;
		if ( ! $with_unit ) {
			return $sign . $number;
		}

		$translate = $args['translate_symbol'] ?? true;
		$symbol    = $this->get_currency_symbol( $currency['code'], $translate );
		$pos_opt   = (string) ( $settings['symbol_position'] ?? 'default' );
		$position  = $args['symbol_position']
			?? ( 'default' !== $pos_opt ? $pos_opt : $currency['symbol_position'] );

		return match ( $position ) {
			'before'       => $sign . $symbol . $number,
			'before_space' => $sign . $symbol . ' ' . $number,
			'after'        => $sign . $number . $symbol,
			default        => $sign . $number . ' ' . $symbol,
		};
	}
}