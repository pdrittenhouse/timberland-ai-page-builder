<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\PromptBuilder;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * UC6 -- how to COMPOSE a block, as opposed to whether to choose it.
 *
 * `best_for` and `avoid_when` answer which block; the schema answers which fields exist and what
 * shape their values take. Nothing answered which field a thing belongs in, and generated pages
 * showed it: background images placed inline, the image block used for icons, no colours at all.
 *
 * Curation carries this because nothing else can. Measured: every `select` adds +1,860 tokens to
 * `acf/card`; restricting to the 434 fields that draw on the design system is still +7,505 across
 * one page's blocks; and there is no structural subset, because `x-tl-via-clone` is false for all
 * 35 of card's palette fields and name depth does not separate them either.
 */
class BlockConfigurationTest extends TestCase
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
    private function manifestWith(string $configuration): array
    {
        $manifest = ManifestFixture::manifest();
        $slug = array_key_first($manifest['blocks']);
        $manifest['blocks'][$slug]['configuration'] = $configuration;
        $manifest['blocks'][$slug]['field_key_map'] = ['heading' => 'field_1'];
        $manifest['blocks'][$slug]['field_type_map'] = ['heading' => 'text'];

        return $manifest;
    }

    private function describeFirst(array $manifest): string
    {
        return (new PromptBuilder($manifest))->describe_block(array_key_first($manifest['blocks']));
    }

    public function test_guidance_reaches_the_block_description(): void
    {
        $described = $this->describeFirst(
            $this->manifestWith('A background image goes in `bg_image`, never an inline Image block.')
        );

        $this->assertStringContainsString('HOW TO CONFIGURE', $described);
        $this->assertStringContainsString('`bg_image`', $described);
    }

    public function test_it_rides_with_the_field_list_not_the_catalogue_line(): void
    {
        // Rendered where it is useful and affordable: advice about fields is only actionable once
        // the model has committed to the block, and this way it is paid for the ~8 blocks a page
        // fetches rather than all 48. Measured cost: +91 to +100 tokens per documented block.
        $manifest = $this->manifestWith('Put the picture in `image`.');
        $slug = array_key_first($manifest['blocks']);

        $catalogue = \TimberlandAIPageBuilder\BlockCatalogue::lines($manifest['blocks']);

        $this->assertStringNotContainsString('HOW TO CONFIGURE', $catalogue);
        $this->assertStringContainsString('HOW TO CONFIGURE', $this->describeFirst($manifest));
        $this->assertStringContainsString('`' . $slug . '`', $catalogue, 'the block is still catalogued');
    }

    public function test_an_undocumented_block_says_nothing_rather_than_something_empty(): void
    {
        // An empty line would read as "this block needs no configuration advice", which is not the
        // same as "nobody has written any" -- and the second is a thing to go and fix.
        $described = $this->describeFirst($this->manifestWith(''));

        $this->assertStringNotContainsString('HOW TO CONFIGURE', $described);
    }

    public function test_a_block_with_no_configuration_key_at_all_is_fine(): void
    {
        // The manifest gained this key; a cached manifest written before it did has no such key,
        // and that must degrade rather than warn.
        $manifest = ManifestFixture::manifest();
        $slug = array_key_first($manifest['blocks']);
        unset($manifest['blocks'][$slug]['configuration']);

        $described = (new PromptBuilder($manifest))->describe_block($slug);

        $this->assertNotSame('', $described);
        $this->assertStringNotContainsString('HOW TO CONFIGURE', $described);
    }

    public function test_guidance_is_not_confused_with_usage_notes(): void
    {
        // `usage_notes` is best_for + avoid_when concatenated -- a rendering of the existing pair,
        // not a third channel. Both appear, and they say different things.
        $manifest = $this->manifestWith('The picture goes in `image`.');
        $slug = array_key_first($manifest['blocks']);
        $manifest['blocks'][$slug]['usage_notes'] = 'Best for: a thing. Avoid when: another thing.';

        $described = (new PromptBuilder($manifest))->describe_block($slug);

        $this->assertStringContainsString('Usage notes:', $described);
        $this->assertStringContainsString('HOW TO CONFIGURE', $described);
    }
}
