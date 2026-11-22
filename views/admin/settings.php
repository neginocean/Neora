<?php
/**
 * Admin View: Neora Core Tabbed Settings Page.
 *
 * @var string $brand_name
 * @var string $settings_subtitle
 * @var string $brand_logo_url
 * @var string $brand_monogram
 * @var string $active_tab
 * @var array<string, array{title: string, callback?: callable}> $tabs
 *
 * @package NeoraStudio\NeoraCore
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$neora_header_args = [
	'title'     => $brand_name . ' — ' . __( 'Settings', 'neora-core' ),
	'subtitle'  => $settings_subtitle ?? __( 'Manage branding, SMS gateway, and active module configurations.', 'neora-core' ),
	'logo_url'  => $brand_logo_url ?? '',
	'monogram'  => $brand_monogram ?? 'N',
	'brand_alt' => $brand_name,
	'badges'    => [],
	'action'    => [
		'label' => __( 'Modules Dashboard', 'neora-core' ),
		'url'   => admin_url( 'admin.php?page=neora-core' ),
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

	<?php settings_errors(); ?>

	<nav class="neora-tabs" aria-label="<?php esc_attr_e( 'Settings Tabs', 'neora-core' ); ?>">
		<?php foreach ( $tabs as $tab_key => $tab ) : ?>
			<?php
			$tab_url = add_query_arg(
				[
					'page' => 'neora-core-settings',
					'tab'  => $tab_key,
				],
				admin_url( 'admin.php' )
			);
			?>
			<a
				href="<?php echo esc_url( $tab_url ); ?>"
				class="neora-tab <?php echo $active_tab === $tab_key ? 'is-active' : ''; ?>"
			>
				<?php echo esc_html( (string) ( $tab['title'] ?? $tab_key ) ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'general' === $active_tab ) : ?>
		<div class="neora-card">
			<form method="post" action="options.php" novalidate="novalidate">
				<?php
				settings_fields( 'neora_core_general_group' );
				do_settings_sections( 'neora-core-settings-general' );
				?>
				<p class="submit">
					<button type="submit" class="neora-btn neora-btn--primary">
						<?php esc_html_e( 'Save Settings', 'neora-core' ); ?>
					</button>
				</p>
			</form>
		</div>
	<?php elseif ( 'regional' === $active_tab ) : ?>
		<div class="neora-card">
			<form method="post" action="options.php" novalidate="novalidate">
				<?php
				settings_fields( 'neora_core_regional_group' );
				do_settings_sections( 'neora-core-settings-regional' );
				?>
				<p class="submit">
					<button type="submit" class="neora-btn neora-btn--primary">
						<?php esc_html_e( 'Save Settings', 'neora-core' ); ?>
					</button>
				</p>
			</form>
		</div>
	<?php elseif ( 'sms' === $active_tab ) : ?>
		<div class="neora-card">
			<form method="post" action="options.php" novalidate="novalidate">
				<?php
				settings_fields( 'neora_core_settings_group' );
				do_settings_sections( 'neora-core-settings' );
				?>
				<p class="submit">
					<button type="submit" class="neora-btn neora-btn--primary">
						<?php esc_html_e( 'Save Settings', 'neora-core' ); ?>
					</button>
				</p>
			</form>
		</div>

		<?php
		$neora_default_country = \NeoraStudio\NeoraCore\Services\Sms\PhoneNumber::get_default_country();
		$neora_calling_codes   = \NeoraStudio\NeoraCore\Services\Sms\PhoneNumber::get_country_calling_codes();
		$neora_sms_queue       = function_exists( 'neora_sms_queue' )
			? neora_sms_queue()
			: new \NeoraStudio\NeoraCore\Services\Sms\SmsQueue();
		$neora_queue_stats     = $neora_sms_queue->get_stats();
		$neora_queue_nonce     = wp_create_nonce( 'neora_sms_queue_action' );
		?>
		<div class="neora-card">
			<div class="neora-card__header">
				<div>
					<h2 class="neora-card__title"><?php esc_html_e( 'Test SMS Delivery', 'neora-core' ); ?></h2>
					<p class="neora-card__desc">
						<?php esc_html_e( 'Send a sample OTP pattern (12345) to verify your routing, E.164 formatting, or Sandbox log.', 'neora-core' ); ?>
					</p>
				</div>
			</div>
			<div class="neora-test-sms-row">
				<select
					id="neora-test-sms-country"
					aria-label="<?php esc_attr_e( 'Country Code', 'neora-core' ); ?>"
				>
					<?php foreach ( $neora_calling_codes as $neora_iso => $neora_dial ) : ?>
						<option value="<?php echo esc_attr( $neora_iso ); ?>" <?php selected( $neora_default_country, $neora_iso ); ?>>
							<?php echo esc_html( sprintf( '%s (+%s)', $neora_iso, $neora_dial ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input
					type="text"
					id="neora-test-sms-phone"
					class="regular-text"
					dir="ltr"
					placeholder="09123456789 / +447911123456"
					aria-label="<?php esc_attr_e( 'Test Mobile Number', 'neora-core' ); ?>"
				/>
				<button type="button" id="neora-send-test-sms" class="neora-btn neora-btn--secondary">
					<?php esc_html_e( 'Send Test SMS', 'neora-core' ); ?>
				</button>
			</div>
			<div id="neora-test-sms-feedback" class="neora-inline-feedback" role="status" aria-live="polite"></div>
		</div>

		<div
			id="neora-sms-queue-card"
			class="neora-card"
			data-nonce="<?php echo esc_attr( $neora_queue_nonce ); ?>"
		>
			<div class="neora-card__header">
				<div>
					<h2 class="neora-card__title"><?php esc_html_e( 'SMS Queue & Retries', 'neora-core' ); ?></h2>
					<p class="neora-card__desc">
						<?php esc_html_e( 'Monitor queued transactional SMS jobs and manually trigger due deliveries.', 'neora-core' ); ?>
					</p>
				</div>
			</div>
			<p class="description">
				<?php esc_html_e( 'Pending:', 'neora-core' ); ?>
				<strong id="neora-queue-pending"><?php echo esc_html( (string) $neora_queue_stats['pending'] ); ?></strong>
				&nbsp;|&nbsp;
				<?php esc_html_e( 'Completed:', 'neora-core' ); ?>
				<strong id="neora-queue-completed"><?php echo esc_html( (string) $neora_queue_stats['completed'] ); ?></strong>
				&nbsp;|&nbsp;
				<?php esc_html_e( 'Failed:', 'neora-core' ); ?>
				<strong id="neora-queue-failed"><?php echo esc_html( (string) $neora_queue_stats['failed'] ); ?></strong>
			</p>
			<div class="neora-test-sms-row">
				<button
					type="button"
					class="neora-btn neora-btn--secondary"
					data-queue-action="process_due"
				>
					<?php esc_html_e( 'Process Due Jobs', 'neora-core' ); ?>
				</button>
				<button
					type="button"
					class="neora-btn neora-btn--secondary"
					data-queue-action="clear_completed"
				>
					<?php esc_html_e( 'Clear Finished Jobs', 'neora-core' ); ?>
				</button>
			</div>
			<div id="neora-sms-queue-feedback" class="neora-inline-feedback" role="status" aria-live="polite"></div>
		</div>
	<?php elseif ( ! empty( $tabs[ $active_tab ]['callback'] ) && is_callable( $tabs[ $active_tab ]['callback'] ) ) : ?>
		<div class="neora-card">
			<?php call_user_func( $tabs[ $active_tab ]['callback'], $active_tab ); ?>
		</div>
	<?php endif; ?>
</div>