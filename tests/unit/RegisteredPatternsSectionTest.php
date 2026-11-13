<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\PromptBuilder;

/**
 * UC8's consumption half: the REGISTERED PATTERNS catalogue reaching a prompt.
 *
 * The vocabulary was registered and indexed for a release without ANYTHING reading it. The only
 * reference to `ai-block-patterns.json` anywhere was the line that writes it, and the plugin had no
 * `WP_Block_Patterns_Registry` reference outside vendored stubs. So these tests exist to pin the
 * consumption, not the registration -- the half that was missing.
 *
 * Two bugs this file would have caught, both found by hand instead:
 *
 *   1. `BlockPatternIndex::collect()` returns an ENVELOPE (`['patterns' => rows, 'counts' => ...]`),
 *      and the manifest was handed the envelope. Two array keys sat where nine patterns belonged,
 *      and because the array was non-empty nothing looked wrong.
 *   2. The section silently truncated at the token budget, dropping the three patterns it exists
 *      for -- slider, table and tabs, which are alphabetically last. A budget that cuts the payload
 *      and says nothing is worse than one that refuses.
 *
 * Core's eleven patterns are excluded by provenance, which is why the framework labels provenance at
 * all: eleven irrelevant options plus nine useful ones is worse for a model than none.
 */
final class RegisteredPatternsSectionTest extends TestCase {

	/**
	 * @param  string $name        Pattern slug.
	 * @param  string $provenance  `registered` for ours, `core` for WordPress's.
	 * @return array               One index row.
	 */
	private function row( string $name, string $provenance = 'registered', string $description = 'A description.' ): array {
		return [
			'name'        => $name,
			'title'       => 'Title of ' . $name,
			'description' => $description,
			'provenance'  => $provenance,
			'blocks_used' => [ 'acf/section', 'acf/row' ],
		];
	}

	private function section( array $rows ): string {
		$builder = new PromptBuilder( [ 'block_patterns' => $rows ] );
		$method  = new \ReflectionMethod( $builder, 'build_block_patterns_section' );
		$method->setAccessible( true );

		return (string) $method->invoke( $builder );
	}

	public function test_lists_our_patterns(): void {
		$section = $this->section( [ $this->row( 'timberland/slider-3-slide' ) ] );

		$this->assertStringContainsString( 'REGISTERED PATTERNS', $section );
		$this->assertStringContainsString( 'timberland/slider-3-slide', $section );
	}

	public function test_excludes_core_patterns(): void {
		$section = $this->section(
			[
				$this->row( 'timberland/row-2-column' ),
				$this->row( 'core/query-standard-posts', 'core' ),
				$this->row( 'core/navigation-overlay', 'core' ),
			]
		);

		$this->assertStringContainsString( 'timberland/row-2-column', $section );
		$this->assertStringNotContainsString( 'core/query-standard-posts', $section );
		$this->assertStringNotContainsString( 'core/navigation-overlay', $section );
	}

	public function test_is_empty_when_nothing_of_ours_is_registered(): void {
		$this->assertSame( '', $this->section( [ $this->row( 'core/query-standard-posts', 'core' ) ] ) );
		$this->assertSame( '', $this->section( [] ) );
	}

	public function test_an_envelope_instead_of_rows_produces_nothing_rather_than_nonsense(): void {
		// The exact shape the bug produced: collect()'s return value passed through whole.
		$envelope = [ 'patterns' => [ $this->row( 'timberland/row-2-column' ) ], 'counts' => [ 'registered' => 1 ] ];

		$this->assertSame(
			'',
			$this->section( $envelope ),
			'An envelope has no row with provenance "registered", so the section must be empty. '
				. 'If this ever returns content, the unwrapping in ManifestBuilder has been reintroduced wrongly.'
		);
	}

	public function test_description_is_trimmed_to_its_first_sentence(): void {
		$section = $this->section(
			[
				$this->row(
					'timberland/accordion-3-item',
					'registered',
					'An accordion with three collapsible items. `acf/accordion-item` declares `acf/accordion` as its parent.'
				),
			]
		);

		$this->assertStringContainsString( 'An accordion with three collapsible items.', $section );
		$this->assertStringNotContainsString(
			'declares `acf/accordion` as its parent',
			$section,
			'The parent rule is already in the prompt\'s NESTING section; paying for it twice cost three patterns their place.'
		);
	}

	public function test_truncation_is_announced_rather_than_silent(): void {
		$rows = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$rows[] = $this->row( 'timberland/synthetic-' . $i );
		}

		$section = $this->section( $rows );

		$this->assertStringContainsString(
			'not listed here',
			$section,
			'A budget that drops entries must say so — silent truncation dropped the three patterns this section exists for.'
		);
		$this->assertLessThanOrEqual(
			600,
			(int) ceil( strlen( $section ) / 4 ),
			'The catalogue must stay inside its budget; the blocks section takes what is left of the allowance.'
		);
	}

	public function test_long_block_lists_are_capped_not_dropped(): void {
		$row                = $this->row( 'core/query/card' );
		$row['blocks_used'] = [
			'core/query',
			'core/post-template',
			'acf/card',
			'core/post-featured-image',
			'core/post-date',
			'core/post-title',
			'core/post-excerpt',
		];

		$section = $this->section( [ $row ] );

		$this->assertStringContainsString( 'core/query`', $section );
		$this->assertStringContainsString( '(+2 more)', $section );
		$this->assertStringNotContainsString( 'core/post-excerpt', $section );
	}

	/**
	 * `resolve_base_pattern()` — the three id shapes, and the fourth that is not one.
	 *
	 * Extracted from two methods that held it verbatim. Adding `blockpattern_` to one copy and not
	 * the other is this stack's recurring failure: the capability lands, one call site gets it, and
	 * every test still passes. Both call sites now share this, so these cover both.
	 */
	private function resolve( array $manifest, string $id ): array {
		$builder = new PromptBuilder( $manifest );
		$method  = new \ReflectionMethod( $builder, 'resolve_base_pattern' );
		$method->setAccessible( true );

		return (array) $method->invoke( $builder, $id );
	}

	public function test_resolves_a_saved_editor_pattern_by_post_id(): void {
		$manifest = [ 'patterns' => [ [ 'id' => 42, 'title' => 'Saved', 'content' => '<!-- wp:paragraph /-->' ] ] ];

		$this->assertSame( [ '<!-- wp:paragraph /-->', 'Saved' ], $this->resolve( $manifest, 'pattern_42' ) );
	}

	public function test_resolves_a_layout_by_index(): void {
		$manifest = [ 'layouts' => [ [ 'name' => 'Full', 'content' => '<!-- wp:spacer /-->' ] ] ];

		$this->assertSame( [ '<!-- wp:spacer /-->', 'Full' ], $this->resolve( $manifest, 'layout_0' ) );
	}

	public function test_unresolvable_ids_yield_null_content_rather_than_an_empty_string(): void {
		// Callers test `if (!$content) continue;`, so null and '' behave alike there — but a caller
		// that starts distinguishing "absent" from "empty" must not silently get the wrong one.
		$this->assertSame( [ null, '' ], $this->resolve( [ 'patterns' => [] ], 'pattern_999' ) );
		$this->assertSame( [ null, '' ], $this->resolve( [ 'layouts' => [] ], 'layout_7' ) );
		$this->assertSame( [ null, '' ], $this->resolve( [], 'something_else' ) );
		$this->assertSame( [ null, '' ], $this->resolve( [], 'blockpattern_' ) );
	}

	public function test_an_unknown_registered_slug_does_not_fatal(): void {
		// Whether WP_Block_Patterns_Registry exists depends on the bootstrap; either way an unknown
		// slug must degrade to null, never throw. Verified live against the real registry too:
		// `blockpattern_timberland/slider-3-slide` returned 330 bytes, `.../nope` returned null.
		$this->assertSame( [ null, '' ], $this->resolve( [], 'blockpattern_timberland/definitely-not-registered' ) );
	}
}
