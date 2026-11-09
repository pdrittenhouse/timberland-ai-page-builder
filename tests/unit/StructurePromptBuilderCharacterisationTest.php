<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\StructurePromptBuilder;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * CHARACTERISATION tests — what `StructurePromptBuilder` does TODAY.
 *
 * This is the second-pass prompt: `PromptDecomposer` asks a model to break the request into
 * sections, and this builds the prompt that turns that plan into a block tree. 495 lines, no
 * tests, and the same rewire ahead of it as PromptBuilder.
 *
 * Unlike `PromptDecomposer`, this one is pure: it takes the decomposition as an array, so it is
 * testable with a synthetic plan and no LLM. That is also what makes the contract between the
 * two worth pinning — see the last test.
 */
final class StructurePromptBuilderCharacterisationTest extends TestCase {

    use WordPressStubs;

    protected function setUp(): void {
        $this->stubWordPress();
    }

    protected function tearDown(): void {
        $this->unstubWordPress();
    }

    /**
     * The shape `PromptDecomposer` asks the model for, verbatim from its own prompt:
     * `sections[] = { intent, pattern_hint, content: { ... } }` plus `overall_intent`.
     *
     * @return array<string, mixed>
     */
    private function decomposition(): array {
        return array(
            'overall_intent'        => 'A marketing landing page introducing a product.',
            'sections'              => array(
                array(
                    'intent'       => 'Hero introducing the product',
                    'pattern_hint' => 'hero',
                    'content'      => array( 'headline' => 'Meet the thing', 'cta' => 'Get started' ),
                ),
                array(
                    'intent'       => 'Three feature cards',
                    'pattern_hint' => 'card',
                    'content'      => array( 'card_1' => 'Fast', 'card_2' => 'Cheap', 'card_3' => 'Good' ),
                ),
            ),
            'suggested_pattern_ids' => array(),
        );
    }

    private function build( ?array $decomposition = null ): string {
        return ( new StructurePromptBuilder( ManifestFixture::manifest() ) )->build(
            'A landing page with a hero and three cards',
            $decomposition ?? $this->decomposition()
        );
    }

    public function test_the_prompt_carries_the_confirmed_plan_as_its_own_section(): void {
        $out = $this->build();

        $this->assertStringContainsString( '# CONFIRMED LAYOUT PLAN', $out );
        $this->assertStringContainsString( '## Section 1: Hero introducing the product', $out );
        $this->assertStringContainsString( '## Section 2: Three feature cards', $out );
    }

    public function test_each_sections_content_is_flattened_into_key_value_lines(): void {
        $out = $this->build();

        $this->assertStringContainsString( 'headline: Meet the thing', $out );
        $this->assertStringContainsString( 'card_3: Good', $out );
    }

    public function test_blocks_are_labelled_container_or_leaf(): void {
        // This builder annotates the heading itself, which PromptBuilder does not. The model's
        // job here is tree construction, so the distinction is load-bearing rather than context.
        $out = $this->build();

        $this->assertStringContainsString( '## Section (`acf/section`) — container', $out );
        $this->assertStringContainsString( '## Card (`acf/card`) — leaf', $out );
    }

    public function test_it_is_cheaper_than_the_single_pass_prompt(): void {
        // ~787 tokens against PromptBuilder's ~1,708 for the same request, measured
        // 2026-11-09. That gap is the whole argument for the two-pass design: the plan is
        // already decided, so the rules section can be shorter. A regression here erases the
        // reason for the extra round trip.
        $this->assertLessThanOrEqual(
            1000,
            $this->estimateTokens( $this->build() ),
            'The structure prompt has grown past the single-pass prompt it exists to undercut.'
        );
    }

    public function test_a_section_whose_content_is_a_string_is_handled_without_a_warning(): void {
        // THE CONTRACT GAP, and the reason this test is here rather than in a bug report.
        //
        // `PromptDecomposer` asks the model for `"content": { ... }` and
        // `parse_decomposition()` validates only that a `sections` key exists — not that each
        // section's `content` is an array. A model returning a plain string there is a common
        // deviation, and this builder does `foreach ($section['content'] ...)` unguarded, so
        // the result is a PHP warning plus that section's content silently missing from the
        // prompt. The model then builds a tree from an incomplete plan and nothing reports it.
        //
        // phpunit.xml sets failOnWarning="true", so this test failed when first written. The
        // fix was a guard rather than a redesign, so it was made rather than filed: a string
        // is now kept as the section's content instead of being iterated and lost. This is the
        // one test here asserting what the code SHOULD do, and it is pinning the contract the
        // JSON-schema validation step will enforce properly.
        $broken = $this->decomposition();
        $broken['sections'][0]['content'] = 'Big headline and a call to action';

        $out = $this->build( $broken );

        $this->assertStringContainsString(
            'Hero introducing the product',
            $out,
            'The section heading should survive even when its content is the wrong type.'
        );
    }
}
