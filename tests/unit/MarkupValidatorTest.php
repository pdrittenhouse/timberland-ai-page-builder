<?php
/**
 * MarkupValidator — the gate between an LLM's output and the block editor.
 *
 * The highest-risk untested code in this stack, and the reason this suite exists at all: a
 * hand-rolled regex parser, processing untrusted model output, deciding whether bad markup
 * reaches the editor. Its own docblock records why it does not use WordPress's `parse_blocks()`
 * — that parser duplicates innerHTML per node and exhausts PHP's memory limit on large
 * generated layouts — so the regex approach is deliberate, not an oversight. It just had no
 * tests.
 *
 * Pure logic: no WordPress, no network, no LLM. Everything an LLM actually gets wrong in
 * practice — fenced output, prose around the markup, malformed JSON, missing ACF field-key
 * companions — is reachable from here with a string and a manifest array.
 */

declare(strict_types=1);

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\MarkupValidator;

final class MarkupValidatorTest extends TestCase {

	/** A manifest with one known block whose `title` field has a field key. */
	private function manifest(): array {
		return array(
			'blocks' => array(
				'acf/jumbotron' => array(
					'field_key_map' => array(
						'title'    => 'field_6123abc',
						'subtitle' => 'field_6123def',
					),
				),
			),
		);
	}

	private function validator(): MarkupValidator {
		return new MarkupValidator( $this->manifest() );
	}

	/** A well-formed ACF block comment with matching field-key companions. */
	private function goodBlock(): string {
		$data = array( 'title' => 'Hello', '_title' => 'field_6123abc' );
		return '<!-- wp:acf/jumbotron ' . json_encode( array( 'name' => 'acf/jumbotron', 'data' => $data ) ) . ' /-->';
	}

	// ---- empty and unparseable input -------------------------------------------------

	public function test_empty_markup_is_invalid_and_says_so(): void {
		$r = $this->validator()->validate( '' );

		$this->assertFalse( $r['valid'] );
		$this->assertSame( array( 'Empty markup' ), $r['errors'] );
		$this->assertSame( 0, $r['block_count'] );
	}

	public function test_whitespace_only_markup_counts_as_empty(): void {
		$this->assertFalse( $this->validator()->validate( "  \n\t " )['valid'] );
	}

	public function test_prose_with_no_blocks_is_invalid(): void {
		// A model that answers in prose instead of block markup. Must be rejected, not passed
		// through as a zero-block page.
		$r = $this->validator()->validate( 'Sure! Here is a homepage for you.' );

		$this->assertFalse( $r['valid'] );
		$this->assertSame( array( 'No valid blocks found in markup.' ), $r['errors'] );
	}

	// ---- markdown fences, which is how LLM output actually arrives --------------------

	public function test_html_fenced_output_is_unwrapped(): void {
		$r = $this->validator()->validate( "```html\n" . $this->goodBlock() . "\n```" );

		$this->assertTrue( $r['valid'], implode( ' | ', $r['errors'] ) );
		$this->assertSame( 1, $r['block_count'] );
	}

	public function test_a_bare_fence_with_no_language_is_unwrapped(): void {
		$r = $this->validator()->validate( "```\n" . $this->goodBlock() . "\n```" );
		$this->assertSame( 1, $r['block_count'] );
	}

	public function test_prose_before_and_after_the_markup_is_discarded(): void {
		// The common shape: a preamble, the blocks, then a closing remark. Everything before the
		// first `<!-- wp:` and after the last `-->` is dropped.
		$r = $this->validator()->validate(
			"Certainly! Here you go:\n\n" . $this->goodBlock() . "\n\nLet me know if you want changes."
		);

		$this->assertTrue( $r['valid'], implode( ' | ', $r['errors'] ) );
		$this->assertSame( 1, $r['block_count'] );
	}

	// ---- block counting ---------------------------------------------------------------

	public function test_counts_namespaced_and_core_blocks_together(): void {
		$markup = $this->goodBlock() . '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
		$this->assertSame( 2, $this->validator()->validate( $markup )['block_count'] );
	}

	public function test_counts_nested_blocks(): void {
		$markup = '<!-- wp:group {"x":1} --><!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!-- /wp:group -->';
		$this->assertSame( 2, $this->validator()->validate( $markup )['block_count'] );
	}

	public function test_a_closing_comment_is_not_counted_as_a_block(): void {
		// `<!-- /wp:paragraph -->` must not match the opener pattern, or every paired block
		// counts twice and the figure reported to the editor is wrong.
		$this->assertSame( 1, $this->validator()->validate( '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->' )['block_count'] );
	}

	// ---- the ACF field-key companion contract -----------------------------------------

	public function test_a_correct_field_key_companion_passes(): void {
		$this->assertTrue( $this->validator()->validate( $this->goodBlock() )['valid'] );
	}

	public function test_a_missing_companion_for_a_KNOWN_field_is_an_error(): void {
		// This is the failure that presents as "the LLM got it wrong" and is actually a key-shape
		// bug. ACF stores the companion `_title` alongside `title`; without it the value is
		// written but never read back.
		$markup = '<!-- wp:acf/jumbotron ' . json_encode( array( 'data' => array( 'title' => 'Hello' ) ) ) . ' /-->';
		$r      = $this->validator()->validate( $markup );

		$this->assertFalse( $r['valid'] );
		$this->assertCount( 1, $r['errors'] );
		$this->assertStringContainsString( '_title', $r['errors'][0] );
		$this->assertStringContainsString( 'field_6123abc', $r['errors'][0], 'the error must name the expected key, or it is not actionable' );
	}

	public function test_a_WRONG_companion_value_is_an_error(): void {
		$markup = '<!-- wp:acf/jumbotron ' . json_encode(
			array( 'data' => array( 'title' => 'Hello', '_title' => 'field_wrong' ) )
		) . ' /-->';
		$r = $this->validator()->validate( $markup );

		$this->assertFalse( $r['valid'] );
		$this->assertStringContainsString( 'field_wrong', $r['errors'][0] );
	}

	public function test_a_field_outside_the_key_map_is_a_warning_not_an_error(): void {
		// Unknown fields must not block publication — the manifest may simply be behind the
		// block. Degrade to a warning.
		$markup = '<!-- wp:acf/jumbotron ' . json_encode(
			array( 'data' => array( 'title' => 'H', '_title' => 'field_6123abc', 'novel' => 'x' ) )
		) . ' /-->';
		$r = $this->validator()->validate( $markup );

		$this->assertTrue( $r['valid'] );
		$this->assertNotEmpty( $r['warnings'] );
	}

	public function test_an_unknown_block_is_a_warning_not_an_error(): void {
		// It may still be registered at runtime; the manifest is not the whole truth.
		$markup = '<!-- wp:acf/not-in-manifest ' . json_encode( array( 'data' => array( 'a' => 'b' ) ) ) . ' /-->';
		$r      = $this->validator()->validate( $markup );

		$this->assertTrue( $r['valid'] );
		$this->assertStringContainsString( 'not found in manifest', $r['warnings'][0] );
	}

	public function test_underscore_keys_are_not_themselves_checked_for_companions(): void {
		// Otherwise `_title` would be reported as needing a `__title`, recursively — and since
		// every value key has a companion, the loop would never terminate usefully.
		//
		// Written first as a foreach over `errors`, which PHPUnit correctly flagged as RISKY:
		// with no errors the loop body never runs and the test asserted nothing. A vacuous pass
		// is worse than no test, so this asserts unconditionally on a block that HAS a bad
		// companion — the one case that definitely produces an error to inspect.
		$markup = '<!-- wp:acf/jumbotron ' . json_encode(
			array( 'data' => array( 'title' => 'H', '_title' => 'field_wrong' ) )
		) . ' /-->';
		$errors = $this->validator()->validate( $markup )['errors'];

		$this->assertNotEmpty( $errors, 'this fixture must produce an error for the assertion below to mean anything' );
		$this->assertStringNotContainsString( '__title', implode( ' ', $errors ) );
	}

	// ---- the gap this suite exists to document ----------------------------------------

	public function test_MALFORMED_JSON_IN_A_BLOCK_IS_SILENTLY_SKIPPED(): void {
		// Documented, not endorsed. `json_decode()` returning null hits `continue`, so a block
		// whose attributes are unparseable is never validated and the markup reports VALID.
		// The block still counts toward block_count, so the editor is handed something that
		// looks checked and was not.
		//
		// Arguably it should be an error. Left as-is because changing it is a behaviour change
		// to a product path, and this test is what makes the current behaviour visible.
		$markup = '<!-- wp:acf/jumbotron {"data":{"title":"unclosed} /-->';
		$r      = $this->validator()->validate( $markup );

		$this->assertTrue( $r['valid'], 'current behaviour: unparseable attributes pass' );
		$this->assertSame( array(), $r['errors'] );
	}

	public function test_validate_attributes_reuses_a_prior_block_count(): void {
		// The post-processing path: structure is unchanged, only JSON was repaired, so the count
		// is carried rather than recomputed.
		$r = $this->validator()->validate_attributes( $this->goodBlock(), 7 );

		$this->assertTrue( $r['valid'] );
		$this->assertSame( 7, $r['block_count'] );
	}

	public function test_validate_attributes_still_catches_a_bad_companion(): void {
		$markup = '<!-- wp:acf/jumbotron ' . json_encode(
			array( 'data' => array( 'title' => 'H', '_title' => 'field_nope' ) )
		) . ' /-->';
		$this->assertFalse( $this->validator()->validate_attributes( $markup, 1 )['valid'] );
	}

	public function test_the_return_shape_is_stable_across_every_branch(): void {
		// Callers destructure this. A branch returning three keys instead of four fails only on
		// the path nobody exercised.
		foreach ( array( '', 'prose only', $this->goodBlock(), '<!-- wp:paragraph -->' ) as $input ) {
			$r = $this->validator()->validate( $input );
			$this->assertSame(
				array( 'valid', 'errors', 'warnings', 'block_count' ),
				array_keys( $r ),
				'return shape changed for input: ' . substr( $input, 0, 30 )
			);
			$this->assertIsBool( $r['valid'] );
			$this->assertIsArray( $r['errors'] );
			$this->assertIsArray( $r['warnings'] );
			$this->assertIsInt( $r['block_count'] );
		}
	}
}
