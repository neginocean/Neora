<?php
/**
 * Neora Core — Polylang Multilingual Integration Module.
 *
 * Bridges CPTs, taxonomies, non-translatable structured metadata, dynamic strings,
 * REST API locale switching, and login redirects with Polylang.
 *
 * Safely reports a missing dependency when Polylang is not active.
 *
 * @package NeoraStudio\NeoraCore\Modules\Multilingual
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Modules\Multilingual;

use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Modules\Auth\AuthModule;
use NeoraStudio\NeoraCore\Modules\Portfolio\PortfolioModule;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use WP_REST_Request;

final class PolylangModule implements ModuleMetadataInterface {

	public const KEY = 'neora/polylang';

	private int $locale_switch_depth = 0;

	public function get_key(): string {
		return self::KEY;
	}

	public function get_name(): string {
		return __( 'Polylang Multilingual Bridge', 'neora-core' );
	}

	public function get_description(): string {
		return __( 'Integrates Services, Portfolio, OTP Auth REST locale, and dynamic strings with Polylang.', 'neora-core' );
	}

	public function is_core(): bool {
		return false;
	}

	public function is_default_enabled(): bool {
		return true;
	}

	public function get_missing_dependencies(): array {
		if ( self::is_polylang_active() ) {
			return [];
		}

		return [
			__( 'Requires the Polylang plugin to be installed and active.', 'neora-core' ),
		];
	}

	public function has_rewrite_rules(): bool {
		return false;
	}

	public function register( Container $container ): void {}

	/**
	 * Note: pll_get_post_types / pll_get_taxonomies are registered directly during
	 * plugins_loaded per Polylang's early-hook requirement.
	 */
	public function boot( HookRegistry $hooks ): void {
		$hooks->filter( 'pll_get_post_types', [ $this, 'filter_post_types' ], 10, 2 );
		$hooks->filter( 'pll_get_taxonomies', [ $this, 'filter_taxonomies' ], 10, 2 );
		$hooks->filter( 'pll_copy_post_metas', [ $this, 'filter_copy_post_metas' ], 10, 2 );
		$hooks->action( 'init', [ $this, 'register_dynamic_strings' ], 20 );

		// Language-aware REST endpoints & OTP login redirect.
		$hooks->filter( 'rest_pre_dispatch', [ $this, 'maybe_switch_rest_locale' ], 10, 3 );
		$hooks->filter( 'rest_post_dispatch', [ $this, 'maybe_restore_rest_locale' ], 10, 3 );
		$hooks->filter( 'neora_auth_login_redirect', [ $this, 'filter_login_redirect' ], 10, 3 );
	}

	public static function is_polylang_active(): bool {
		return function_exists( 'pll_current_language' ) || defined( 'POLYLANG_VERSION' );
	}

	/**
	 * Translate a registered dynamic string via Polylang when available.
	 */
	public static function translate_string( string $text ): string {
		if ( function_exists( 'pll__' ) ) {
			return (string) pll__( $text );
		}

		return $text;
	}

	/**
	 * Return current active language slug or WordPress locale fallback.
	 */
	public static function current_language( string $field = 'slug' ): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( $field );
			if ( is_string( $lang ) && '' !== $lang ) {
				return $lang;
			}
		}

		return function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
	}

	/**
	 * Register active Neora Core CPTs with Polylang.
	 *
	 * @param array<string, string> $post_types
	 * @return array<string, string>
	 */
	public function filter_post_types( array $post_types, bool $is_settings = false ): array {
		$app     = Bootstrap::app();
		$manager = $app?->module_manager();

		$services_active  = $manager ? $manager->is_active( ServicesModule::KEY ) : post_type_exists( ServicesModule::CPT );
		$portfolio_active = $manager ? $manager->is_active( PortfolioModule::KEY ) : post_type_exists( PortfolioModule::CPT );

		if ( $services_active ) {
			$post_types[ ServicesModule::CPT ] = ServicesModule::CPT;
		}

		if ( $portfolio_active ) {
			$post_types[ PortfolioModule::CPT ] = PortfolioModule::CPT;
		}

		return $post_types;
	}

	/**
	 * Register active Neora Core taxonomies with Polylang.
	 *
	 * @param array<string, string> $taxonomies
	 * @return array<string, string>
	 */
	public function filter_taxonomies( array $taxonomies, bool $is_settings = false ): array {
		$app     = Bootstrap::app();
		$manager = $app?->module_manager();

		$services_active  = $manager ? $manager->is_active( ServicesModule::KEY ) : taxonomy_exists( ServicesModule::TAX );
		$portfolio_active = $manager ? $manager->is_active( PortfolioModule::KEY ) : taxonomy_exists( PortfolioModule::TAX );

		if ( $services_active ) {
			$taxonomies[ ServicesModule::TAX ] = ServicesModule::TAX;
		}

		if ( $portfolio_active ) {
			$taxonomies[ PortfolioModule::TAX ] = PortfolioModule::TAX;
		}

		return $taxonomies;
	}

	/**
	 * Sync structured non-translatable meta fields across Polylang post translations.
	 *
	 * @param array<int, string> $metas
	 * @return array<int, string>
	 */
	public function filter_copy_post_metas( array $metas, bool $sync = false ): array {
		$neora_metas = [
			'_neora_service_code',
			'_neora_service_price',
			'_neora_portfolio_client_name',
			'_neora_portfolio_project_url',
			'_neora_portfolio_completion_year',
		];

		$filtered = function_exists( 'apply_filters' )
			? apply_filters( 'neora_polylang_copy_metas', $neora_metas, $sync )
			: $neora_metas;

		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $meta_key ) {
				if ( is_string( $meta_key ) && '' !== $meta_key && ! in_array( $meta_key, $metas, true ) ) {
					$metas[] = $meta_key;
				}
			}
		}

		return $metas;
	}

	/**
	 * Register dynamic user-configurable strings in Polylang's Strings Translations table.
	 */
	public function register_dynamic_strings(): void {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		$app = Bootstrap::app();
		/** @var CurrencyService $currency_service */
		$currency_service = ( $app && $app->container()->has( 'neora.currency' ) )
			? $app->container()->get( 'neora.currency' )
			: new CurrencyService();

		$active_currency = $currency_service->get_currency();

		$strings = [
			'site_brand_name' => [
				'value'     => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
				'multiline' => false,
			],
			'currency_label'  => [
				'value'     => $active_currency['symbol'],
				'multiline' => false,
			],
		];

		foreach ( [ 'IRT', 'IRR', 'AED' ] as $code ) {
			$item = $currency_service->get_currency( $code );
			if ( '' !== $item['symbol'] ) {
				$strings[ 'currency_symbol_' . strtolower( $code ) ] = [
					'value'     => $item['symbol'],
					'multiline' => false,
				];
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$strings = apply_filters( 'neora_polylang_strings', $strings );
		}

		if ( ! is_array( $strings ) ) {
			return;
		}

		foreach ( $strings as $name => $config ) {
			if ( is_string( $config ) && '' !== $config ) {
				pll_register_string( (string) $name, $config, 'Neora Core', false );
				continue;
			}

			if ( is_array( $config ) && ! empty( $config['value'] ) && is_string( $config['value'] ) ) {
				pll_register_string(
					(string) $name,
					$config['value'],
					'Neora Core',
					! empty( $config['multiline'] )
				);
			}
		}
	}

	/**
	 * Switch WordPress locale for /neora/v1/ REST requests based on request lang or Polylang.
	 */
	public function maybe_switch_rest_locale( mixed $result, mixed $server, mixed $request ): mixed {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( '' === $route || ! str_starts_with( $route, '/' . AuthModule::REST_NAMESPACE . '/' ) ) {
			return $result;
		}

		$lang_slug = $this->extract_request_language( $request );
		if ( '' === $lang_slug ) {
			return $result;
		}

		$locale = $this->resolve_locale_for_slug( $lang_slug );
		if ( '' !== $locale && function_exists( 'switch_to_locale' ) ) {
			if ( switch_to_locale( $locale ) ) {
				++$this->locale_switch_depth;
			}
		}

		return $result;
	}

	/**
	 * Restore original locale after REST dispatch finishes.
	 */
	public function maybe_restore_rest_locale( mixed $response, mixed $server, mixed $request ): mixed {
		if ( $this->locale_switch_depth > 0 && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
			--$this->locale_switch_depth;
		}

		return $response;
	}

	/**
	 * Redirect authenticated users to the home URL of the active Polylang language.
	 */
	public function filter_login_redirect( string $url, int $user_id, mixed $request = null ): string {
		if ( ! function_exists( 'pll_home_url' ) ) {
			return $url;
		}

		$lang_slug = $request instanceof WP_REST_Request
			? $this->extract_request_language( $request )
			: '';

		if ( '' === $lang_slug && function_exists( 'pll_current_language' ) ) {
			$current   = pll_current_language( 'slug' );
			$lang_slug = is_string( $current ) ? $current : '';
		}

		if ( '' === $lang_slug ) {
			return $url;
		}

		$localized = pll_home_url( $lang_slug );

		return is_string( $localized ) && '' !== $localized ? $localized : $url;
	}

	private function extract_request_language( WP_REST_Request $request ): string {
		$param = $request->get_param( 'lang' );
		if ( is_string( $param ) && '' !== trim( $param ) ) {
			return sanitize_key( $param );
		}

		if ( method_exists( $request, 'get_header' ) ) {
			$header = $request->get_header( 'X-Neora-Lang' );
			if ( is_string( $header ) && '' !== trim( $header ) ) {
				return sanitize_key( $header );
			}
		}

		if ( function_exists( 'pll_current_language' ) ) {
			$current = pll_current_language( 'slug' );
			if ( is_string( $current ) && '' !== $current ) {
				return $current;
			}
		}

		return '';
	}

	private function resolve_locale_for_slug( string $slug ): string {
		$normalized = strtolower( trim( $slug ) );

		if ( function_exists( 'pll_languages_list' ) ) {
			$languages = pll_languages_list( [ 'fields' => '' ] );
			if ( is_array( $languages ) ) {
				foreach ( $languages as $lang ) {
					if (
						is_object( $lang )
						&& isset( $lang->slug, $lang->locale )
						&& strtolower( (string) $lang->slug ) === $normalized
					) {
						return (string) $lang->locale;
					}
				}
			}
		}

		$defaults = [
			'fa'    => 'fa_IR',
			'fa_ir' => 'fa_IR',
			'en'    => 'en_US',
			'en_us' => 'en_US',
		];

		$map = function_exists( 'apply_filters' )
			? apply_filters( 'neora_polylang_locale_map', $defaults )
			: $defaults;

		return is_array( $map ) && isset( $map[ $normalized ] ) ? (string) $map[ $normalized ] : '';
	}
}