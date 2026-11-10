<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\RepeaterRows;

/**
 * A repeater written as a JSON array, converted to the rows ACF stores.
 *
 * This reached a saved draft (post 7100) through a real generation, with the prompt already
 * warning against it and the schema already rejecting it. Both write paths --
 * `MarkupGenerator::post_process_markup()` and `MarkupAssembler::build_data_object()` -- passed
 * the array through untouched, so the bug was not a divergence between them: it was absent from
 * both. These tests pin the shared conversion.
 */
class RepeaterRowsTest extends TestCase
{
    /** @var array<string, string> */
    private array $types = [
        'col_width' => 'repeater',
        'col_offset' => 'repeater',
        'layouts' => 'flexible_content',
        'title' => 'text',
        'settings' => 'group',
    ];

    public function test_the_parent_becomes_a_row_count(): void
    {
        $out = RepeaterRows::flatten([
            'col_width' => [['breakpoint' => 'lg', 'width' => '10']],
        ], $this->types);

        $this->assertSame(1, $out['col_width'], 'ACF reads the parent as the number of rows');
        $this->assertSame('lg', $out['col_width_0_breakpoint']);
        $this->assertSame('10', $out['col_width_0_width']);
    }

    public function test_a_prefixed_sub_key_is_not_doubled(): void
    {
        // Models echo back the flattened name they were shown, so the prefix is often already
        // there. Left alone it becomes `col_width_0_col_width_breakpoint`.
        $out = RepeaterRows::flatten([
            'col_width' => [['col_width_breakpoint' => 'lg', 'col_width_width' => '10']],
        ], $this->types);

        $this->assertArrayHasKey('col_width_0_breakpoint', $out);
        $this->assertArrayNotHasKey('col_width_0_col_width_breakpoint', $out);
    }

    public function test_several_rows_are_indexed_in_order(): void
    {
        $out = RepeaterRows::flatten([
            'col_width' => [
                ['breakpoint' => 'sm', 'width' => '12'],
                ['breakpoint' => 'lg', 'width' => '4'],
            ],
        ], $this->types);

        $this->assertSame(2, $out['col_width']);
        $this->assertSame('sm', $out['col_width_0_breakpoint']);
        $this->assertSame('lg', $out['col_width_1_breakpoint']);
        $this->assertSame('4', $out['col_width_1_width']);
    }

    public function test_flexible_content_is_handled_too(): void
    {
        $out = RepeaterRows::flatten([
            'layouts' => [['acf_fc_layout' => 'text', 'body' => 'hi']],
        ], $this->types);

        $this->assertSame(1, $out['layouts']);
        $this->assertSame('text', $out['layouts_0_acf_fc_layout']);
    }

    public function test_a_field_that_is_not_a_repeater_is_untouched(): void
    {
        $value = ['a' => 1, 'b' => 2];
        $out = RepeaterRows::flatten(['settings' => $value, 'title' => 'x'], $this->types);

        $this->assertSame($value, $out['settings'], 'a group is not a repeater and must not be rewritten');
        $this->assertSame('x', $out['title']);
    }

    public function test_an_already_correct_row_count_is_left_alone(): void
    {
        // The common case: the model got it right, or a previous step already converted it.
        $in = ['col_width' => 1, 'col_width_0_width' => '10'];

        $this->assertSame($in, RepeaterRows::flatten($in, $this->types));
    }

    public function test_an_associative_array_is_not_guessed_at(): void
    {
        // Not a list of rows. Guessing would be worse than letting the validator report it.
        $in = ['col_width' => ['breakpoint' => 'lg']];

        $this->assertSame($in, RepeaterRows::flatten($in, $this->types));
    }

    public function test_a_list_of_scalars_is_not_guessed_at(): void
    {
        $in = ['col_width' => ['lg', 'md']];

        $this->assertSame($in, RepeaterRows::flatten($in, $this->types));
    }

    public function test_an_explicit_row_key_wins_over_the_array_form(): void
    {
        $out = RepeaterRows::flatten([
            'col_width' => [['width' => 'from-array']],
            'col_width_0_width' => 'explicit',
        ], $this->types);

        $this->assertSame('explicit', $out['col_width_0_width']);
    }

    public function test_companion_keys_are_not_mangled(): void
    {
        $out = RepeaterRows::flatten([
            'col_width' => [['width' => '10']],
            '_col_width' => 'field_abc',
        ], $this->types);

        $this->assertSame('field_abc', $out['_col_width'], 'companions belong to the callers, not here');
    }
}
