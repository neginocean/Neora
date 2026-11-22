<?php
/**
 * Neora Core — Shared Admin Header Partial.
 *
 * @var array{
 *     title?: string,
 *     subtitle?: string,
 *     logo_url?: string,
 *     monogram?: string,
 *     brand_alt?: string,
 *     badges?: array<int, array{label: string, variant?: string}>,
 *     action?: array{label: string, url: string}|null
 * } $neora_header_args
 *
 * @package NeoraStudio\NeoraCore
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$neora_header_title     = (string) ( $neora_header_args['title'] ?? '' );
$neora_header_subtitle  = (string) ( $neora_header_args['subtitle'] ?? '' );
$neora_header_logo_url  = (string) ( $neora_header_args['logo_url'] ?? '' );
$neora_header_monogram  = (string) ( $neora_header_args['monogram'] ?? 'N' );
$neora_header_brand_alt = (string) ( $neora_header_args['brand_alt'] ?? $neora_header_title );
$neora_header_badges    = isset( $neora_header_args['badges'] ) && is_array( $neora_header_args['badges'] )
	? $neora_header_args['badges']
	: [];
$neora_header_action    = isset( $neora_header_args['action'] ) && is_array( $neora_header_args['action'] )
	? $neora_header_args['action']
	: null;
?>
<div class="neora-header">
	<div class="neora-header__brand">
		<?php if ( '' !== $neora_header_logo_url ) : ?>
			<span class="neora-header__logo neora-header__logo--image">
				<img
					src="<?php echo esc_url( $neora_header_logo_url ); ?>"
					alt="<?php echo esc_attr( $neora_header_brand_alt ); ?>"
					class="neora-header__logo-img"
				/>
			</span>
		<?php else : ?>
			<span class="neora-header__logo" aria-hidden="true"><?php echo esc_html( $neora_header_monogram ); ?></span>
		<?php endif; ?>
		<div>
			<h1 class="neora-header__title"><?php echo esc_html( $neora_header_title ); ?></h1>
			<?php if ( '' !== $neora_header_subtitle ) : ?>
				<p class="neora-header__subtitle"><?php echo esc_html( $neora_header_subtitle ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php if ( ! empty( $neora_header_badges ) || null !== $neora_header_action ) : ?>
		<div class="neora-header__meta">
			<?php foreach ( $neora_header_badges as $neora_badge ) : ?>
				<?php
				$neora_badge_label   = (string) ( $neora_badge['label'] ?? '' );
				$neora_badge_variant = sanitize_html_class( (string) ( $neora_badge['variant'] ?? 'primary' ) );
				if ( '' === $neora_badge_label ) {
					continue;
				}
				?>
				<span class="neora-badge <?php echo esc_attr( 'neora-badge--' . $neora_badge_variant ); ?>">
					<?php echo esc_html( $neora_badge_label ); ?>
				</span>
			<?php endforeach; ?>
			<?php if ( null !== $neora_header_action && ! empty( $neora_header_action['url'] ) && ! empty( $neora_header_action['label'] ) ) : ?>
				<a class="neora-btn neora-btn--secondary" href="<?php echo esc_url( (string) $neora_header_action['url'] ); ?>">
					<?php echo esc_html( (string) $neora_header_action['label'] ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>