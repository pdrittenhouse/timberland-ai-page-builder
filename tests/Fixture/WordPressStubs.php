<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Fixture;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * The two WordPress functions the prompt builders reach for, and nothing else.
 *
 * All three builders take a plain manifest array and look WordPress-free from their
 * constructors, but `build()` calls `Plugin::get_settings()` for the operator's
 * `custom_system_prompt`, which is `get_option()` + `wp_parse_args()`. That is the whole
 * surface — stubbing more would be inventing coupling that does not exist.
 *
 * `wp_parse_args()` is given real merge behaviour rather than a canned return, because the
 * builders read a specific key out of the merged result; a `justReturn([])` stub would make
 * every settings-dependent branch vanish and the tests would pass without exercising them.
 *
 * The unit bootstrap asserts WordPress is NOT loaded. That assertion runs once before any
 * test, so these per-test stubs do not violate it — and that is the point: the suite proves
 * the code is standalone, and names its WordPress dependencies here explicitly instead of
 * inheriting a real install.
 */
trait WordPressStubs {

    /**
     * @param array<string, mixed> $settings values for the `taipb_settings` option.
     */
    protected function stubWordPress( array $settings = array() ): void {
        Monkey\setUp();

        Functions\when( 'get_option' )->alias(
            static function ( string $name, $default = false ) use ( $settings ) {
                return 'taipb_settings' === $name ? $settings : $default;
            }
        );

        Functions\when( 'wp_parse_args' )->alias(
            static function ( $args, $defaults = array() ): array {
                return array_merge(
                    is_array( $defaults ) ? $defaults : array(),
                    is_array( $args ) ? $args : array()
                );
            }
        );
    }

    /**
     * The theme-location functions `SchemaOracle::locate()` uses to find a tier root.
     *
     * Pointed at a fixture directory rather than a real theme. `locate()` derives candidate
     * roots from the tier NAME the build recorded and checks each against the filesystem, so a
     * test for "this tier does not exist here" needs the lookup to actually run and find
     * nothing — stubbing it to return a canned answer would skip the logic under test.
     */
    protected function stubThemeDirs( ?string $template = null, ?string $stylesheet = null ): void {
        $template = $template ?? dirname( __DIR__ ) . '/Fixture/theme';
        $stylesheet = $stylesheet ?? $template;

        Functions\when( 'get_template_directory' )->justReturn( $template );
        Functions\when( 'get_stylesheet_directory' )->justReturn( $stylesheet );

        if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
            define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/Fixture/plugins' );
        }
    }

    protected function unstubWordPress(): void {
        Monkey\tearDown();
    }

    /**
     * The framework's token estimator, reproduced exactly.
     *
     * `webpack-plugins/lib/ai-manifest.js` uses `Math.ceil(String(text).length / 4)` and the
     * selection index's committed ceiling is enforced against that number. A ratchet on this
     * side has to count the same way or the two figures cannot be compared, and "tokens" stops
     * meaning one thing across the stack.
     */
    protected function estimateTokens( string $text ): int {
        return (int) ceil( strlen( $text ) / 4 );
    }
}
