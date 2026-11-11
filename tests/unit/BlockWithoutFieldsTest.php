<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\BlockCatalogue;
use TimberlandAIPageBuilder\PromptBuilder;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * A block the manifest knows but has no fields for.
 *
 * This is reachable without anyone doing anything wrong. Block membership comes from
 * `WP_Block_Type_Registry`; field names come from ACF field groups titled `Block: ` or `Module: `.
 * A block that registers before its group exists, or whose group is titled anything else, is a real
 * block with no known fields.
 *
 * It is currently **0 of 48** on this install, which is exactly why it needs a test: nothing would
 * notice it starting to happen.
 */
class BlockWithoutFieldsTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        $this->stubWordPress();
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    /** @return array<string, mixed> */
    private function manifestWithAFieldlessBlock(): array
    {
        $manifest = ManifestFixture::manifest();
        $manifest['blocks']['acf/brand-new'] = [
            'name' => 'acf/brand-new',
            'title' => 'Brand New',
            'description' => 'Registered before its field group existed.',
            'keywords' => [],
            'is_container' => false,
            'parent' => null,
            'field_key_map' => [],
            'field_type_map' => [],
            'field_variants' => [],
            'field_count' => 0,
            'usage_notes' => '',
        ];

        return $manifest;
    }

    public function test_it_is_still_offered_in_the_catalogue(): void
    {
        // Membership is the registry's call, not ours. Hiding a real block would be its own bug --
        // the operator can see it in the inserter and would reasonably expect it to be usable.
        $lines = BlockCatalogue::lines($this->manifestWithAFieldlessBlock()['blocks']);

        $this->assertStringContainsString('`acf/brand-new`', $lines);
    }

    public function test_the_model_is_told_the_fields_are_unknown(): void
    {
        // The defect this fixes: the description came back with a title, a type, and then nothing
        // at all -- indistinguishable from "this block takes no content". A model asked to fill a
        // section then invents plausible names, which is exactly how `col_width` and `cards`
        // reached a block tree and cost 40 validation errors.
        $described = (new PromptBuilder($this->manifestWithAFieldlessBlock()))
            ->describe_block('acf/brand-new');

        $this->assertNotSame('', $described);
        $this->assertStringContainsString('NO field definitions available', $described);
        $this->assertStringContainsString('do not guess', $described);
        $this->assertStringContainsString('report_gap', $described);
    }

    public function test_a_block_with_fields_says_none_of_that(): void
    {
        // The warning must not leak into the normal case, where it would be noise charged against
        // the budget on every described block.
        $manifest = ManifestFixture::manifest();
        $with_fields = null;

        foreach ($manifest['blocks'] as $slug => $block) {
            if (!empty($block['field_key_map'])) {
                $with_fields = $slug;
            }
        }

        $this->assertNotNull($with_fields, 'the fixture must have one block with fields');

        $described = (new PromptBuilder($manifest))->describe_block($with_fields);

        $this->assertStringNotContainsString('NO field definitions', $described);
    }

    public function test_an_absent_block_is_still_empty_not_described(): void
    {
        // The pre-existing contract, which this change must not blur: a block the manifest does not
        // have returns '' so the caller treats it as "do not offer", never as "a block with no
        // fields". Those are now two distinct answers, which is the point.
        $described = (new PromptBuilder(ManifestFixture::manifest()))->describe_block('acf/not-a-block');

        $this->assertSame('', $described);
    }
}
