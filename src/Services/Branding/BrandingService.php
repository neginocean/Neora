<?php
/**
 * Neora Core — White-Label v2 Branding Service.
 *
 * Centralizes client branding options, safe color/URL sanitization,
 * dynamic CSS custom properties, and admin presentation helpers.
 *
 * @package NeoraStudio\NeoraCore\Services\Branding
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Services\Branding;

final class BrandingService {

	public const OPTION_KEY    = 'neora_core_general_settings';
	public const DEFAULT_COLOR = '#4f46e5';
	public const DEFAULT_ICON  = 'dashicons-layout';

	/**
	 * Return default white-label branding settings.
	 *
	 * @return array<string, string|bool>
	 */
	public static function get_default_settings(): array {
		return [
			'brand_name'         => '',
			'menu_label'         => '',
			'menu_icon'          => self::DEFAULT_ICON,
			'logo_url'           => '',
			'brand_color'        => self::DEFAULT_COLOR,
			'dashboard_subtitle' => '',
			'settings_subtitle'  => '',
			'admin_footer_text'  => '',
			'show_version_badge' => true,
			'show_system_info'   => true,
		];
	}

	/**
	 * Return available Dashicons choices for the top-level admin menu.
	 *
	 * @return array<string, string>
	 */
	public static function get_menu_icons(): array {
		$icons = [
			'dashicons-layout'        => __( 'Layout (Default)', 'neora-core' ),
			'dashicons-admin-generic' => __( 'Gear / Platform', 'neora-core' ),
			'dashicons-shield'        => __( 'Shield', 'neora-core' ),
			'dashicons-store'         => __( 'Store / Commerce', 'neora-core' ),
			'dashicons-portfolio'     => __( 'Portfolio / Briefcase', 'neora-core' ),
			'dashicons-building'      => __( 'Agency / Building', 'neora-core' ),
			'dashicons-star-filled'   => __( 'Star', 'neora-core' ),
			'dashicons-megaphone'     => __( 'Megaphone', 'neora-core' ),
		];

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_branding_menu_icons', $icons );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$valid = [];
				foreach ( $filtered as $class => $label ) {
					$sanitized_class = self::sanitize_menu_icon( $class );
					if ( is_string( $class ) && trim( $class ) === $sanitized_class ) {
						$valid[ $sanitized_class ] = (string) $label;
					}
				}
				if ( ! empty( $valid ) ) {
					return $valid;
				}
			}
		}

		return $icons;
	}

	/**
	 * Return normalized white-label settings merged with defaults and filter overrides.
	 *
	 * @return array<string, string|bool>
	 */
	public function get_settings(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, [] ) : [];
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$merged                       = array_merge( self::get_default_settings(), $saved );
		$merged['brand_name']         = self::trim_string( $merged['brand_name'] );
		$merged['menu_label']         = self::trim_string( $merged['menu_label'] );
		$merged['menu_icon']          = self::sanitize_menu_icon( $merged['menu_icon'] );
		$merged['logo_url']           = self::validate_http_url( $merged['logo_url'] );
		$merged['brand_color']        = self::sanitize_hex_color( $merged['brand_color'] ) ?: self::DEFAULT_COLOR;
		$merged['dashboard_subtitle'] = self::trim_string( $merged['dashboard_subtitle'] );
		$merged['settings_subtitle']  = self::trim_string( $merged['settings_subtitle'] );
		$merged['admin_footer_text']  = self::trim_string( $merged['admin_footer_text'] );
		$merged['show_version_badge'] = (bool) $merged['show_version_badge'];
		$merged['show_system_info']   = (bool) $merged['show_system_info'];

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'neora_core_branding_settings', $merged );
			if ( is_array( $filtered ) ) {
				$merged                       = array_merge( $merged, $filtered );
				$merged['menu_icon']          = self::sanitize_menu_icon( $merged['menu_icon'] );
				$merged['logo_url']           = self::validate_http_url( $merged['logo_url'] );
				$merged['brand_color']        = self::sanitize_hex_color( $merged['brand_color'] ) ?: self::DEFAULT_COLOR;
				$merged['show_version_badge'] = (bool) $merged['show_version_badge'];
				$merged['show_system_info']   = (bool) $merged['show_system_info'];
			}
		}

		return $merged;
	}

	/**
	 * Sanitize submitted branding form values.
	 *
	 * @param mixed $input Raw settings input.
	 * @return array<string, string|bool>
	 */
	public function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_default_settings();
		}

		return [
			'brand_name'         => self::sanitize_text( $input['brand_name'] ?? '' ),
			'menu_label'         => self::sanitize_text( $input['menu_label'] ?? '' ),
			'menu_icon'          => self::sanitize_menu_icon( $input['menu_icon'] ?? self::DEFAULT_ICON ),
			'logo_url'           => self::sanitize_logo_url( $input['logo_url'] ?? '' ),
			'brand_color'        => self::sanitize_hex_color( $input['brand_color'] ?? '' ) ?: self::DEFAULT_COLOR,
			'dashboard_subtitle' => self::sanitize_text( $input['dashboard_subtitle'] ?? '' ),
			'settings_subtitle'  => self::sanitize_text( $input['settings_subtitle'] ?? '' ),
			'admin_footer_text'  => self::sanitize_text( $input['admin_footer_text'] ?? '' ),
			'show_version_badge' => ! empty( $input['show_version_badge'] ),
			'show_system_info'   => ! empty( $input['show_system_info'] ),
		];
	}

	public function get_display_brand_name(): string {
		$settings = $this->get_settings();
		$name     = trim( (string) $settings['brand_name'] );

		return '' !== $name ? $name : __( 'Neora Core', 'neora-core' );
	}

	public function get_menu_label(): string {
		$settings = $this->get_settings();
		$label    = trim( (string) $settings['menu_label'] );

		return '' !== $label ? $label : __( 'Neora Core', 'neora-core' );
	}

	public function get_dashboard_subtitle(): string {
		$settings = $this->get_settings();
		$subtitle = trim( (string) $settings['dashboard_subtitle'] );

		return '' !== $subtitle
			? $subtitle
			: __( 'Modular WordPress platform foundation & module manager.', 'neora-core' );
	}

	public function get_settings_subtitle(): string {
		$settings = $this->get_settings();
		$subtitle = trim( (string) $settings['settings_subtitle'] );

		return '' !== $subtitle
			? $subtitle
			: __( 'Manage branding, SMS gateway, and active module configurations.', 'neora-core' );
	}

	public function get_monogram( ?string $brand_name = null ): string {
		$name = trim( $brand_name ?? $this->get_display_brand_name() );
		if ( '' === $name ) {
			return 'N';
		}

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( mb_substr( $name, 0, 1, 'UTF-8' ), 'UTF-8' );
		}

		return strtoupper( substr( $name, 0, 1 ) );
	}

	/**
	 * Compute CSS custom properties derived from the configured brand color.
	 *
	 * @param array<string, mixed>|null $settings Optional settings override.
	 * @return array<string, string>
	 */
	public function get_css_variables( ?array $settings = null ): array {
		$resolved = $settings ?? $this->get_settings();
		$primary  = self::sanitize_hex_color( $resolved['brand_color'] ?? '' ) ?: self::DEFAULT_COLOR;

		return [
			'--neora-primary'          => $primary,
			'--neora-primary-hover'    => self::mix_hex( $primary, '#000000', 0.14 ),
			'--neora-primary-soft'     => self::mix_hex( $primary, '#ffffff', 0.90 ),
			'--neora-primary-border'   => self::mix_hex( $primary, '#ffffff', 0.72 ),
			'--neora-primary-contrast' => self::get_contrast_color( $primary ),
		];
	}

	/**
	 * Build scoped inline CSS rules for Neora admin screens.
	 *
	 * @param array<string, mixed>|null $settings Optional settings override.
	 */
	public function build_inline_css( ?array $settings = null ): string {
		$declarations = [];
		foreach ( $this->get_css_variables( $settings ) as $prop => $value ) {
			$declarations[] = $prop . ':' . $value;
		}

		return '.neora-admin-wrap{' . implode( ';', $declarations ) . ';}';
	}

	public static function sanitize_hex_color( mixed $color ): string {
		if ( ! is_string( $color ) ) {
			return '';
		}

		$trimmed = trim( $color );
		if ( 1 !== preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $trimmed, $matches ) ) {
			return '';
		}

		$hex = strtolower( $matches[1] );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return '#' . $hex;
	}

	public static function sanitize_menu_icon( mixed $icon ): string {
		if ( ! is_string( $icon ) ) {
			return self::DEFAULT_ICON;
		}

		$trimmed = trim( $icon );

		return 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $trimmed )
			? $trimmed
			: self::DEFAULT_ICON;
	}

	public static function sanitize_logo_url( mixed $url ): string {
		if ( ! is_scalar( $url ) ) {
			return '';
		}

		$raw = trim( (string) $url );
		if ( '' === $raw ) {
			return '';
		}

		$cleaned = function_exists( 'esc_url_raw' )
			? esc_url_raw( $raw, [ 'http', 'https' ] )
			: $raw;

		return self::validate_http_url( $cleaned );
	}

	private static function validate_http_url( mixed $url ): string {
		if ( ! is_scalar( $url ) ) {
			return '';
		}

		$cleaned = trim( (string) $url );
		if ( '' === $cleaned || 1 !== preg_match( '#^https?://#i', $cleaned ) ) {
			return '';
		}

		return false !== filter_var( $cleaned, FILTER_VALIDATE_URL ) ? $cleaned : '';
	}

	private static function trim_string( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function sanitize_text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'sanitize_text_field' ) ) {
			return trim( sanitize_text_field( $text ) );
		}

		return trim( (string) ( preg_replace( '/<[^>]*>/', '', $text ) ?? '' ) );
	}

	private static function mix_hex( string $base_hex, string $target_hex, float $weight ): string {
		$base   = self::hex_to_rgb( $base_hex );
		$target = self::hex_to_rgb( $target_hex );

		$r = (int) round( $base[0] * ( 1.0 - $weight ) + $target[0] * $weight );
		$g = (int) round( $base[1] * ( 1.0 - $weight ) + $target[1] * $weight );
		$b = (int) round( $base[2] * ( 1.0 - $weight ) + $target[2] * $weight );

		return sprintf( '#%02x%02x%02x', max( 0, min( 255, $r ) ), max( 0, min( 255, $g ) ), max( 0, min( 255, $b ) ) );
	}

	private static function get_contrast_color( string $hex ): string {
		[ $r, $g, $b ] = self::hex_to_rgb( $hex );

		$channels = [];
		foreach ( [ $r, $g, $b ] as $channel ) {
			$c          = $channel / 255.0;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}

		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return $luminance > 0.179 ? '#0f172a' : '#ffffff';
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function hex_to_rgb( string $hex ): array {
		$clean = ltrim( self::sanitize_hex_color( $hex ) ?: self::DEFAULT_COLOR, '#' );

		return [
			(int) hexdec( substr( $clean, 0, 2 ) ),
			(int) hexdec( substr( $clean, 2, 2 ) ),
			(int) hexdec( substr( $clean, 4, 2 ) ),
		];
	}
}