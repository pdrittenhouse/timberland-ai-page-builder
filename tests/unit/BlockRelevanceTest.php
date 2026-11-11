<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\BlockRelevance;

/**
 * The three defects that starved the model of blocks.
 *
 * Each `test_..._used_to_...` below is a regression for something measured against the real
 * 48-block manifest, not a hypothetical.
 */
class BlockRelevanceTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function blocks(): array
    {
        return [
            // Layout primitives.
            'acf/section' => ['title' => 'Section', 'keywords' => []],
            'acf/row' => ['title' => 'Row', 'keywords' => []],
            'acf/column' => ['title' => 'Column', 'keywords' => []],
            // The two that matched all English: their keywords are HTML tag names. `parent` is
            // carried because it is what marks them as parts of a composite rather than blocks a
            // band could be built from -- the real manifest declares exactly this.
            'acf/table-cell' => ['title' => 'Table Cell', 'keywords' => ['th', 'td'], 'parent' => ['acf/table-row']],
            'acf/table-row' => ['title' => 'Table Row', 'keywords' => ['tr'], 'parent' => ['acf/table-head']],
            // Content blocks -- the ones that were never reaching the prompt.
            'acf/card' => ['title' => 'Card', 'keywords' => ['card']],
            'acf/card-grid' => ['title' => 'Card Grid', 'keywords' => ['cards', 'grid']],
            'acf/button' => ['title' => 'Button', 'keywords' => ['button']],
            'acf/jumbotron' => ['title' => 'Jumbotron', 'keywords' => ['jumbotron']],
            'acf/hero-unit' => ['title' => 'Hero Unit', 'keywords' => ['hero']],
            'acf/feature' => ['title' => 'Feature', 'keywords' => ['feature']],
            'acf/promo' => ['title' => 'Promo', 'keywords' => ['promo']],
            'acf/text-block' => ['title' => 'Text Block', 'keywords' => ['text']],
            'acf/testimonial' => ['title' => 'Testimonial', 'keywords' => ['testimonial', 'quote']],
        ];
    }

    public function test_a_two_letter_keyword_no_longer_matches_inside_a_word(): void
    {
        // Defect 1, and the root cause of the other two mattering. `th` is acf/table-cell's keyword
        // and it is inside "the"; `tr` is acf/table-row's and it is inside "Treat". Those two blocks
        // scored on essentially any English sentence.
        $selected = BlockRelevance::select($this->blocks(), 'Treat the copy as real.');

        $this->assertArrayNotHasKey('acf/table-cell', $selected, '`th` must not match "the"');
        $this->assertArrayNotHasKey('acf/table-row', $selected, '`tr` must not match "Treat"');
    }

    public function test_a_keyword_still_matches_its_own_plural(): void
    {
        // The fix must not trade one starvation bug for another: "cards" has to reach acf/card.
        $selected = BlockRelevance::select($this->blocks(), 'A row of product cards');

        $this->assertArrayHasKey('acf/card', $selected);
    }

    public function test_a_real_table_request_still_finds_the_table_blocks(): void
    {
        // Whole-word matching must keep the case the keywords are actually for.
        $selected = BlockRelevance::select($this->blocks(), 'A pricing table with a th header and tr rows');

        $this->assertArrayHasKey('acf/table-cell', $selected);
        $this->assertArrayHasKey('acf/table-row', $selected);
    }

    public function test_an_accidental_match_no_longer_suppresses_the_fallback(): void
    {
        // Defect 2. The old gate was `count($selected) <= count(CORE_BLOCKS)`, so a single stray
        // hit took the count to 4 and silently disabled every content block. The question is
        // whether a CONTENT block was found, not how many entries exist.
        $blocks = $this->blocks();
        $blocks['acf/table-cell']['keywords'] = ['frame'];   // force exactly one accidental hit

        $selected = BlockRelevance::select($blocks, 'Build this from the Figma frame.');

        $this->assertArrayHasKey('acf/table-cell', $selected, 'the stray hit is still a hit');
        $this->assertArrayHasKey('acf/card-grid', $selected, 'but it must not kill the fallback');
        $this->assertArrayHasKey('acf/hero-unit', $selected);
    }

    public function test_a_parent_restricted_block_does_not_count_as_finding_content(): void
    {
        // The discriminator itself. A block declaring `parent` is part of a composite -- selecting
        // one says nothing about whether the model has anywhere to put a band's content. Asking
        // "is it outside CORE_BLOCKS?" instead got this wrong, and this is the case that caught it.
        $blocks = $this->blocks();
        $blocks['acf/button-text'] = ['title' => 'Button Text', 'keywords' => ['label'], 'parent' => ['acf/button']];
        // text-block would ALSO match a prompt about text, and it is freely placeable, so finding
        // it really does mean content was found. Dropped here to isolate the restricted block --
        // the first version of this test did not, and failed for that reason rather than the one
        // it was written for.
        unset($blocks['acf/text-block']);

        $selected = BlockRelevance::select($blocks, 'Set the label on it.');

        $this->assertArrayHasKey('acf/button-text', $selected, 'it matched "label" legitimately');
        $this->assertArrayHasKey('acf/card-grid', $selected, 'but it cannot hold a band of content');
    }

    public function test_the_fallback_does_not_fire_when_real_content_blocks_matched(): void
    {
        // The safety net must stay a net. A prompt that genuinely names its blocks should not have
        // eight unrelated ones appended.
        $selected = BlockRelevance::select($this->blocks(), 'A page of testimonial quotes');

        $this->assertArrayHasKey('acf/testimonial', $selected);
        $this->assertArrayNotHasKey('acf/jumbotron', $selected, 'fallback fired when it should not');
    }

    public function test_the_confirmed_plan_is_scored_not_just_the_operators_prose(): void
    {
        // Defect 3, and the one that made the Figma path produce an unusable catalogue. A
        // Figma-derived prompt describes provenance, never content -- the content is in the plan.
        $prompt = 'Build the "Home" page from the linked Figma frame.';

        $without = BlockRelevance::select($this->blocks(), $prompt);
        $with = BlockRelevance::select($this->blocks(), $prompt, [
            'overall_intent' => '',
            'sections' => [
                ['intent' => 'Band 2', 'content' => ['title' => 'Customer Favorites, Naturally']],
                ['intent' => 'Band 3', 'content' => ['title' => 'Shop by testimonial quote']],
            ],
        ]);

        $this->assertArrayHasKey('acf/testimonial', $with, 'a plan naming quotes must reach the quote block');
        $this->assertArrayNotHasKey('acf/testimonial', $without, 'the prose alone cannot say that');
    }

    public function test_a_plan_whose_content_is_a_string_is_read_too(): void
    {
        // An existing characterisation test covers the decomposer emitting `content` as a string,
        // so the scorer must not assume an array and must not warn.
        $selected = BlockRelevance::select($this->blocks(), 'Build it', [
            'sections' => [['intent' => 'Hero', 'content' => 'A jumbotron with a headline']],
        ]);

        $this->assertArrayHasKey('acf/jumbotron', $selected);
    }

    public function test_the_layout_primitives_are_always_present(): void
    {
        $selected = BlockRelevance::select($this->blocks(), '');

        foreach (BlockRelevance::CORE_BLOCKS as $name) {
            $this->assertArrayHasKey($name, $selected);
        }
    }

    public function test_an_empty_prompt_still_yields_content_blocks(): void
    {
        // Core only is not a usable catalogue: the model would have no block to put content in.
        $selected = BlockRelevance::select($this->blocks(), '');

        $this->assertArrayHasKey('acf/card-grid', $selected);
    }

    public function test_a_block_absent_from_the_manifest_is_never_invented(): void
    {
        // The fallback names blocks by slug; a tier that does not ship acf/promo must not have it
        // appear in a catalogue, or the model is told about a block that cannot be rendered.
        $blocks = $this->blocks();
        unset($blocks['acf/promo'], $blocks['acf/card-grid']);

        $selected = BlockRelevance::select($blocks, '');

        $this->assertArrayNotHasKey('acf/promo', $selected);
        $this->assertArrayNotHasKey('acf/card-grid', $selected);
    }
}
