<?php
/**
 * Neora Core — MetaHelper.
 *
 * Shared utility for schema-driven post meta registration and save.
 * Stateless; all public methods are static.
 *
 * Caller contract for save_fields():
 *  - Pass the slashed form source (typically $_POST), including its nonce.
 *  - This helper also checks nonce, capability, autosave and revision guards.
 *  - Sanitizers accept unslashed values, including when called by WordPress.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

final class MetaHelper {        /**
 * Log a schema error without breaking the whole loop.
 */
    private static function log_schema_error( string $message ): void {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    error_log( $message );
    }

    /**
     * Required keys every schema item must declare.
     *
     * @var string[]
     */
    private const REQUIRED_KEYS = [ 'meta_key', 'post_key', 'type', 'sanitize_cb' ];

    // Prevent instantiation — this is a static utility class.
    private function __construct() {}
    
    /**
 * Build an object-aware auth callback for register_post_meta().
 *
 * This mirrors save-path capability checks by enforcing:
 * current_user_can( 'edit_post', $object_id ).
 *
 * Least-privilege default:
 * - If $object_id <= 0, deny access.
 *
 * @return callable
 */ 
public static function make_edit_post_auth_cb(): callable {
    return static function (
        bool $allowed,
        string $meta_key,
        int $object_id,
        int $user_id = 0,
        string $cap = '',
        array $caps = []
    ): bool {
        return $object_id > 0 && current_user_can( 'edit_post', $object_id );
    };
}
 

    /**
     * Register post meta fields from a schema array.
     *
     * Iterates the schema and calls register_post_meta() for each field.
     * The `description` key is optional; omitting it registers an empty string.
     *
     * @param string   $post_type Post type slug.
     * @param array    $schema    Field definitions — see field_schema() in each module.
     * @param callable $auth_cb   Capability callback passed as `auth_callback` to every field.
     *
     * @throws \InvalidArgumentException If any schema item is missing a required key.
     */
  /**
 * @param string        $post_type     The post type slug.
 * @param array         $fields        Array of field schema definitions.
 * @param callable|null $auth_callback Optional override; defaults to edit_post capability check.
 */
public static function register_fields( string $post_type, array $fields, ?callable $auth_callback = null ): void {
    $auth_callback = $auth_callback ?? self::make_edit_post_auth_cb();

    foreach ( $fields as $field ) {
        try {
            self::assert_schema( $field );

            register_meta( 'post', $field['meta_key'], [
                'type'              => $field['type'],
                'single'            => true,
                'description'       => $field['description'] ?? '',
                'sanitize_callback' => $field['sanitize_cb'],
                'auth_callback'     => $auth_callback,
                'show_in_rest'      => $field['show_in_rest'] ?? false,
                'object_subtype'    => $post_type,
            ] );
        } catch ( \InvalidArgumentException $e ) {
            self::log_schema_error( $e->getMessage() );
            continue;
        }
    }
}

    /**
     * Persist schema-defined meta fields from a source array.
     *
     * Save contract (identical to the previous per-module behavior):
     *  - Sanitized empty string → delete_post_meta.
     *    Keeps the meta table clean; register_post_meta's `default` applies on read.
     *  - Non-empty sanitized value → update_post_meta.
     *
     * Security contract: nonce, capability, autosave and revision guards are
     * checked here even when the calling module has already checked them.
     *
     * @param int   $post_id Post ID being saved.
     * @param array $schema  Same schema array used in register_fields().
     * @param array $source  Slashed form source, including nonce, typically $_POST.
     *
     * @throws \InvalidArgumentException If any schema item is missing a required key.
     */
public static function save_fields( int $post_id, array $fields, array $extra = [] ): void {
    // ۱. nonce
    if (
        ! isset( $extra['_neora_meta_nonce'] ) ||
        ! is_string( $extra['_neora_meta_nonce'] ) ||
        ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( (string) $extra['_neora_meta_nonce'] ) ),
            'neora_save_meta_' . $post_id
        )
    ) {
        return;
    }

    // ۲. autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // ۳. revision
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // ۴. capability
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    foreach ( $fields as $field ) {
        try {
            self::assert_schema( $field );

            $raw = $extra[ $field['post_key'] ] ?? '';
            // Malformed scalar input must not overwrite an existing value.
            if ( ! in_array( $field['type'], [ 'array', 'object' ], true ) && ! is_scalar( $raw ) ) {
                continue;
            }
            $value = call_user_func( $field['sanitize_cb'], wp_unslash( $raw ) );

            if ( '' === $value || null === $value ) {
                delete_post_meta( $post_id, $field['meta_key'] );
            } else {
                // WordPress unslashes metadata before running its sanitizer.
                update_post_meta( $post_id, $field['meta_key'], wp_slash( $value ) );
            }
        } catch ( \InvalidArgumentException $e ) {
            self::log_schema_error( $e->getMessage() );
            continue;
        }
    }
}





    /**
     * Assert that a schema item contains all required keys.
     *
     * @param array $field A single schema item from field_schema().
     *
     * @throws \InvalidArgumentException If a required key is absent.
     */
   private static function assert_schema( array $field ): void {
    $required = [ 'meta_key', 'post_key', 'type', 'sanitize_cb' ];

    foreach ( self::REQUIRED_KEYS as $key ) {
        if ( empty( $field[ $key ] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'MetaHelper: schema field missing required key "%s".', $key )
            );
        }
    }



    // Validate WP-supported meta types.
    $valid_types = [ 'string', 'boolean', 'integer', 'number', 'array', 'object' ];
    if ( ! in_array( $field['type'], $valid_types, true ) ) {
        throw new \InvalidArgumentException(
            sprintf(
                'MetaHelper: field "%s" has unrecognized type "%s". Accepted: %s.',
                $field['meta_key'],
                $field['type'],
                implode( ', ', $valid_types )
            )
        );
    }

    // Validate sanitize callback is callable.
    if ( ! is_callable( $field['sanitize_cb'] ) ) {
        throw new \InvalidArgumentException(
            sprintf(
                'MetaHelper: field "%s" has uncallable sanitize_cb (%s).',
                $field['meta_key'],
                is_string( $field['sanitize_cb'] )
                    ? '"' . $field['sanitize_cb'] . '"'
                    : gettype( $field['sanitize_cb'] )
            )
        );
    }
}

}
