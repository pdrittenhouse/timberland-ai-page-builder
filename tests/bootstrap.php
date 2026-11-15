<?php

// WordPress time constants. ManifestStore's cache TTL references DAY_IN_SECONDS at class-constant
// level, so a test touching the store fatals without it.
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}
/**
 * Unit-test bootstrap — pure logic, no WordPress.
 *
 * Mirrors the framework's phase-1 convention (see timberland/tests/php/bootstrap.php): load the
 * Composer autoloader, define the few constants the code reads as VALUES, and assert that
 * WordPress is NOT loaded, so these tests cannot quietly start passing against a real install.
 *
 * `ABSPATH` is the one concession and is not a stub of behaviour: every file in `includes/`
 * opens with `if (!defined('ABSPATH')) { exit; }` — the standard WordPress direct-access guard —
 * so without it the class files terminate the process instead of declaring anything.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	// A path, not a behaviour. Nothing under test reads from it.
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'TAIPB_VERSION' ) ) {
	define( 'TAIPB_VERSION', 'test' );
}

// A minimal `WP_Error`, because the Figma layer returns one rather than throwing -- a REST
// handler has to be able to show the person who pasted a URL why it was refused. Required after
// the autoloader and before the assertion below, which guarantees it is not shadowing a real one.
require_once __DIR__ . '/Fixture/WPErrorDouble.php';

/**
 * The framework's Figma layer, resolved the SAME WAY production resolves it.
 *
 * `FigmaStructure`, `ChromeHints`, `FigmaUrl`, `FigmaDecomposer` and `FigmaClient` moved to
 * `Timberland\Framework\*`. At runtime the theme's `vendor/autoload.php` maps that namespace --
 * verified: it points at the framework's `src/` -- and the plugin's Figma code only executes inside
 * REST callbacks and admin pages, long after `after_setup_theme`, so the mapping is always
 * registered by the time any of it runs.
 *
 * This suite has no WordPress and therefore no theme, so the same files are located directly and
 * registered under the same namespace. Deliberately NOT by requiring the theme's
 * `vendor/autoload.php`: that would drag the theme's whole dependency tree (psysh and friends) into
 * a suite whose point is that it has none, and it would couple this plugin's tests to the theme's
 * composer state rather than to the five files they actually use.
 *
 * Deliberately NOT by adding the framework to this plugin's own `composer.json` either. That would
 * put a SECOND copy of the framework on disk under the plugin's `vendor/`, which at runtime could
 * shadow the theme's -- a divergence hazard strictly worse than the duplication this relocation
 * exists to remove.
 *
 * The theme directory is discovered rather than named: this plugin runs under `timberland-starter`
 * here and would run under a differently-named parent elsewhere.
 */
$taipb_framework_src = null;

foreach ( (array) glob( dirname( __DIR__, 3 ) . '/themes/*/vendor/*/timberland/src', GLOB_ONLYDIR ) as $candidate ) {
	if ( is_file( $candidate . '/FigmaStructure.php' ) ) {
		$taipb_framework_src = $candidate;
		break;
	}
}

if ( null !== $taipb_framework_src ) {
	spl_autoload_register(
		static function ( string $class ) use ( $taipb_framework_src ): void {
			if ( ! str_starts_with( $class, 'Timberland\\Framework\\' ) ) {
				return;
			}

			$relative = substr( $class, strlen( 'Timberland\\Framework\\' ) );
			$path     = $taipb_framework_src . '/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_file( $path ) ) {
				require_once $path;
			}
		}
	);
} else {
	// Loud, not silent. Without the framework the Figma layer cannot load at all, and a suite that
	// quietly skipped would report green while covering none of it.
	fwrite(
		STDERR,
		"The framework's Figma classes could not be found under wp-content/themes/*/vendor/*/timberland/src.\n"
		. "This plugin's Figma layer lives there now; the suite cannot run without it.\n"
	);
	exit( 1 );
}

// Asserted, not assumed.
if ( function_exists( 'add_action' ) ) {
	fwrite( STDERR, "WordPress is loaded in the unit suite. These tests must run without it,\n"
		. "or they stop proving the code under test is genuinely standalone.\n" );
	exit( 1 );
}
