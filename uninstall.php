<?php

/**
 * Cleanup on plugin uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('taipb_settings');
delete_option('taipb_manifest');
delete_option('taipb_usage_notes');

// Remove transient cache
delete_transient('taipb_manifest_cache');

// Drop history table
global $wpdb;
$table_name = $wpdb->prefix . 'taipb_history';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
