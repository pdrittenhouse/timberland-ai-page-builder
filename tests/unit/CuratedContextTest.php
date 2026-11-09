<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\CuratedContext;

/**
 * `CuratedContext` — the ONLY thing this plugin reads from the framework's build.
 *
 * The boundary matters more than the parsing. WordPress's registry is the authority on which
 * blocks exist and ACF on their field shapes; the build is the authority on editorial judgement
 * that cannot be derived from a registration — `best_for` / `avoid_when`. These tests pin that
 * split, so a later change cannot quietly start reading block identity from a build artifact
 * again, which is the mistake this replaced.
 */
final class CuratedContextTest extends TestCase {

    private function context(): CuratedContext {
        return new CuratedContext( dirname( __DIR__ ) . '/Fixture/manifests' );
    }

    public function test_it_reads_curated_prose_keyed_by_bare_slug(): void {
        $index = $this->context()->selection_index();

        $this->assertArrayHasKey( 'section', $index );
        $this->assertArrayHasKey( 'card', $index );
        $this->assertSame(
            'A short teaser linking to a longer page.',
            $index['card']['best_for']
        );
    }

    public function test_it_translates_a_registered_block_name_to_the_indexs_bare_slug(): void {
        // The index stores `card`; the registry calls it `acf/card`. Callers should never have
        // to know that, and a translation bug here produces empty usage notes for every block
        // with nothing failing anywhere.
        $notes = $this->context()->usage_notes_for( 'acf/card' );

        $this->assertStringContainsString( 'Best for: A short teaser', $notes );
        $this->assertStringContainsString( 'Avoid when:', $notes );
    }

    public function test_a_block_the_index_does_not_cover_gets_no_notes_and_no_error(): void {
        // Expected, not exceptional: the index carries the framework's 41 blocks, so a plugin-
        // or theme-tier block has none until someone writes its documentation. It stays
        // selectable on the registry's title and description; it just competes without the
        // editorial steer.
        $this->assertSame( '', $this->context()->usage_notes_for( 'acf/updater-forms' ) );
        $this->assertSame( array(), $this->context()->missing() );
    }

    public function test_a_missing_artifact_is_reported_rather_than_treated_as_no_curation(): void {
        // This plugin is not standalone — it ships against the framework, whose build emits
        // these. A missing file is a broken install, and silently returning "no curation"
        // degrades every prompt with nothing to show for it.
        $context = new CuratedContext( dirname( __DIR__ ) . '/Fixture/does-not-exist' );

        $this->assertSame( array(), $context->selection_index() );
        $this->assertCount( 1, $context->missing() );
        $this->assertStringContainsString( 'ai-selection-index.json', $context->missing()[0] );
    }

    public function test_unreadable_json_is_reported_the_same_way_as_a_missing_file(): void {
        // A truncated or half-written artifact is the more likely failure of the two, and it
        // must not look like success with an empty index.
        $dir = sys_get_temp_dir() . '/taipb-curation-' . uniqid();
        mkdir( $dir );
        file_put_contents( $dir . '/ai-selection-index.json', '{"entries": [' );

        try {
            $context = new CuratedContext( $dir );
            $this->assertSame( array(), $context->selection_index() );
            $this->assertCount( 1, $context->missing() );
        } finally {
            unlink( $dir . '/ai-selection-index.json' );
            rmdir( $dir );
        }
    }
}
