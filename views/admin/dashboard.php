<?php
/**
 * Neora Core — Modern Admin Dashboard View.
 *
 * @var string $page_title
 * @var string $dashboard_subtitle
 * @var string $brand_logo_url
 * @var string $brand_monogram
 * @var bool $show_version_badge
 * @var bool $show_system_info
 * @var \NeoraStudio\NeoraCore\Core\ModuleInterface[] $modules
 * @var \NeoraStudio\NeoraCore\Core\ModuleManager|null $manager
 * @var array<string, bool> $effective_states
 * @var array<string, bool> $saved_states
 * @var array<string, array<int, string>> $dependency_errors
 * @var array<string, array<int, string>> $declared_dependencies
 * @var array<string, array<int, string>> $dependent_modules
 * @var bool $has_internal_dep_block
 */

declare(strict_types=1);

use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$modules_saved          = isset( $_GET['modules_saved'] ) && '1' === $_GET['modules_saved'];
$active_count           = $manager ? count( $manager->get_active_modules() ) : 0;
$total_count            = count( $modules );
$dependency_errors      = $dependency_errors ?? [];
$declared_dependencies  = $declared_dependencies ?? [];
$dependent_modules      = $dependent_modules ?? [];
$has_internal_dep_block = ! empty( $has_internal_dep_block );
$neora_show_version     = isset( $show_version_badge ) ? (bool) $show_version_badge : true;
$neora_show_system      = isset( $show_system_info ) ? (bool) $show_system_info : true;
$neora_header_badges    = [];

if ( $neora_show_version ) {
	$neora_header_badges[] = [
		'label'   => sprintf( 'v%s', NEORA_CORE_VERSION ),
		'variant' => 'primary',
	];
}

$neora_header_badges[] = [
	'label'   => sprintf(
		/* translators: 1: active modules count, 2: total modules count */
		__( '%1$d / %2$d Active', 'neora-core' ),
		$active_count,
		$total_count
	),
	'variant' => 'success',
];

$neora_header_args = [
	'title'     => $page_title,
	'subtitle'  => $dashboard_subtitle ?? __( 'Modular WordPress platform foundation & module manager.', 'neora-core' ),
	'logo_url'  => $brand_logo_url ?? '',
	'monogram'  => $brand_monogram ?? 'N',
	'brand_alt' => $page_title,
	'badges'    => $neora_header_badges,
	'action'    => [
		'label' => __( 'Settings', 'neora-core' ),
		'url'   => admin_url( 'admin.php?page=neora-core-settings' ),
	],
];
?>
<div class="wrap neora-admin-wrap">
	<?php
	$neora_header_view = NEORA_CORE_PATH . 'views/admin/partials/header.php';
	if ( is_file( $neora_header_view ) ) {
		require $neora_header_view;
	}
	?>

	<?php if ( $modules_saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Module settings saved.', 'neora-core' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $has_internal_dep_block ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'One or more enabled modules could not be activated because an internal module dependency is missing or disabled.', 'neora-core' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="neora-card">
		<div class="neora-card__header">
			<div>
				<h2 class="neora-card__title"><?php esc_html_e( 'Modules', 'neora-core' ); ?></h2>
				<p class="neora-card__desc">
					<?php esc_html_e( 'Enable or disable optional platform features for this website.', 'neora-core' ); ?>
				</p>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="neora_save_modules" />
			<?php wp_nonce_field( 'neora_save_modules' ); ?>

			<div class="neora-modules-grid">
				<?php foreach ( $modules as $key => $module ) : ?>
					<?php
					$is_meta      = $module instanceof ModuleMetadataInterface;
					$is_core      = $is_meta && $module->is_core();
					$name         = $is_meta ? $module->get_name() : $key;
					$description  = $is_meta ? $module->get_description() : '';
					$missing_deps = $dependency_errors[ $key ] ?? ( $is_meta ? $module->get_missing_dependencies() : [] );
					$requires     = $declared_dependencies[ $key ] ?? [];
					$required_by  = $dependent_modules[ $key ] ?? [];
					$is_effective = ! empty( $effective_states[ $key ] );
					$is_saved     = array_key_exists( $key, $saved_states )
						? (bool) $saved_states[ $key ]
						: ( $is_meta ? $module->is_default_enabled() : true );
					$overridden   = ! $is_core && ( $is_effective !== $is_saved );
					$is_booted    = $manager ? $manager->is_active( $key ) : false;
					?>
					<div class="neora-module-card <?php echo $is_booted ? 'is-active' : ''; ?>">
						<div>
							<div class="neora-module-card__top">
								<div>
									<h3 class="neora-module-card__name"><?php echo esc_html( $name ); ?></h3>
									<code class="neora-module-card__key"><?php echo esc_html( $key ); ?></code>
								</div>
								<label class="neora-switch" aria-label="<?php echo esc_attr( $name ); ?>">
									<input
										type="checkbox"
										name="neora_modules[<?php echo esc_attr( $key ); ?>]"
										value="1"
										<?php checked( $is_effective ); ?>
										<?php disabled( $is_core || $overridden ); ?>
									/>
									<span class="neora-switch__slider"></span>
								</label>
							</div>
							<?php if ( '' !== $description ) : ?>
								<p class="neora-module-card__desc" style="margin-top: 10px;">
									<?php echo esc_html( $description ); ?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $requires ) ) : ?>
								<p class="neora-module-card__desc" style="margin-top: 8px; font-size: 12px;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: comma-separated list of required modules */
											__( 'Requires: %s', 'neora-core' ),
											implode( ', ', $requires )
										)
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $required_by ) ) : ?>
								<p class="neora-module-card__desc" style="margin-top: 4px; font-size: 12px;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: comma-separated list of dependent modules */
											__( 'Required by: %s', 'neora-core' ),
											implode( ', ', $required_by )
										)
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $missing_deps ) ) : ?>
								<p class="neora-module-card__dep">
									<?php echo esc_html( implode( ' | ', $missing_deps ) ); ?>
								</p>
							<?php endif; ?>
						</div>

						<div class="neora-module-card__footer">
							<?php if ( $is_core ) : ?>
								<span class="neora-badge neora-badge--primary"><?php esc_html_e( 'Core (Required)', 'neora-core' ); ?></span>
							<?php elseif ( ! empty( $missing_deps ) ) : ?>
								<span class="neora-badge neora-badge--danger"><?php esc_html_e( 'Missing Dependency', 'neora-core' ); ?></span>
							<?php elseif ( $is_booted ) : ?>
								<span class="neora-badge neora-badge--success"><?php esc_html_e( 'Active', 'neora-core' ); ?></span>
							<?php else : ?>
								<span class="neora-badge neora-badge--neutral"><?php esc_html_e( 'Inactive', 'neora-core' ); ?></span>
							<?php endif; ?>

							<?php if ( $overridden ) : ?>
								<span class="neora-badge neora-badge--warning"><?php esc_html_e( '(Controlled by filter)', 'neora-core' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<button type="submit" class="neora-btn neora-btn--primary">
				<?php esc_html_e( 'Save Module Settings', 'neora-core' ); ?>
			</button>
		</form>
	</div>

	<?php if ( $neora_show_system ) : ?>
		<div class="neora-card">
			<div class="neora-card__header">
				<div>
					<h2 class="neora-card__title"><?php esc_html_e( 'System', 'neora-core' ); ?></h2>
				</div>
			</div>
			<div class="neora-stats-grid">
				<div class="neora-stat">
					<div class="neora-stat__label"><?php esc_html_e( 'Plugin version', 'neora-core' ); ?></div>
					<code class="neora-stat__value"><?php echo esc_html( NEORA_CORE_VERSION ); ?></code>
				</div>
				<div class="neora-stat">
					<div class="neora-stat__label"><?php esc_html_e( 'PHP version', 'neora-core' ); ?></div>
					<code class="neora-stat__value"><?php echo esc_html( PHP_VERSION ); ?></code>
				</div>
				<div class="neora-stat">
					<div class="neora-stat__label"><?php esc_html_e( 'WordPress version', 'neora-core' ); ?></div>
					<code class="neora-stat__value"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>