<?php
/**
 * Neora Core — Admin Menu & Module Control Dashboard.
 *
 * @package NeoraStudio\NeoraCore\Admin
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Admin;

use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Core\ModuleStateStore;
use NeoraStudio\NeoraCore\Modules\Portfolio\PortfolioModule;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use NeoraStudio\NeoraCore\Services\Branding\BrandingService;

final class AdminMenu implements ModuleMetadataInterface {

	public function get_key(): string {
		return 'neora/admin-menu';
	}

	public function get_name(): string {
		return __( 'Admin Dashboard', 'neora-core' );
	}

	public function get_description(): string {
		return __( 'Core admin menu, system status, and module toggle panel.', 'neora-core' );
	}

	public function is_core(): bool {
		return true;
	}

	public function is_default_enabled(): bool {
		return true;
	}

	public function get_missing_dependencies(): array {
		return [];
	}

	public function has_rewrite_rules(): bool {
		return false;
	}

	public function register( Container $container ): void {}

	public function boot( HookRegistry $hooks ): void {
		$hooks->action( 'admin_menu', [ $this, 'register_menu' ] );
		$hooks->action( 'admin_post_neora_save_modules', [ $this, 'handle_save_modules' ] );
		$hooks->action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		$hooks->filter( 'admin_footer_text', [ $this, 'filter_admin_footer_text' ] );
	}

	public function register_menu(): void {
		$branding   = $this->get_branding_service();
		$settings   = $branding->get_settings();
		$menu_label = $branding->get_menu_label();
		$menu_icon  = (string) ( $settings['menu_icon'] ?? BrandingService::DEFAULT_ICON );

		add_menu_page(
			$menu_label,
			$menu_label,
			'manage_options',
			'neora-core',
			[ $this, 'render_dashboard' ],
			$menu_icon,
			30
		);
	}

	/**
	 * Load admin CSS/JS strictly on Neora Core pages and Neora CPT screens.
	 */
	public function enqueue_admin_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$is_neora_page = str_contains( (string) $screen->id, 'neora-core' );
		$is_neora_cpt  = in_array(
			(string) $screen->post_type,
			[ ServicesModule::CPT, PortfolioModule::CPT ],
			true
		);

		if ( ! $is_neora_page && ! $is_neora_cpt ) {
			return;
		}

		$branding = $this->get_branding_service();

		wp_enqueue_style(
			'neora-core-admin',
			NEORA_CORE_URL . 'assets/admin/neora-admin.css',
			[],
			NEORA_CORE_VERSION
		);

		if ( function_exists( 'wp_add_inline_style' ) ) {
			wp_add_inline_style( 'neora-core-admin', $branding->build_inline_css() );
		}

		if ( $is_neora_page ) {
			if ( str_contains( (string) $screen->id, 'neora-core-settings' ) && function_exists( 'wp_enqueue_media' ) ) {
				wp_enqueue_media();
			}

			wp_enqueue_script(
				'neora-core-admin',
				NEORA_CORE_URL . 'assets/admin/neora-admin.js',
				[],
				NEORA_CORE_VERSION,
				true
			);

			wp_localize_script(
				'neora-core-admin',
				'neoraAdminData',
				[
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'testSmsNonce' => wp_create_nonce( 'neora_test_sms' ),
					'defaultColor' => BrandingService::DEFAULT_COLOR,
					'i18n'         => [
						'enterMobile'     => __( 'Please enter a valid mobile number first.', 'neora-core' ),
						'sending'         => __( 'Sending...', 'neora-core' ),
						'genericError'    => __( 'An unexpected error occurred while sending the test SMS.', 'neora-core' ),
						'selectLogoTitle' => __( 'Select Brand Logo', 'neora-core' ),
						'useThisLogo'     => __( 'Use this logo', 'neora-core' ),
					],
				]
			);
		}
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'neora-core' ) );
		}

		$app     = Bootstrap::app();
		$manager = $app?->module_manager();
		$modules = $manager ? $manager->all() : [];

		/** @var ModuleStateStore $state_store */
		$state_store = ( $app && $app->container()->has( 'neora.module_state' ) )
			? $app->container()->get( 'neora.module_state' )
			: new ModuleStateStore();

		$effective_states   = $state_store->get_effective_states( $modules );
		$saved_states       = $state_store->get_saved_states();
		$branding           = $this->get_branding_service();
		$branding_settings  = $branding->get_settings();
		$page_title         = $branding->get_display_brand_name();
		$dashboard_subtitle = $branding->get_dashboard_subtitle();
		$brand_logo_url     = (string) ( $branding_settings['logo_url'] ?? '' );
		$brand_monogram     = $branding->get_monogram( $page_title );
		$show_version_badge = ! empty( $branding_settings['show_version_badge'] );
		$show_system_info   = ! empty( $branding_settings['show_system_info'] );

		$dependency_errors      = [];
		$declared_dependencies  = [];
		$dependent_modules      = [];
		$has_internal_dep_block = false;

		foreach ( $modules as $key => $module ) {
			$external_errors = $module instanceof ModuleMetadataInterface
				? $module->get_missing_dependencies()
				: [];
			$internal_errors = $manager ? $manager->get_dependency_errors( $key ) : [];

			if ( ! empty( $internal_errors ) ) {
				$has_internal_dep_block = true;
			}

			$dependency_errors[ $key ] = array_values(
				array_unique( array_merge( $external_errors, $internal_errors ) )
			);

			if ( $manager ) {
				$deps = $manager->get_dependencies( $key );
				if ( ! empty( $deps ) ) {
					$labels = [];
					foreach ( $deps as $dep_key ) {
						$dep_mod  = $modules[ $dep_key ] ?? null;
						$labels[] = $dep_mod instanceof ModuleMetadataInterface
							? $dep_mod->get_name()
							: $dep_key;
					}
					$declared_dependencies[ $key ] = $labels;
				}

				$rev_deps = $manager->get_dependents( $key );
				if ( ! empty( $rev_deps ) ) {
					$rev_labels = [];
					foreach ( $rev_deps as $rev_key ) {
						$rev_mod      = $modules[ $rev_key ] ?? null;
						$rev_labels[] = $rev_mod instanceof ModuleMetadataInterface
							? $rev_mod->get_name()
							: $rev_key;
					}
					$dependent_modules[ $key ] = $rev_labels;
				}
			}
		}

		$view = NEORA_CORE_PATH . 'views/admin/dashboard.php';

		if ( is_file( $view ) ) {
			require $view;
		}
	}

	public function handle_save_modules(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'neora-core' ) );
		}

		check_admin_referer( 'neora_save_modules' );

		$app     = Bootstrap::app();
		$manager = $app?->module_manager();

		if ( null === $manager ) {
			wp_safe_redirect( admin_url( 'admin.php?page=neora-core' ) );
			exit;
		}

		/** @var ModuleStateStore $state_store */
		$state_store = $app->container()->has( 'neora.module_state' )
			? $app->container()->get( 'neora.module_state' )
			: new ModuleStateStore();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified via check_admin_referer above.
		$raw_modules = isset( $_POST['neora_modules'] ) && is_array( $_POST['neora_modules'] )
			? wp_unslash( $_POST['neora_modules'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: [];

		$submitted = [];
		foreach ( $manager->all() as $key => $module ) {
			$submitted[ $key ] = ! empty( $raw_modules[ $key ] );
		}

		$state_store->save_states( $submitted, $manager->all() );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => 'neora-core',
					'modules_saved' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Replace the WordPress admin footer text on Neora screens when configured.
	 */
	public function filter_admin_footer_text( string $text ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! str_contains( (string) $screen->id, 'neora-core' ) ) {
			return $text;
		}

		$settings    = $this->get_branding_service()->get_settings();
		$footer_text = trim( (string) ( $settings['admin_footer_text'] ?? '' ) );

		return '' !== $footer_text ? esc_html( $footer_text ) : $text;
	}

	private function get_branding_service(): BrandingService {
		$app = Bootstrap::app();
		if ( $app && $app->container()->has( 'neora.branding' ) ) {
			/** @var BrandingService $service */
			$service = $app->container()->get( 'neora.branding' );
			return $service;
		}

		return new BrandingService();
	}
}