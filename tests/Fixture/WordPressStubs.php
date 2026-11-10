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

    /**
     * The handful of extra WordPress functions the generation pipeline touches.
     *
     * Kept separate from `stubWordPress()` so a test declares what it actually needs: a prompt
     * builder reaching `get_current_user_id()` would be a surprise worth seeing fail.
     */
    protected function stubGenerationPipeline(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_json_encode' )->alias(
            static fn( $data, int $flags = 0, int $depth = 512 ) => json_encode( $data, $flags, $depth )
        );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( '__' )->returnArg();

        // Access control. Granted here because the eval suite is about what happens to a
        // model's response once a permitted user has asked -- a capability test belongs with
        // the capability check, and silently granting it there would be the bug.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_userdata' )->justReturn( false );
        // Fixed, not `now`: a timestamp that moves makes a recorded row unassertable.
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        // `save_history()` writes a row with $wpdb, which does not exist here. A double that
        // RECORDS the insert rather than discarding it, so a test can assert history is written
        // -- and so a silent failure to write does not look like success.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @var array<int, array<string, mixed>> */
            public array $inserted = array();

            /**
             * @param array<string, mixed> $data
             * @param array<int, string>   $format
             */
            public function insert( string $table, array $data, array $format = array() ): int {
                $this->inserted[] = array( 'table' => $table, 'data' => $data );

                return 1;
            }

            public function prepare( string $query, ...$args ): string {
                return $query;
            }

            /** @return array<int, mixed> */
            public function get_results( string $query, $output = null ): array {
                return array();
            }

            public function get_var( string $query ) {
                return null;
            }
        };

        // `user_can_generate()` intersects the user's roles against an allow-list, so the stub
        // has to carry a roles array rather than just be truthy.
        Functions\when( 'wp_get_current_user' )->alias(
            static function (): object {
                $user = new \stdClass();
                $user->ID = 1;
                $user->roles = array( 'administrator' );

                return $user;
            }
        );
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
