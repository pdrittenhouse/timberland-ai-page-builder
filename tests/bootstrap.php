<?php
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

// Asserted, not assumed.
if ( function_exists( 'add_action' ) ) {
	fwrite( STDERR, "WordPress is loaded in the unit suite. These tests must run without it,\n"
		. "or they stop proving the code under test is genuinely standalone.\n" );
	exit( 1 );
}
