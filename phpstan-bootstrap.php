<?php
/**
 * PHPStan bootstrap — constants the analysed code reads but that are defined outside
 * `includes/`.
 *
 * `timberland-ai-page-builder.php` defines TAIPB_* at load time and is not in `paths`
 * (it is a bootstrap file, not analysable application code). Without these, PHPStan
 * reports nine "Constant ... not found" errors that say nothing about the code — the
 * constants exist, just not in the analysed set.
 *
 * Declared as VALUES, not as stubs of behaviour. Nothing under analysis depends on what
 * these resolve to; the directory and URL constants are given obviously-synthetic values
 * so that a test or an analysis accidentally treating one as a real path fails loudly
 * rather than silently reading from the repository.
 *
 * Mirrors the framework's `phpstan-bootstrap.php`, and the same constants are defined in
 * `tests/bootstrap.php` for the unit suite — the two must agree about what the plugin is.
 */

if ( ! defined( 'TAIPB_VERSION' ) ) {
    define( 'TAIPB_VERSION', '0.0.0-phpstan' );
}

if ( ! defined( 'TAIPB_PLUGIN_FILE' ) ) {
    define( 'TAIPB_PLUGIN_FILE', __DIR__ . '/timberland-ai-page-builder.php' );
}

if ( ! defined( 'TAIPB_PLUGIN_DIR' ) ) {
    define( 'TAIPB_PLUGIN_DIR', __DIR__ . '/' );
}

if ( ! defined( 'TAIPB_PLUGIN_URL' ) ) {
    define( 'TAIPB_PLUGIN_URL', 'https://phpstan.invalid/wp-content/plugins/timberland-ai-page-builder/' );
}

if ( ! defined( 'TAIPB_INCLUDES_DIR' ) ) {
    define( 'TAIPB_INCLUDES_DIR', __DIR__ . '/includes/' );
}
