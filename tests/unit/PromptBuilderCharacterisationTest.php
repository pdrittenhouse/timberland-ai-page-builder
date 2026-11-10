<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\PromptBuilder;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * CHARACTERISATION tests — what `PromptBuilder` does TODAY, not what it should do.
 *
 * 799 lines of prompt construction with no tests, about to be rewired: the plugin currently
 * derives its own block list instead of reading WordPress's registry, and fills `usage_notes`
 * with `''` for every block instead of the curated `best_for`/`avoid_when` the framework's
 * build harvests. Both of those change what lands in this prompt.
 *
 * These assertions exist to make that change SAFE, not to bless the current output. They are
 * deliberately written as "the prompt contains X" rather than exact-string snapshots: a
 * snapshot of 6,829 characters fails on every wording tweak and teaches nobody anything, while
 * these pin the structural facts a rewrite must not silently lose.
 *
 * Where an assertion records something that is arguably WRONG, it says so in the message
 * rather than quietly enshrining it. Those are the ones expected to be updated by the rewire,
 * and a failure there is the signal that it worked — not a regression.
 */
final class PromptBuilderCharacterisationTest extends TestCase {

    use WordPressStubs;

    protected function setUp(): void {
        $this->stubWordPress();
    }

    protected function tearDown(): void {
        $this->unstubWordPress();
    }

    private function build( ?array $manifest = null, string $prompt = 'A landing page with a hero and three cards' ): string {
        return ( new PromptBuilder( $manifest ?? ManifestFixture::manifest() ) )->build( $prompt, 'page' );
    }

    public function test_the_prompt_is_assembled_from_four_top_level_sections(): void {
        $out = $this->build();

        foreach ( array( '# RULES', '# AVAILABLE BLOCKS', '# NESTING RULES', '# POST TYPE CONTEXT' ) as $heading ) {
            $this->assertStringContainsString(
                $heading,
                $out,
                "The prompt lost the $heading section. If that is deliberate, update this test; if not, a whole class of instruction just stopped reaching the model."
            );
        }
    }

    public function test_only_blocks_relevant_to_the_prompt_are_described(): void {
        $out = $this->build();

        // Section and Card are described in full; accordion-item is not, because nothing in the
        // prompt relates to it. This filtering is the mechanism PromptBuilder's docblock calls
        // "manages token budget", and the budget test below is what makes it measurable.
        $this->assertStringContainsString( '## Section (`acf/section`)', $out );
        $this->assertStringContainsString( '## Card (`acf/card`)', $out );
        $this->assertStringNotContainsString(
            '## Accordion Item (`acf/accordion-item`)',
            $out,
            'An irrelevant block is now being described in full. That is budget spent on a block the request did not ask for.'
        );
    }

    public function test_a_filtered_out_block_still_appears_in_the_nesting_rules(): void {
        // It is named as an allowed child of acf/accordion. Dropping it from the nesting graph
        // because it was filtered out of the descriptions would tell the model a container has
        // no legal children, which is worse than spending the handful of tokens.
        $this->assertStringContainsString( 'acf/accordion-item', $this->build() );
    }

    public function test_field_names_reach_the_prompt_and_acf_keys_deliberately_do_not(): void {
        // This test previously asserted the OPPOSITE, on the premise that ACF cannot resolve a
        // field from its name. That premise was wrong, and this is the test that proved it:
        // `MarkupGenerator::post_process_markup()` step 3 and
        // `MarkupAssembler::build_data_object()` step 4 both UNCONDITIONALLY overwrite every
        // `_fieldname` companion from the manifest's key map. The generator's own comment says
        // why -- "Claude may output wrong field keys that look valid" -- so every key the prompt
        // taught the model was discarded and recomputed server-side.
        //
        // Measured on the real 48-block manifest: those `name => key` pairs were 97% of the
        // entire per-block prompt cost. Keys are now omitted and names kept.
        $out = $this->build();

        $this->assertStringContainsString( 'card_title', $out );
        $this->assertStringNotContainsString(
            'field_aaa111',
            $out,
            'An ACF field key is back in the prompt. The server overwrites it regardless, so this '
            . 'is budget spent on a value that is thrown away.'
        );
    }

    public function test_usage_notes_are_surfaced_when_present(): void {
        // The slot UC1/UC2's curated prose fills. The fixture populates it on one block; the
        // real ManifestBuilder hardcodes `usage_notes => ''` for EVERY block, so in production
        // this section of the prompt is always empty. This assertion is what proves the
        // plumbing works, so the rewire only has to supply the content.
        $this->assertStringContainsString( 'Best for: a short teaser linking to a longer page.', $this->build() );
    }

    public function test_the_operators_custom_system_prompt_is_appended(): void {
        $this->unstubWordPress();
        $this->stubWordPress( array( 'custom_system_prompt' => 'ZZ-CUSTOM-PROMPT-MARKER' ) );

        $this->assertStringContainsString( 'ZZ-CUSTOM-PROMPT-MARKER', $this->build() );
    }

    public function test_the_prompt_is_deterministic_for_the_same_input(): void {
        // An LLM product whose prompt varies between identical requests cannot be evaluated,
        // cached or regression-tested. Nothing here should introduce a timestamp or a random
        // ordering, and `generated_at` sits in the manifest where it is easy to leak into it.
        $this->assertSame( $this->build(), $this->build() );
        $this->assertStringNotContainsString( '2026-01-01T00:00:00+00:00', $this->build() );
    }
}
