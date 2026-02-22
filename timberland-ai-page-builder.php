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

// Composer autoloader
if (file_exists(TAIPB_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once TAIPB_PLUGIN_DIR . 'vendor/autoload.php';
}

// Plugin bootstrap (classes autoloaded via Composer PSR-4)
TimberlandAIPageBuilder\Plugin::instance();
