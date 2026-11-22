<?php
/**
 * Neora Core — Services Module.
 *
 * Registers the `service` Custom Post Type, the `service_category` taxonomy,
 * admin list columns, meta fields (schema-first), and the meta box UI + save handler.
 *
 * Lifecycle:
 *  - register(): nothing to bind into the container for MVP.
 *  - boot():     attach all hooks via HookRegistry — single entrypoint, no duplicates.
 *
 * Meta Box Policy:
 *  - context = 'normal' (required for classic PHP meta boxes in Gutenberg).
 *  - 'side' is forbidden per Neora Core Meta Box Policy.
 *
 * @package NeoraStudio\NeoraCore\Modules\Services
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Modules\Services;

use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\MetaHelper;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Core\RewriteAwareInterface;
use NeoraStudio\NeoraCore\Services\Currency\CurrencyService;

final class ServicesModule implements ModuleMetadataInterface, RewriteAwareInterface {

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    public const KEY = 'neora/services';
    public const CPT = 'service';
    public const TAX = 'service_category';
    private const NONCE_NAME   = '_neora_meta_nonce';

    // -----------------------------------------------------------------------
    // ModuleInterface
    // -----------------------------------------------------------------------

    public function get_key(): string {
        return self::KEY;
    }
    public function get_name(): string {
		return __( 'Services', 'neora-core' );
	}

	public function get_description(): string {
		return __( 'Registers the Service post type, categories, pricing meta, and admin columns.', 'neora-core' );
	}

	public function is_core(): bool {
		return false;
	}

	public function is_default_enabled(): bool {
		return true;
	}

	public function get_missing_dependencies(): array {
		return [];
	}

	public function has_rewrite_rules(): bool {
		return true;
	}

	public function register_rewrite_entities(): void {
		$this->register_post_type();
		$this->register_taxonomy();
	}

	public function get_rewrite_post_types(): array {
		return [ self::CPT ];
	}

	public function get_rewrite_taxonomies(): array {
		return [ self::TAX ];
	}

    public function register( Container $container ): void {
        // No container bindings needed at MVP stage.
    }

    /**
     * Single hook registration entrypoint — no hook is registered anywhere else.
     */
    public function boot( HookRegistry $hooks ): void {
        $hooks->action( 'init', [ $this, 'register_post_type' ] );
        $hooks->action( 'init', [ $this, 'register_taxonomy' ] );
        $hooks->action( 'init', [ $this, 'register_meta' ] );

        // CPT-scoped hook per Hook Contract — fires only for the `service` post type.
        $hooks->action( 'add_meta_boxes_service', [ $this, 'add_meta_box' ] );
        // CPT-specific save hook — no manual post-type guard needed.
        $hooks->action( 'save_post_service',      [ $this, 'save_meta' ] );
        $hooks->action( 'admin_notices', [ $this, 'render_price_notice' ] );

        // Admin list columns.
        $hooks->filter( 'manage_service_posts_columns',       [ $this, 'add_admin_columns' ] );
        $hooks->action( 'manage_service_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
    }

    // -----------------------------------------------------------------------
    // CPT
    // -----------------------------------------------------------------------

    /**
     * Register the `service` Custom Post Type.
     */
    public function register_post_type(): void {
        $labels = [
            'name'                   => _x( 'Services', 'post type general name', 'neora-core' ),
            'singular_name'          => _x( 'Service', 'post type singular name', 'neora-core' ),
            'add_new'                => __( 'Add New', 'neora-core' ),
            'add_new_item'           => __( 'Add New Service', 'neora-core' ),
            'edit_item'              => __( 'Edit Service', 'neora-core' ),
            'new_item'               => __( 'New Service', 'neora-core' ),
            'view_item'              => __( 'View Service', 'neora-core' ),
            'view_items'             => __( 'View Services', 'neora-core' ),
            'search_items'           => __( 'Search Services', 'neora-core' ),
            'not_found'              => __( 'No services found.', 'neora-core' ),
            'not_found_in_trash'     => __( 'No services found in Trash.', 'neora-core' ),
            'all_items'              => __( 'All Services', 'neora-core' ),
            'archives'               => __( 'Service Archives', 'neora-core' ),
            'attributes'             => __( 'Service Attributes', 'neora-core' ),
            'menu_name'              => _x( 'Services', 'admin menu', 'neora-core' ),
            'name_admin_bar'         => _x( 'Service', 'add new on admin bar', 'neora-core' ),
            'item_published'         => __( 'Service published.', 'neora-core' ),
            'item_published_private' => __( 'Service published privately.', 'neora-core' ),
            'item_reverted_to_draft' => __( 'Service reverted to draft.', 'neora-core' ),
            'item_updated'           => __( 'Service updated.', 'neora-core' ),
        ];

        $args = [
            'labels'             => $labels,
            'description'        => __( 'Services offered by the site.', 'neora-core' ),
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'query_var'          => true,
            'rewrite'            => [
                'slug'       => 'services',
                'with_front' => false,
            ],
            'capability_type'    => 'post',
            'has_archive'        => 'services',
            'hierarchical'       => false,
            'menu_position'      => 5,
            'menu_icon'          => 'dashicons-admin-tools',
            'supports'           => [
                'title',
                'editor',
                'thumbnail',
                'excerpt',
                'revisions',
                'custom-fields',
                'page-attributes',
            ],
        ];

        register_post_type( 'service', $args );
    }

    // -----------------------------------------------------------------------
    // Taxonomy
    // -----------------------------------------------------------------------

    /**
     * Register the `service_category` taxonomy.
     */
    public function register_taxonomy(): void {
        $labels = [
            'name'              => _x( 'Service Categories', 'taxonomy general name', 'neora-core' ),
            'singular_name'     => _x( 'Service Category', 'taxonomy singular name', 'neora-core' ),
            'search_items'      => __( 'Search Service Categories', 'neora-core' ),
            'all_items'         => __( 'All Service Categories', 'neora-core' ),
            'parent_item'       => __( 'Parent Service Category', 'neora-core' ),
            'parent_item_colon' => __( 'Parent Service Category:', 'neora-core' ),
            'edit_item'         => __( 'Edit Service Category', 'neora-core' ),
            'update_item'       => __( 'Update Service Category', 'neora-core' ),
            'add_new_item'      => __( 'Add New Service Category', 'neora-core' ),
            'new_item_name'     => __( 'New Service Category Name', 'neora-core' ),
            'menu_name'         => __( 'Categories', 'neora-core' ),
            'not_found'         => __( 'No service categories found.', 'neora-core' ),
            'no_terms'          => __( 'No service categories', 'neora-core' ),
        ];

        $args = [
            'labels'             => $labels,
            'hierarchical'       => true,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'show_admin_column'  => true,
            'query_var'          => true,
            'rewrite'            => [
                'slug'       => 'service-category',
                'with_front' => false,
            ],
        ];

        register_taxonomy( 'service_category', [ 'service' ], $args );
    }

    // -----------------------------------------------------------------------
    // Field schema — single source of truth for register, render, and save.
    // -----------------------------------------------------------------------

    /**
     * Canonical field definitions for all `service` meta fields.
     *
     * Every key here is consumed by register_meta(), render_meta_box(), and save_meta().
     * To add a new field: add one entry here. No other method needs touching.
     *
     * @return array<int, array{
     *   meta_key:    string,
     *   post_key:    string,
     *   label:       string,
     *   description: string,
     *   placeholder: string,
     *   inputmode:   string,
     *   sanitize:    callable(mixed): string,
     * }>
     */
    private static function field_schema(): array {
        $currency = self::get_currency_service()->get_currency();
        $decimals = (int) $currency['decimals'];

        if ( 0 === $decimals ) {
            /* translators: %s: active currency unit label */
            $price_label = sprintf( __( 'Service Price (%s)', 'neora-core' ), $currency['symbol'] );
            $price_desc  = 'IRT' === $currency['code']
                ? __( 'Whole tomans only; no decimals or separators.', 'neora-core' )
                : sprintf(
                    /* translators: %s: active currency name */
                    __( 'Whole %s units only; no decimals or separators.', 'neora-core' ),
                    $currency['name']
                );
            $price_placeholder = __( 'e.g. 1500000', 'neora-core' );
            $price_inputmode   = 'numeric';
            $price_pattern     = '^[0-9۰-۹٠-٩]*$(?![\\s\\S])';
        } else {
            /* translators: %s: active currency code */
            $price_label = sprintf( __( 'Service Price (%s)', 'neora-core' ), $currency['code'] );
            $price_desc  = sprintf(
                /* translators: 1: active currency name, 2: max decimal places */
                __( 'Enter a valid amount in %1$s (up to %2$d decimal places, no thousand separators).', 'neora-core' ),
                $currency['name'],
                $decimals
            );
            $price_placeholder = __( 'e.g. 150.00', 'neora-core' );
            $price_inputmode   = 'decimal';
            $price_pattern     = '^(?:[0-9۰-۹٠-٩]+(?:[.٫][0-9۰-۹٠-٩]{1,' . $decimals . '})?|[.٫][0-9۰-۹٠-٩]{1,' . $decimals . '})?$(?![\\s\\S])';
        }

        return [
            [
                'meta_key'     => '_neora_service_code',
                'post_key'     => 'neora_service_code',
                'label'        => __( 'Service Code', 'neora-core' ),
                'description'  => __( 'Short service identifier code.', 'neora-core' ),
                'placeholder'  => __( 'e.g. SEO-01', 'neora-core' ),
                'inputmode'    => 'text',
                'type'         => 'string',
                'dir'          => 'ltr',
                'show_in_rest' => [ 'schema' => [ 'context' => [ 'edit' ] ] ],
                'sanitize_cb'  => static fn( mixed $v ): string =>
                    is_scalar( $v ) ? trim( sanitize_text_field( (string) $v ) ) : '',
            ],
            [
                'meta_key'     => '_neora_service_price',
                'post_key'     => 'neora_service_price',
                'label'        => $price_label,
                'description'  => $price_desc,
                'placeholder'  => $price_placeholder,
                'inputmode'    => $price_inputmode,
                'type'         => 'string',
                'dir'          => 'ltr',
                'show_in_rest' => [
                    'schema' => [
                        'context' => [ 'edit' ],
                        'pattern' => $price_pattern,
                    ],
                ],
                'sanitize_cb'  => static fn( mixed $v ): string =>
                    self::sanitize_price( $v ) ?? '',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Meta registration
    // -----------------------------------------------------------------------

    /**
 * Register meta fields for the `service` post type.
 * Delegates to MetaHelper::register_fields() — no field logic lives here.
 */
public function register_meta(): void {
    MetaHelper::register_fields(
        'service',
        self::field_schema(),
        MetaHelper::make_edit_post_auth_cb()
    );
}



    // -----------------------------------------------------------------------
    // Meta box
    // -----------------------------------------------------------------------

    /**
     * Register the meta box on the `service` edit screen.
     *
     * context = 'normal' is required by Neora Core Meta Box Policy:
     * Gutenberg loads 'normal'/'advanced' classic meta boxes through the
     * dedicated classic iframe at the bottom of the block editor. Using
     * 'side' causes the panel header to appear in the Document sidebar
     * with no mountable content — the header renders but clicking does nothing.
     */
    public function add_meta_box(): void {
        add_meta_box(
            'neora_service_details',
            __( 'Service Details', 'neora-core' ),
            [ $this, 'render_meta_box' ],
            'service',
            'normal',   // 'side' is forbidden — see Meta Box Policy.
            'default'
        );
    }

    /**
     * Render the meta box HTML.
     * Driven by field_schema() — markup is generated per field, no hardcoded keys.
     *
     * @param \WP_Post $post Current post object.
     */
   public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'neora_save_meta_' . $post->ID, '_neora_meta_nonce' );
		?>
		<div class="neora-metabox-grid">
			<?php foreach ( self::field_schema() as $field ) : ?>
				<?php $value = (string) get_post_meta( $post->ID, $field['meta_key'], true ); ?>
				<div class="neora-metabox-field">
					<label for="<?php echo esc_attr( $field['post_key'] ); ?>">
						<?php echo esc_html( $field['label'] ); ?>
					</label>
					<input
						type="text"
						id="<?php echo esc_attr( $field['post_key'] ); ?>"
						name="<?php echo esc_attr( $field['post_key'] ); ?>"
						aria-describedby="<?php echo esc_attr( $field['post_key'] . '_description' ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
						<?php if ( 'text' !== $field['inputmode'] ) : ?>
							inputmode="<?php echo esc_attr( $field['inputmode'] ); ?>"
						<?php endif; ?>
						<?php if ( ! empty( $field['dir'] ) ) : ?>
							dir="<?php echo esc_attr( $field['dir'] ); ?>"
						<?php endif; ?>
					/>
					<p class="description" id="<?php echo esc_attr( $field['post_key'] . '_description' ); ?>">
						<?php echo esc_html( $field['description'] ); ?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

    // -----------------------------------------------------------------------
    // Save meta
    // -----------------------------------------------------------------------

    /**
     * Save service meta fields when the post is saved.
     *
     * @param int $post_id Post ID passed by save_post_service.
     */
    public function save_meta( int $post_id ): void {
        // 1. Nonce verification.
        if (
            ! isset( $_POST[ self::NONCE_NAME ] )
            || ! is_string( $_POST[ self::NONCE_NAME ] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ),
                'neora_save_meta_' . $post_id
            )
        ) {
            return;
        }

        // 2. Skip autosave.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // 3. Skip revisions.
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        // 4. Capability check.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $schema = self::field_schema();

        // Preserve existing price and flag admin notice if the submitted price is invalid.
        if ( isset( $_POST['neora_service_price'] ) ) {
            $raw_price = wp_unslash( $_POST['neora_service_price'] );
            if ( null === self::sanitize_price( $raw_price ) ) {
                $schema = array_values(
                    array_filter(
                        $schema,
                        static fn( array $field ): bool => '_neora_service_price' !== $field['meta_key']
                    )
                );

                add_filter(
                    'redirect_post_location',
                    static fn( string $location ): string => add_query_arg( 'neora_price_invalid', '1', $location )
                );
            }
        }

        MetaHelper::save_fields( $post_id, $schema, $_POST );
    }

    /**
     * Normalize a service price string according to the active currency's decimal precision.
     */
    public static function sanitize_price( mixed $value, ?int $decimals = null ): ?string {
        $precision = $decimals ?? (int) self::get_currency_service()->get_currency()['decimals'];
        return CurrencyService::normalize_amount( $value, $precision, false );
    }

    public function render_price_notice(): void {
        if ( ! isset( $_GET['neora_price_invalid'] ) || '1' !== $_GET['neora_price_invalid'] ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || 'service' !== $screen->post_type || ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        $currency = self::get_currency_service()->get_currency();
        if ( 'IRT' === $currency['code'] ) {
            $message = __( 'The service price was not saved. Enter a whole number in tomans, without decimals or separators.', 'neora-core' );
        } elseif ( 0 === (int) $currency['decimals'] ) {
            $message = sprintf(
                /* translators: %s: active currency name */
                __( 'The service price was not saved. Enter a whole number in %s, without decimals or separators.', 'neora-core' ),
                $currency['name']
            );
        } else {
            $message = sprintf(
                /* translators: 1: active currency name, 2: max decimal places */
                __( 'The service price was not saved. Enter a valid amount in %1$s with up to %2$d decimal places and no thousand separators.', 'neora-core' ),
                $currency['name'],
                (int) $currency['decimals']
            );
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html( $message );
        echo '</p></div>';
    }

    private static function get_currency_service(): CurrencyService {
        $app = Bootstrap::app();
        if ( $app && $app->container()->has( 'neora.currency' ) ) {
            /** @var CurrencyService $service */
            $service = $app->container()->get( 'neora.currency' );
            return $service;
        }

        return new CurrencyService();
    }

    // -----------------------------------------------------------------------
    // Admin columns
    // -----------------------------------------------------------------------

    /**
     * Modify columns shown in the Services list table.
     *
     * Hooked on a FILTER — must return the modified columns array.
     *
     * @param  array<string, string> $columns
     * @return array<string, string>
     */
    public function add_admin_columns( array $columns ): array {
        $reordered = [];

        foreach ( $columns as $key => $label ) {
            $reordered[ $key ] = $label;
            if ( 'title' === $key ) {
                $reordered['neora_service_code']  = __( 'Service Code', 'neora-core' );
                $reordered['neora_service_price'] = __( 'Price', 'neora-core' );
            }
        }

        return $reordered;
    }

    /**
     * Render the value for each custom column row.
     *
     * Hooked on an ACTION — no return value expected.
     *
     * @param string $column  Column key.
     * @param int    $post_id Current row post ID.
     */
    public function render_admin_column( string $column, int $post_id ): void {
        if ( 'neora_service_price' === $column ) {
            $raw   = (string) get_post_meta( $post_id, '_neora_service_price', true );
            $price = '' !== trim( $raw ) ? self::get_currency_service()->format( $raw ) : '';

            if ( '' !== $price ) {
                echo '<span>' . esc_html( $price ) . '</span>';
            } else {
                echo '<span aria-hidden="true">—</span>';
            }
            return;
        }

        if ( 'neora_service_code' !== $column ) {
            return;
        }

        $code = get_post_meta( $post_id, '_neora_service_code', true );

        if ( is_string( $code ) && '' !== $code ) {
            echo '<code>' . esc_html( $code ) . '</code>';
        } else {
            echo '<span aria-hidden="true">—</span>';
        }
    }
}
