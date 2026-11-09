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

// Asserted, not assumed.
if ( function_exists( 'add_action' ) ) {
	fwrite( STDERR, "WordPress is loaded in the unit suite. These tests must run without it,\n"
		. "or they stop proving the code under test is genuinely standalone.\n" );
	exit( 1 );
}
