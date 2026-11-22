<?php
/**
 * Neora Core — Bootstrap.
 *
 * Orchestrates the startup sequence. Called once from the main plugin file
 * via the plugins_loaded hook.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

use NeoraStudio\NeoraCore\Admin\AdminMenu;
use NeoraStudio\NeoraCore\Admin\SettingsPage;
use NeoraStudio\NeoraCore\Core\Migration\MigrationInterface;
use NeoraStudio\NeoraCore\Core\Migration\MigrationRunner;
use NeoraStudio\NeoraCore\Core\Migration\Migrations\M001NormalizeModuleKeys;
use NeoraStudio\NeoraCore\Modules\Auth\AuthModule;
use NeoraStudio\NeoraCore\Modules\Portfolio\PortfolioModule;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use NeoraStudio\NeoraCore\Services\Branding\BrandingService;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;
use NeoraStudio\NeoraCore\Services\Sms\MelipayamakClient;
use NeoraStudio\NeoraCore\Services\Sms\OtpManager;
use NeoraStudio\NeoraCore\Services\Sms\Providers\MelipayamakProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\TwilioProvider;
use NeoraStudio\NeoraCore\Services\Sms\Providers\WebhookProvider;
use NeoraStudio\NeoraCore\Services\Sms\SmsManager;
use NeoraStudio\NeoraCore\Services\Sms\SmsQueue;
use NeoraStudio\NeoraCore\Modules\Multilingual\PolylangModule;
use NeoraStudio\NeoraCore\Modules\WooCommerce\WooCommerceBridgeModule;



final class Bootstrap {

	private static ?Application $app = null;

	/**
	 * Initialise and boot the plugin.
	 *
	 * Hooked onto 'plugins_loaded' in neora-core.php.
	 */
	public static function init(): void {
		self::$app = self::create_application();

		/** @var HookRegistry $hooks */
		$hooks = self::$app->container()->get( 'neora.hooks' );
		$hooks->action( 'init', [ self::class, 'load_textdomain' ], 1 );
		$hooks->action( 'init', [ self::class, 'maybe_run_migrations' ], 5 );
		$hooks->action( 'init', [ ModuleStateStore::class, 'maybe_flush_rewrite_rules' ], 99 );

		/** @var SmsQueue $sms_queue */
		$sms_queue = self::$app->container()->get( 'neora.sms_queue' );
		$sms_queue->boot( $hooks );

		/** @var ModuleStateStore $state_store */
		$state_store = self::$app->container()->get( 'neora.module_state' );

		self::$app->module_manager()->boot( self::$app->container(), $hooks, $state_store );
	}

	/**
	 * Build an Application instance with core services and modules registered.
	 */
	public static function create_application(): Application {
		$app = new Application();

		self::register_core_services( $app->container() );
		self::register_modules( $app );

		return $app;
	}

	/**
	 * Return the booted Application instance, or null if called before init().
	 */
	public static function app(): ?Application {
		return self::$app;
	}

	/**
	 * Runs when the plugin is activated in WordPress.
	 */
	public static function activate(): void {
		$app = self::create_application();

		/** @var MigrationRunner $migrations */
		$migrations = $app->container()->get( 'neora.migrations' );
		$migrations->run();

		/** @var ModuleStateStore $state_store */
		$state_store = $app->container()->get( 'neora.module_state' );
		$manager     = $app->module_manager();
		$modules     = $manager->all();
		$states      = $state_store->get_effective_states( $modules );
		$plan        = $manager->resolve_boot_plan( $states );

		foreach ( $plan['boot_order'] as $key ) {
			$module = $modules[ $key ] ?? null;
			if ( $module instanceof RewriteAwareInterface ) {
				$module->register_rewrite_entities();
			}
		}

		flush_rewrite_rules();
	}

	/**
	 * Runs when the plugin is deactivated in WordPress.
	 */
	public static function deactivate(): void {
		$app        = self::create_application();
		$post_types = [];
		$taxonomies = [];

		foreach ( $app->module_manager()->all() as $module ) {
			if ( ! $module instanceof RewriteAwareInterface ) {
				continue;
			}

			foreach ( $module->get_rewrite_post_types() as $post_type ) {
				$post_types[] = $post_type;
			}

			foreach ( $module->get_rewrite_taxonomies() as $taxonomy ) {
				$taxonomies[] = $taxonomy;
			}
		}

		foreach ( array_unique( $post_types ) as $post_type ) {
			unregister_post_type( $post_type );
		}

		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( SmsQueue::CRON_HOOK );
		}

		flush_rewrite_rules();
	}

	/**
	 * Load plugin translations on the 'init' hook (WP 6.7+ compliant).
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'neora-core',
			false,
			dirname( plugin_basename( NEORA_CORE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Run pending schema/option migrations when the stored DB version is behind.
	 */
	public static function maybe_run_migrations(): void {
		if ( null === self::$app ) {
			return;
		}

		/** @var MigrationRunner $migrations */
		$migrations = self::$app->container()->get( 'neora.migrations' );
		$migrations->run();
	}

	/**
	 * Bind core infrastructure services into the container.
	 */
	private static function register_core_services( Container $container ): void {
		$container->set( 'neora.hooks', static fn(): HookRegistry => new HookRegistry() );
		$container->set( 'neora.module_state', static fn(): ModuleStateStore => new ModuleStateStore() );

		$container->set(
			'neora.migrations',
			static function (): MigrationRunner {
				$migrations = [
					new M001NormalizeModuleKeys(),
				];

				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'neora_core_migrations', $migrations );
					if ( is_array( $filtered ) ) {
						$migrations = array_values(
							array_filter(
								$filtered,
								static fn( mixed $item ): bool => $item instanceof MigrationInterface
							)
						);
					}
				}

				return new MigrationRunner( $migrations );
			}
		);

		$container->set(
			'neora.branding',
			static fn(): BrandingService => new BrandingService()
		);

		$container->set(
			'neora.currency',
			static fn(): CurrencyService => new CurrencyService()
		);

		$container->set(
			'neora.sms',
			static fn(): MelipayamakClient => new MelipayamakClient()
		);

		$container->set(
			'neora.sms_manager',
			static function ( Container $c ): SmsManager {
				/** @var MelipayamakClient $client */
				$client = $c->get( 'neora.sms' );
				return new SmsManager(
					[
						new MelipayamakProvider( $client ),
						new TwilioProvider(),
						new WebhookProvider(),
					]
				);
			}
		);

		$container->set(
			'neora.sms_queue',
			static function ( Container $c ): SmsQueue {
				/** @var SmsManager $manager */
				$manager = $c->get( 'neora.sms_manager' );
				return new SmsQueue( $manager );
			}
		);

		$container->set(
			'neora.otp',
			static function ( Container $c ): OtpManager {
				/** @var SmsManager $sms_manager */
				$sms_manager = $c->get( 'neora.sms_manager' );
				return new OtpManager( $sms_manager );
			}
		);
	}

	/**
	 * Register built-in and external feature modules.
	 */
	private static function register_modules( Application $app ): void {
		$manager = $app->module_manager();

		$manager->add( new AdminMenu() );
		$manager->add( new ServicesModule() );
		$manager->add( new PortfolioModule() );
		$manager->add( new AuthModule() );
		$manager->add( new SettingsPage() );
        $manager->add( new PolylangModule() );
		$manager->add( new WooCommerceBridgeModule() );


		if ( function_exists( 'do_action' ) ) {
			do_action( 'neora_core_register_modules', $manager, $app );
		}
	}
}