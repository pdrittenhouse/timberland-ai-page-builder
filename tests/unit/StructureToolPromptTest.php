<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;
use TimberlandAIPageBuilder\StructurePromptBuilder;

/**
 * The tool-mode structure prompt: every block named, no field lists.
 *
 * Measured against the real 48-block manifest, the keyword-selecting `build()` gave a
 * Figma-derived prompt **6 blocks, none able to hold content**, and the model invented field names
 * on the structural blocks it had. With this prompt the same request validated clean -- 0 errors
 * against 40 -- because the model could see every block and ask for the real fields of the eight it
 * chose. These tests pin the two properties that make that work.
 */
class StructureToolPromptTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        $this->stubWordPress();

        // Scoped HERE, not to the shared trait, and that distinction cost three unrelated test
        // failures to learn. `function_exists()` reports TRUE for any function Brain Monkey has
        // been asked to mock, so stubbing these globally flipped PromptBuilder's lazy
        // `function_exists('get_template_directory')` guard from false to true for every test in the
        // suite, which changed whether a schema oracle was built at all and broke three retry tests
        // that depend on validation outcomes.
        //
        // The paths do not exist on purpose: a unit test must not read the live site's build
        // artifacts, so the vocabulary degrades to empty rather than letting this install's colours
        // leak into assertions.
        Functions\when('get_stylesheet_directory')->justReturn('/nonexistent/child-theme');
        Functions\when('get_template_directory')->justReturn('/nonexistent/parent-theme');
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    /** @param array<string, mixed> $decomposition */
    private function prompt(array $decomposition = [], array $curated = []): string
    {
        return (new StructurePromptBuilder(ManifestFixture::manifest()))
            ->build_for_tools('Build the home page', $decomposition ?: ['sections' => []], $curated);
    }

    public function test_every_block_in_the_manifest_is_named(): void
    {
        // The whole point. A block the model cannot see is a block it cannot choose, and the
        // prompt's claim that these are the only blocks that exist has to be true.
        $prompt = $this->prompt();
        $blocks = ManifestFixture::manifest()['blocks'];

        $this->assertNotEmpty($blocks);

        foreach (array_keys($blocks) as $slug) {
            $this->assertStringContainsString("`{$slug}`", $prompt, "{$slug} is missing from the catalogue");
        }
    }

    public function test_no_field_names_are_inlined(): void
    {
        // Inlining them is what made selection look necessary: 14,972 fields across 48 blocks,
        // ~149,500 tokens. The names alone are ~1% of that, which is why this prompt can afford to
        // be complete.
        $prompt = $this->prompt();

        $this->assertStringNotContainsString('Fields:', $prompt);
    }

    public function test_it_tells_the_model_to_fetch_fields_before_writing(): void
    {
        // A catalogue with no field lists and no instruction to ask is strictly worse than the
        // thing it replaced -- the model would simply guess, which is the defect being fixed.
        $prompt = $this->prompt();

        $this->assertStringContainsString('get_block_fields', $prompt);
        $this->assertStringContainsString('ONE call', $prompt);
        $this->assertStringContainsString('report_gap', $prompt);
    }

    public function test_a_parent_restriction_travels_with_the_name(): void
    {
        // Placement decides whether a block is a candidate at all, so it belongs beside the name
        // rather than inside field detail the model may never request.
        $manifest = ManifestFixture::manifest();
        $restricted = null;

        foreach ($manifest['blocks'] as $slug => $block) {
            if (!empty($block['parent'])) {
                $restricted = $slug;
            }
        }

        if (null === $restricted) {
            $this->markTestSkipped('the manifest fixture has no parent-restricted block');
        }

        $this->assertMatchesRegularExpression(
            '/`' . preg_quote($restricted, '/') . '`[^\n]*only inside/',
            $this->prompt()
        );
    }

    public function test_curated_guidance_is_preferred_and_the_description_is_the_fallback(): void
    {
        // The curated index covers 41 of 48 blocks on the real install, and the seven it omits are
        // content blocks (card, hero-unit, jumbotron, button, image). Falling back to the manifest
        // description is what stops that gap starving the catalogue a second time.
        $blocks = array_keys(ManifestFixture::manifest()['blocks']);
        $first = str_replace('acf/', '', $blocks[0]);

        $prompt = $this->prompt([], [
            $first => ['best_for' => 'CURATED-SENTINEL', 'avoid_when' => 'AVOID-SENTINEL'],
        ]);

        $this->assertStringContainsString('CURATED-SENTINEL', $prompt);
        $this->assertStringContainsString('AVOID-SENTINEL', $prompt);
    }

    public function test_an_uncurated_block_is_still_listed(): void
    {
        // Omitting it would be the starvation bug one layer down.
        $prompt = $this->prompt([], []);
        $blocks = array_keys(ManifestFixture::manifest()['blocks']);

        foreach ($blocks as $slug) {
            $this->assertStringContainsString("`{$slug}`", $prompt);
        }
    }

    public function test_the_confirmed_plan_is_still_carried(): void
    {
        // Tool mode must not drop the thing the operator confirmed.
        $prompt = $this->prompt([
            'sections' => [['intent' => 'PLAN-SENTINEL', 'content' => ['title' => 'TITLE-SENTINEL']]],
        ]);

        $this->assertStringContainsString('PLAN-SENTINEL', $prompt);
        $this->assertStringContainsString('TITLE-SENTINEL', $prompt);
    }

    public function test_its_size_does_not_grow_with_the_number_of_fields(): void
    {
        // The real invariant, and the reason a complete catalogue is affordable. A flat
        // size comparison on this fixture proves nothing -- the fixture's blocks have a handful of
        // fields each, so the inline prompt is the smaller of the two there, and asserting
        // otherwise was my mistake about what a fixture can show. On the real manifest the blocks
        // average 312 fields, which is where 149,500 tokens against 4,100 comes from.
        //
        // So: add fields, and watch which prompt notices.
        $manifest = ManifestFixture::manifest();
        $slug = array_key_first($manifest['blocks']);

        $builder_before = new StructurePromptBuilder($manifest);

        for ($i = 0; $i < 200; ++$i) {
            $manifest['blocks'][$slug]['field_key_map']["invented_field_{$i}"] = "field_{$i}";
            $manifest['blocks'][$slug]['field_type_map']["invented_field_{$i}"] = 'text';
        }

        $builder_after = new StructurePromptBuilder($manifest);

        $decomposition = ['sections' => [['intent' => 'Hero', 'content' => ['title' => 'Hello']]]];

        $inline_growth = strlen($builder_after->build('Build the home page', $decomposition))
            - strlen($builder_before->build('Build the home page', $decomposition));
        $tools_growth = strlen($builder_after->build_for_tools('Build the home page', $decomposition))
            - strlen($builder_before->build_for_tools('Build the home page', $decomposition));

        $this->assertGreaterThan(1000, $inline_growth, '200 fields must cost the inline prompt dearly');
        $this->assertSame(0, $tools_growth, 'the tool catalogue must not grow by one byte');
    }
}
