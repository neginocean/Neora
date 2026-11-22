<?php
/**
 * Portfolio Module
 *
 * Registers the Portfolio CPT, taxonomy, meta fields, and admin meta box.
 * Follows the ServicesModule reference pattern exactly.
 *
 * @package NeoraStudio\NeoraCore
 */

declare( strict_types=1 );

namespace NeoraStudio\NeoraCore\Modules\Portfolio;

use NeoraStudio\NeoraCore\Core\Container;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Core\MetaHelper;
use NeoraStudio\NeoraCore\Core\ModuleMetadataInterface;
use NeoraStudio\NeoraCore\Core\RewriteAwareInterface;

/**
 * Class PortfolioModule
 */
class PortfolioModule implements ModuleMetadataInterface, RewriteAwareInterface {

    /** Module identifier. */
    public const KEY = 'neora/portfolio';

    /** Custom post type slug. */
    public const CPT = 'neora_portfolio';

    /** Taxonomy slug. */
    public const TAX = 'portfolio_category';

    /** Nonce field name. */
    public const NONCE_NAME = '_neora_meta_nonce';

    // -------------------------------------------------------------------------
    // ModuleInterface
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function get_key(): string {
        return self::KEY;
    }

    public function get_name(): string {
		return __( 'Portfolio', 'neora-core' );
	}

	public function get_description(): string {
		return __( 'Registers the Portfolio post type, categories, and client project metadata.', 'neora-core' );
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
    /**
     * {@inheritdoc}
     *
     * Intentionally empty — CPT/taxonomy/meta registration is deferred
     * to the init hook inside boot(), matching the ServicesModule contract.
     */
    public function register( Container $container ): void {}

    /**
     * {@inheritdoc}
     *
     * All hooks are registered here. CPT, taxonomy, and meta fields are
     * deferred to init so they respect the WordPress load order.
     */
    public function boot( HookRegistry $hooks ): void {
        $hooks->action( 'init', [ $this, 'register_post_type' ] );
        $hooks->action( 'init', [ $this, 'register_taxonomy' ] );
        $hooks->action( 'init', [ $this, 'register_meta_fields' ] );

        $hooks->action( 'add_meta_boxes_' . self::CPT, [ $this, 'add_meta_box' ] );
        $hooks->action( 'save_post_' . self::CPT,      [ $this, 'save_meta' ] );
    }

    // -------------------------------------------------------------------------
    // CPT / Taxonomy
    // -------------------------------------------------------------------------

    /**
     * Registers the neora_portfolio custom post type.
     *
     * Called via the init hook registered in boot().
     */
    public function register_post_type(): void {
        $labels = [
            'name'               => __( 'Portfolio', 'neora-core' ),
            'singular_name'      => __( 'Portfolio Item', 'neora-core' ),
            'add_new'            => __( 'Add New', 'neora-core' ),
            'add_new_item'       => __( 'Add New Portfolio Item', 'neora-core' ),
            'edit_item'          => __( 'Edit Portfolio Item', 'neora-core' ),
            'new_item'           => __( 'New Portfolio Item', 'neora-core' ),
            'view_item'          => __( 'View Portfolio Item', 'neora-core' ),
            'search_items'       => __( 'Search Portfolio', 'neora-core' ),
            'not_found'          => __( 'No portfolio items found', 'neora-core' ),
            'not_found_in_trash' => __( 'No portfolio items found in trash', 'neora-core' ),
            'menu_name'          => __( 'Portfolio', 'neora-core' ),
        ];

        register_post_type( self::CPT, [
            'labels'       => $labels,
            'public'       => true,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-portfolio',
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'has_archive'  => true,
            'rewrite'      => [ 'slug' => 'portfolio' ],
        ] );
    }

    /**
     * Registers the portfolio_category taxonomy.
     *
     * Called via the init hook registered in boot().
     */
    public function register_taxonomy(): void {
        $labels = [
            'name'              => __( 'Portfolio Categories', 'neora-core' ),
            'singular_name'     => __( 'Portfolio Category', 'neora-core' ),
            'search_items'      => __( 'Search Categories', 'neora-core' ),
            'all_items'         => __( 'All Categories', 'neora-core' ),
            'parent_item'       => __( 'Parent Category', 'neora-core' ),
            'parent_item_colon' => __( 'Parent Category:', 'neora-core' ),
            'edit_item'         => __( 'Edit Category', 'neora-core' ),
            'update_item'       => __( 'Update Category', 'neora-core' ),
            'add_new_item'      => __( 'Add New Category', 'neora-core' ),
            'new_item_name'     => __( 'New Category Name', 'neora-core' ),
            'menu_name'         => __( 'Categories', 'neora-core' ),
        ];

        register_taxonomy( self::TAX, [ self::CPT ], [
            'labels'       => $labels,
            'hierarchical' => true,
            'show_in_rest' => true,
            'rewrite'      => [ 'slug' => 'portfolio-category' ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Meta Schema
    // -------------------------------------------------------------------------

    /**
     * Registers meta fields for the portfolio post type.
     * Delegates to MetaHelper::register_fields() — no field logic lives here.
     *
     * Called via the init hook registered in boot(), after register_post_type().
     */
    public function register_meta_fields(): void {
        MetaHelper::register_fields(
            self::CPT,
            self::field_schema(),
            MetaHelper::make_edit_post_auth_cb()
        );
    }

    /**
     * Canonical meta field definitions consumed by register_meta_fields(),
     * render_meta_box(), and save_meta() via MetaHelper::save_fields().
     *
     * Required keys per MetaHelper contract:
     *   meta_key    – WordPress meta key stored in postmeta (prefixed _neora_portfolio_).
     *   post_key    – HTML <input name> and $_POST key.
     *   type        – One of: string | boolean | integer | number | array | object.
     *   sanitize_cb – Callable used by both register_meta() and MetaHelper::save_fields().
     *
     * @return array<int, array{meta_key: string, post_key: string, type: string, label: string, sanitize_cb: callable-string|array}>
     */
    public static function field_schema(): array {
        return [
            [
                'meta_key'    => '_neora_portfolio_client_name',
                'post_key'    => 'neora_portfolio_client_name',
                'type'        => 'string',
                'label'       => __( 'Client Name', 'neora-core' ),
                'sanitize_cb' => 'sanitize_text_field',
            ],
            [
                'meta_key'    => '_neora_portfolio_project_url',
                'post_key'    => 'neora_portfolio_project_url',
                'type'        => 'string',
                'dir' => 'ltr',
                'label'       => __( 'Project URL', 'neora-core' ),
                'sanitize_cb' => 'esc_url_raw',
            ],
            [
                'meta_key'    => '_neora_portfolio_completion_year',
                'post_key'    => 'neora_portfolio_completion_year',
                'type'        => 'integer',
                'inputmode' => 'numeric',
                'dir' => 'ltr',
                'label'       => __( 'Completion Year', 'neora-core' ),
                'sanitize_cb' => [ self::class, 'sanitize_year' ],
            ],
           [
                'meta_key'    => '_neora_portfolio_short_description',
                'post_key'    => 'neora_portfolio_short_description',
                'type'        => 'string',
                'input_type'  => 'textarea',
                'label'       => __( 'Short Description', 'neora-core' ),
                'sanitize_cb' => 'sanitize_textarea_field',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Meta Box
    // -------------------------------------------------------------------------

    /**
     * Registers the meta box on the CPT edit screen.
     *
     * context='normal' per project policy (classic meta box).
     */
    public function add_meta_box(): void {
        add_meta_box(
            'neora_portfolio_meta',
            __( 'Portfolio Details', 'neora-core' ),
            [ $this, 'render_meta_box' ],
            self::CPT,
            'normal',
            'default'
        );
    }

    /**
     * Renders the meta box fields.
     *
     * @param \WP_Post $post Current post object.
     */
  public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'neora_save_meta_' . $post->ID, self::NONCE_NAME );
		?>
		<div class="neora-metabox-grid">
			<?php foreach ( self::field_schema() as $field ) : ?>
				<?php
				$value   = (string) get_post_meta( $post->ID, $field['meta_key'], true );
				$is_full = '_neora_portfolio_short_description' === $field['meta_key'];
				?>
				<div class="neora-metabox-field <?php echo $is_full ? 'neora-metabox-field--full' : ''; ?>">
					<label for="<?php echo esc_attr( $field['post_key'] ); ?>">
						<?php echo esc_html( $field['label'] ); ?>
					</label>
					<?php if ( 'textarea' === ( $field['input_type'] ?? 'text' ) ) : ?>
						<textarea
							id="<?php echo esc_attr( $field['post_key'] ); ?>"
							name="<?php echo esc_attr( $field['post_key'] ); ?>"
							rows="3"
						><?php echo esc_textarea( $value ); ?></textarea>
					<?php else : ?>
						<input
							type="text"
							id="<?php echo esc_attr( $field['post_key'] ); ?>"
							name="<?php echo esc_attr( $field['post_key'] ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							<?php if ( ! empty( $field['inputmode'] ) ) : ?>
								inputmode="<?php echo esc_attr( $field['inputmode'] ); ?>"
							<?php endif; ?>
							<?php if ( ! empty( $field['dir'] ) ) : ?>
								dir="<?php echo esc_attr( $field['dir'] ); ?>"
							<?php endif; ?>
						/>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

    // -------------------------------------------------------------------------
    // Save
    // -------------------------------------------------------------------------

    /**
     * Saves meta fields when the post is saved.
     *
     * Guards applied in order: nonce → autosave → revision → capability.
     *
     * @param int $post_id Post ID passed by save_post_{CPT}.
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

        MetaHelper::save_fields( $post_id, self::field_schema(), $_POST );
    }

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    /**
     * Sanitizes a four-digit year value.
     *
     * Returns an empty string to trigger meta deletion when the year is
     * outside the valid range, matching WordPress meta cleanup convention.
     *
     * @param  mixed      $value Raw input.
     * @return int|string        Integer year (1000–9999) or '' to delete the meta.
     */
    public static function sanitize_year( mixed $value ): int|string {
        if ( ! is_string( $value ) && ! is_int( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );
        if ( ! preg_match( '/^[1-9][0-9]{3}$/D', $value ) ) {
            return '';
        }

        $year = (int) $value;

        if ( $year < 1000 || $year > 9999 ) {
            return '';
        }

        return $year;
    }
}
