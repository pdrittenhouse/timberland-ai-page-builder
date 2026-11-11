<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\MarkupValidator;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * Where a block SITS, which nothing checked until now.
 *
 * Every other check in `MarkupValidator` validates a block's `data` and never its position, so two
 * failures observed in live generations passed clean: a block placed outside the parent it declares,
 * and a block that is not in the catalogue at all.
 */
class BlockPlacementTest extends TestCase
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

    private function validator(): MarkupValidator
    {
        return new MarkupValidator([
            'blocks' => [
                'acf/section' => ['name' => 'acf/section', 'is_container' => true, 'parent' => null,
                    'field_key_map' => [], 'field_type_map' => []],
                'acf/row' => ['name' => 'acf/row', 'is_container' => true, 'parent' => null,
                    'field_key_map' => [], 'field_type_map' => []],
                'acf/column' => ['name' => 'acf/column', 'is_container' => true, 'parent' => ['acf/row'],
                    'field_key_map' => [], 'field_type_map' => []],
                'acf/table-cell' => ['name' => 'acf/table-cell', 'is_container' => false,
                    'parent' => ['acf/table-row'], 'field_key_map' => [], 'field_type_map' => []],
                'acf/table-row' => ['name' => 'acf/table-row', 'is_container' => true,
                    'parent' => ['acf/table-head'], 'field_key_map' => [], 'field_type_map' => []],
            ],
        ]);
    }

    public function test_a_block_outside_its_declared_parent_is_an_error(): void
    {
        // 11 of 48 blocks carry a `parent`. WordPress enforces it in the editor; nothing enforced
        // it on generated markup.
        $markup = <<<'HTML'
<!-- wp:acf/section {"data":{}} -->
<!-- wp:acf/table-cell {"data":{}} /-->
<!-- /wp:acf/section -->
HTML;

        $result = $this->validator()->validate($markup);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('acf/table-cell', implode(' ', $result['errors']));
        $this->assertStringContainsString('acf/table-row', implode(' ', $result['errors']));
    }

    public function test_a_correctly_placed_block_is_not_flagged(): void
    {
        $markup = <<<'HTML'
<!-- wp:acf/row {"data":{}} -->
<!-- wp:acf/column {"data":{}} /-->
<!-- /wp:acf/row -->
HTML;

        $this->assertSame([], $this->validator()->validate($markup)['errors']);
    }

    public function test_a_restricted_block_at_the_top_level_is_an_error(): void
    {
        // "At the top level" has to be said explicitly, or the message reads as though the block
        // has no parent at all rather than the wrong one.
        $result = $this->validator()->validate('<!-- wp:acf/column {"data":{}} /-->');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('top level', implode(' ', $result['errors']));
    }

    public function test_an_unnamespaced_core_block_is_recognised(): void
    {
        // The regression that made the first version of this check useless. WordPress serialises
        // its own blocks WITHOUT the namespace -- `<!-- wp:heading -->` is `core/heading` -- so a
        // pattern requiring `ns/name` matched every ACF block and no core one, and reported zero
        // warnings on markup containing two headings.
        $markup = <<<'HTML'
<!-- wp:acf/section {"data":{}} -->
<!-- wp:heading {"level":2} -->
<h2>Hello</h2>
<!-- /wp:heading -->
<!-- /wp:acf/section -->
HTML;

        $warnings = $this->validator()->validate($markup)['warnings'];

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('core/heading', implode(' ', $warnings));
    }

    public function test_a_foreign_block_is_a_warning_not_an_error(): void
    {
        // Core blocks are legal WordPress and an operator may want one. What they must not be is
        // unremarked: they carry no ACF fields and no design-system styling, which is the silent
        // approximation `report_gap` exists to replace.
        $result = $this->validator()->validate(
            "<!-- wp:acf/section {\"data\":{}} -->\n<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->\n<!-- /wp:acf/section -->"
        );

        $this->assertTrue($result['valid'], 'a core block must not fail the generation');
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_a_repeated_foreign_block_is_reported_once(): void
    {
        // A page of paragraphs should not produce a page of identical warnings.
        $markup = '<!-- wp:acf/section {"data":{}} -->';

        for ($i = 0; $i < 5; ++$i) {
            $markup .= "\n<!-- wp:paragraph -->\n<p>{$i}</p>\n<!-- /wp:paragraph -->";
        }

        $markup .= "\n<!-- /wp:acf/section -->";

        $warnings = array_filter(
            $this->validator()->validate($markup)['warnings'],
            static fn(string $w): bool => str_contains($w, 'core/paragraph')
        );

        $this->assertCount(1, $warnings);
    }

    public function test_a_self_closing_block_does_not_corrupt_the_nesting(): void
    {
        // `<!-- wp:acf/x /-->` never closes, so pushing it would leave the stack wrong for
        // everything after it and misreport the next block's parent.
        $markup = <<<'HTML'
<!-- wp:acf/row {"data":{}} -->
<!-- wp:acf/column {"data":{}} /-->
<!-- wp:acf/column {"data":{}} /-->
<!-- /wp:acf/row -->
HTML;

        $this->assertSame([], $this->validator()->validate($markup)['errors']);
    }

    public function test_an_ancestor_rule_is_satisfied_at_any_depth(): void
    {
        // `ancestor` is "anywhere beneath", unlike `parent`'s "directly inside", so it is checked
        // against the whole stack. Checking only the immediate parent would report a block
        // legitimately nested two levels down as misplaced.
        $v = new MarkupValidator(['blocks' => [
            'acf/section' => ['name' => 'acf/section', 'is_container' => true, 'field_key_map' => [], 'field_type_map' => []],
            'acf/row' => ['name' => 'acf/row', 'is_container' => true, 'field_key_map' => [], 'field_type_map' => []],
            'acf/deep' => ['name' => 'acf/deep', 'is_container' => false, 'ancestor' => ['acf/section'],
                'field_key_map' => [], 'field_type_map' => []],
        ]]);

        $ok = "<!-- wp:acf/section {\"data\":{}} -->\n<!-- wp:acf/row {\"data\":{}} -->\n"
            . "<!-- wp:acf/deep {\"data\":{}} /-->\n<!-- /wp:acf/row -->\n<!-- /wp:acf/section -->";
        $this->assertSame([], $v->validate($ok)['errors'], 'two levels down still counts');

        $bad = "<!-- wp:acf/row {\"data\":{}} -->\n<!-- wp:acf/deep {\"data\":{}} /-->\n<!-- /wp:acf/row -->";
        $this->assertNotEmpty($v->validate($bad)['errors'], 'no section anywhere above it');
    }

    public function test_a_containers_allowed_blocks_list_is_honoured(): void
    {
        // The one rule owned by the PARENT rather than the child.
        $v = new MarkupValidator(['blocks' => [
            'acf/picky' => ['name' => 'acf/picky', 'is_container' => true,
                'allowed_blocks' => ['acf/welcome'], 'field_key_map' => [], 'field_type_map' => []],
            'acf/welcome' => ['name' => 'acf/welcome', 'is_container' => false, 'field_key_map' => [], 'field_type_map' => []],
            'acf/unwelcome' => ['name' => 'acf/unwelcome', 'is_container' => false, 'field_key_map' => [], 'field_type_map' => []],
        ]]);

        $ok = "<!-- wp:acf/picky {\"data\":{}} -->\n<!-- wp:acf/welcome {\"data\":{}} /-->\n<!-- /wp:acf/picky -->";
        $this->assertSame([], $v->validate($ok)['errors']);

        $bad = "<!-- wp:acf/picky {\"data\":{}} -->\n<!-- wp:acf/unwelcome {\"data\":{}} /-->\n<!-- /wp:acf/picky -->";
        $errors = $v->validate($bad)['errors'];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not accepted by', implode(' ', $errors));
    }

    public function test_a_block_with_no_parent_restriction_may_sit_anywhere(): void
    {
        $markup = <<<'HTML'
<!-- wp:acf/row {"data":{}} -->
<!-- wp:acf/column {"data":{}} -->
<!-- wp:acf/section {"data":{}} /-->
<!-- /wp:acf/column -->
<!-- /wp:acf/row -->
HTML;

        $this->assertSame([], $this->validator()->validate($markup)['errors']);
    }
}
