<?php

/**
 * Plugin Name: Timberland AI Page Builder
 * Description: Generate page/post layouts from natural language using Claude AI. Integrates with ACF blocks, Genesis layouts, and editor patterns.
 * Version: 1.1.0
 * Requires PHP: 8.1
 * Author: Updater
 * Text Domain: timberland-ai-page-builder
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('TAIPB_VERSION', '1.1.0');
define('TAIPB_PLUGIN_FILE', __FILE__);
define('TAIPB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAIPB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TAIPB_INCLUDES_DIR', TAIPB_PLUGIN_DIR . 'includes/');

// Raise memory limit for admin and REST API requests.
// Block parsing (parse_blocks) in WordPress core can be very memory-intensive
// with large generated layouts. This filter applies to wp-admin page loads
// and REST API dispatch via wp_raise_memory_limit('admin').
add_filter('admin_memory_limit', function () {
    return '1024M';
});

// Prevent WordPress from running do_blocks()/parse_blocks() on post
// content during block editor REST requests. The block editor only needs
// the raw markup (delivered via `content.raw`). The rendered HTML
// (`content.rendered`) triggers do_blocks() which calls parse_blocks() —
// on large posts this can exhaust PHP memory.
//
// Scoped to `context=edit` requests only, so non-editor REST consumers
// (headless frontends, custom integrations) still get fully rendered HTML.
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($request->get_param('context') === 'edit') {
        remove_filter('the_content', 'do_blocks', 9);
    }
    return $result;
}, 10, 3);

// Composer autoloader
if (file_exists(TAIPB_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once TAIPB_PLUGIN_DIR . 'vendor/autoload.php';
}

// Plugin bootstrap (classes autoloaded via Composer PSR-4)
TimberlandAIPageBuilder\Plugin::instance();
