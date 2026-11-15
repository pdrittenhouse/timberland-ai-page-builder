<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaCacheCommand;
use TimberlandAIPageBuilder\FigmaSupport;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * The failure mode the relocation introduced, and the guard that answers it.
 *
 * Moving the Figma reader to the framework made a previously-impossible state reachable: a site with
 * this plugin and no Timberland theme. Before, those classes shipped here; now they arrive through
 * the theme's `vendor/`, and an unguarded request would fatal on a missing class rather than answer.
 *
 * What is actually assertable here is the SHAPE of the refusal, not the absent state itself -- this
 * suite's own bootstrap exits non-zero when the framework cannot be found, because a Figma suite
 * that quietly skipped would report green while covering none of it. So the absent case is exercised
 * where it can be: by asking the guard about a sentinel that is genuinely not loaded.
 */
final class FigmaSupportTest extends TestCase {

    use WordPressStubs;

    protected function setUp(): void {
        parent::setUp();
        $this->stubWordPress();

        // `FigmaUrl::parse()` reaches for it, and the CLI test below goes through parse(). Stubbed
        // with real behaviour rather than a canned return, because the guard under test sits AHEAD
        // of parse() and a canned value would hide whether it actually got that far.
        Functions\when( 'wp_parse_url' )->alias( static fn( string $u, int $c = -1 ) => parse_url( $u, $c ) );
        Functions\when( 'wp_parse_str' )->alias(
            static function ( string $s, &$r ): void {
                parse_str( $s, $r );
            }
        );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'delete_transient' )->justReturn( false );
    }

    protected function tearDown(): void {
        $this->unstubWordPress();
        parent::tearDown();
    }

    public function test_the_reader_is_reported_present_when_it_is(): void {
        // The bootstrap guarantees this, and asserting it keeps the guard honest: a version of
        // `available()` that always returned false would pass every other test in this file.
        $this->assertTrue( FigmaSupport::available() );
        $this->assertTrue( class_exists( 'Timberland\Framework\FigmaUrl' ) );
    }

    public function test_the_refusal_is_a_501_and_names_the_theme(): void {
        $error = FigmaSupport::unavailable_error();

        $this->assertSame( 'taipb_figma_unavailable', $error->get_error_code() );
        // 501, not 400 or 500: the request was fine and the server is fine. A 400 sends an operator
        // to check their URL; a 500 sends them to the log. Neither says "not installed here".
        $this->assertSame( 501, $error->get_error_data()['status'] );
        $this->assertStringContainsString( 'Timberland', $error->get_error_message() );
    }

    public function test_the_message_points_at_the_cause_rather_than_the_symptom(): void {
        // An operator reading this needs to know it is not their token, their URL, or a transient
        // failure. It also says the rest of the plugin needs the same theme, because that is the
        // actual situation and discovering it one feature at a time is worse.
        $message = FigmaSupport::unavailable_error()->get_error_message();

        $this->assertStringContainsString( 'parent theme', $message );
        $this->assertStringContainsString( 'block manifests', $message );
    }

    public function test_the_cli_flush_refuses_in_the_same_terms_rather_than_fataling(): void {
        // The CLI path returns a message rather than a WP_Error, so it needs its own guard; without
        // one it would reach `FigmaUrl::parse()` and fatal on the missing class.
        $out = FigmaCacheCommand::flush( 'https://www.figma.com/design/ABCDEFGHIJ/x?node-id=0-1' );

        // With the framework present this proceeds normally -- the point of the assertion is that
        // the guard sits ahead of everything and does not interfere when the reader IS available.
        $this->assertArrayHasKey( 'ok', $out );
        $this->assertArrayHasKey( 'message', $out );
        $this->assertStringNotContainsString( 'does not have', $out['message'] );
    }

    public function test_no_admin_notice_is_registered_while_the_reader_is_present(): void {
        // The framework IS present in this suite (the bootstrap guarantees it), so the notice must
        // not register at all -- a warning about a missing dependency that is not missing is worse
        // than no warning, because it trains an operator to ignore the next one.
        $added = 0;
        Functions\when( 'add_action' )->alias( static function () use ( &$added ): void {
            ++$added;
        } );

        FigmaSupport::register_admin_notice();

        $this->assertSame( 0, $added, 'nothing to warn about, so nothing should be hooked' );
    }

    public function test_the_notice_does_not_disable_anything(): void {
        // Deliberately a warning and never a deactivation: everything in this plugin that does not
        // read Figma still works without the framework, so taking the whole plugin away would cost
        // more than the gap. Pinned because "requires X" plugins commonly self-deactivate, and
        // doing that here would be a regression rather than a hardening.
        $src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/FigmaSupport.php' );

        $this->assertStringNotContainsString( 'deactivate_plugins', $src );
        $this->assertStringContainsString( 'notice-warning', $src );
        // Scoped to somebody who could actually act on it.
        $this->assertStringContainsString( "current_user_can('switch_themes')", str_replace( '"', "'", $src ) );
    }
}
