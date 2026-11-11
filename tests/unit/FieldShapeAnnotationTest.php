<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\PromptBuilder;
use TimberlandAIPageBuilder\SchemaOracle;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * A field whose stored value is not a string has to SAY so.
 *
 * The measured failure: `acf/button.link` was listed as the bare name `link`, a model sent
 * `"link": "/shop"`, and validation rejected it three times over -- "String value found, but an
 * object is required" plus both halves of the `anyOf`. The field was visible and its shape was not,
 * so a plausible string was the only thing to send.
 *
 * The annotation is read from the SAME schema the validator enforces, which is the point: a
 * hand-kept table of ACF types is how a prompt and its validator drift, and five of the non-scalar
 * ACF types do not even occur in this install's schemas.
 */
class FieldShapeAnnotationTest extends TestCase
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

    /** @param array<string, mixed> $properties */
    private function builderWithSchema(array $properties, ?string $schemaFor = 'acf/leaf'): PromptBuilder
    {
        $block = 'acf/leaf';
        // `$schemaFor` is which block the ORACLE answers about, which is not always the block being
        // described -- that is how "this install has no schema for it" is exercised. The first
        // version of this helper conflated the two and described a block the manifest did not have,
        // so it asserted an empty string rather than the fallback.
        $oracle = new class (['properties' => $properties], (string) $schemaFor) extends SchemaOracle {
            /** @param array<string, mixed> $schema */
            public function __construct(private array $schema, private string $block)
            {
            }

            public function schema_for(string $block_name): ?array
            {
                return $block_name === $this->block ? $this->schema : null;
            }
        };

        $manifest = ManifestFixture::manifest();
        $manifest['blocks'][$block] = [
            'name' => $block,
            'title' => 'Leaf',
            'description' => '',
            'keywords' => [],
            'is_container' => false,
            'parent' => null,
            'field_key_map' => ['link' => 'field_1', 'kin' => 'field_2', 'heading' => 'field_3'],
            'field_type_map' => ['link' => 'link', 'kin' => 'relationship', 'heading' => 'text'],
            'field_count' => 3,
            'usage_notes' => '',
        ];

        return new PromptBuilder($manifest, $oracle);
    }

    /** @return array<string, mixed> */
    private static function unsetSentinel(): array
    {
        // ACF writes one of these for an empty field; it is always the second anyOf branch, and the
        // shape to describe is the one that is NOT this.
        return ['enum' => [false, '', 'null', null]];
    }

    public function test_an_object_field_says_it_is_an_object(): void
    {
        $described = $this->builderWithSchema([
            'link' => ['anyOf' => [['type' => 'object'], self::unsetSentinel()]],
        ])->describe_block('acf/leaf');

        $this->assertStringContainsString('OBJECT', $described);
        $this->assertStringContainsString('never a bare string', $described);
    }

    public function test_a_link_field_names_acfs_own_keys(): void
    {
        // `type: object` alone stops a string but does not produce a working link. The keys are
        // ACF's, from class-acf-field-link.php.
        $described = $this->builderWithSchema([
            'link' => ['anyOf' => [['type' => 'object'], self::unsetSentinel()]],
        ])->describe_block('acf/leaf');

        foreach (['title', 'url', 'target'] as $key) {
            $this->assertStringContainsString($key, $described);
        }
    }

    public function test_an_array_field_says_it_is_an_array(): void
    {
        $described = $this->builderWithSchema([
            'kin' => ['anyOf' => [['type' => 'array'], self::unsetSentinel()]],
        ])->describe_block('acf/leaf');

        $this->assertStringContainsString('ARRAY', $described);
    }

    public function test_a_plain_string_field_is_not_annotated(): void
    {
        // The common case must stay a bare name. Annotating every field would spend the budget
        // restating that text fields take text.
        $described = $this->builderWithSchema([
            'heading' => ['anyOf' => [['type' => 'string'], self::unsetSentinel()]],
        ])->describe_block('acf/leaf');

        $this->assertStringContainsString('heading', $described);
        $this->assertStringNotContainsString('heading  [', $described);
    }

    public function test_the_unset_sentinel_branch_is_never_described(): void
    {
        // Reading the enum branch instead of the real one would annotate every field as "one of
        // false, '', 'null', null", which is true and useless.
        $described = $this->builderWithSchema([
            'link' => ['anyOf' => [self::unsetSentinel(), ['type' => 'object']]],
        ])->describe_block('acf/leaf');

        $this->assertStringContainsString('OBJECT', $described);
        $this->assertStringNotContainsString('"null"', $described);
    }

    public function test_a_block_with_no_schema_still_describes_its_fields(): void
    {
        // An install whose schemas have not been generated must degrade to bare names, not to an
        // empty description -- the latter is the "block with no fields" state, which is worse.
        $described = $this->builderWithSchema(
            ['link' => ['anyOf' => [['type' => 'object'], self::unsetSentinel()]]],
            'acf/some-other-block'
        )->describe_block('acf/leaf');

        $this->assertStringContainsString('link', $described);
        $this->assertStringNotContainsString('OBJECT', $described);
    }
}
