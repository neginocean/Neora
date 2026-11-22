<?php
/**
 * Neora Core — Uninstall handler.
 *
 * WordPress calls this file when the plugin is deleted from the admin.
 * It must never be executed directly.
 *
 * Future milestones will add real cleanup here (custom tables, options, etc.)
 * as each piece of data is introduced. Every removal will be documented
 * alongside the migration that created the data.
 */

declare(strict_types=1);

// WordPress sets this constant before including this file.
// If it is not set, someone is accessing the file directly — bail out.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// -------------------------------------------------------------------
// Placeholder — no persistent data exists yet.
//
// When data is added in later milestones, removals will follow this
// pattern to keep cleanup readable:
//
//   // Remove plugin options.
//   delete_option( 'neora_core_settings' );
//
//   // Drop custom tables (example — not added yet).
//   global $wpdb;
//   $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}neora_leads" );
//
// -------------------------------------------------------------------
delete_option( 'neora_sms_settings' );
delete_option( 'neora_core_general_settings' );
delete_option( 'neora_core_regional_settings' );
delete_option( 'neora_active_modules' );
delete_option( 'neora_flush_rewrite_rules' );
delete_option( 'neora_wc_settings' );
delete_option( 'neora_core_db_version' );
delete_option( 'neora_core_migrations' );
delete_transient( 'neora_core_migration_lock' );
delete_option( 'neora_sms_queue' );
delete_transient( 'neora_sms_queue_lock' );
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'neora_sms_process_queue' );
}
