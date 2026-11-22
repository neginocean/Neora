<?php
/**
 * Plugin Name:       Neora Core
 * Plugin URI:        https://neorastudio.ir
 * Description:       The modular foundation platform powering Neora Studio websites.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Neora Studio
 * Author URI:        https://neorastudio.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       neora-core
 * Domain Path:       /languages
 */

declare(strict_types=1);

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// Core constants
// -------------------------------------------------------------------------

define( 'NEORA_CORE_VERSION', '0.1.0' );
define( 'NEORA_CORE_FILE',    __FILE__ );
define( 'NEORA_CORE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'NEORA_CORE_URL',     plugin_dir_url( __FILE__ ) );

// -------------------------------------------------------------------------
// Internal PSR-4-style autoloader
// Maps NeoraStudio\NeoraCore\ → src/
// -------------------------------------------------------------------------

spl_autoload_register( function ( string $class ): void {
    $prefix = 'NeoraStudio\\NeoraCore\\';
    $base   = NEORA_CORE_PATH . 'src/';

    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        // Not our namespace — let another autoloader handle it.
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $file     = $base . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

    if ( is_file( $file ) ) {
        require $file;
    }
    // Fail-safe: if file is missing we do nothing and let PHP throw
    // a class-not-found error with a clear message.
} );

// -------------------------------------------------------------------------
// Global helper functions
// Loaded after the autoloader so helpers can reference autoloaded classes,
// but before booting so they are available throughout the init sequence.
// -------------------------------------------------------------------------

$functions_file = NEORA_CORE_PATH . 'src/Support/Functions.php';
if ( is_file( $functions_file ) ) {
    require_once $functions_file;
}
unset( $functions_file );

// -------------------------------------------------------------------------
// Activation / deactivation hooks
// Must be registered before the plugin boots so WordPress can call them
// even if the full application has not yet initialised.
// -------------------------------------------------------------------------

register_activation_hook(
    NEORA_CORE_FILE,
    function (): void {
        \NeoraStudio\NeoraCore\Core\Bootstrap::activate();
    }
);

register_deactivation_hook(
    NEORA_CORE_FILE,
    function (): void {
        \NeoraStudio\NeoraCore\Core\Bootstrap::deactivate();
    }
);

// -------------------------------------------------------------------------
// Boot the plugin
// -------------------------------------------------------------------------

add_action( 'plugins_loaded', function (): void {
    \NeoraStudio\NeoraCore\Core\Bootstrap::init();
} );
