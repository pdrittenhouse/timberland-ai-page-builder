<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\RestController;

/**
 * UC5's server half: which blocks may legally sit at a position.
 *
 * The filtering is the point. Offering all 48 and letting validation reject the illegal ones would
 * be worse than offering nothing, because the operator would reasonably trust the list. These use
 * the same three positive primitives `MarkupValidator` enforces, so the dropdown and the validator
 * cannot disagree -- and that agreement is what these tests are really pinning.
 */
class PlaceableBlocksTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return ['blocks' => [
            'acf/section' => ['title' => 'Section', 'is_container' => true],
            'acf/row' => ['title' => 'Row', 'is_container' => true],
            'acf/column' => ['title' => 'Column', 'is_container' => true, 'parent' => ['acf/row']],
            'acf/card' => ['title' => 'Card', 'is_container' => true],
            'acf/slide' => ['title' => 'Slide', 'is_container' => false, 'ancestor' => ['acf/slider']],
            'acf/slider' => ['title' => 'Slider', 'is_container' => true],
            'acf/picky' => ['title' => 'Picky', 'is_container' => true, 'allowed_blocks' => ['acf/card']],
        ]];
    }

    /**
     * @param array<int, string> $ancestors
     * @return array<int, string>
     */
    private function placeable(string $parent, array $ancestors = []): array
    {
        $method = new \ReflectionMethod(RestController::class, 'may_sit_here');
        $method->setAccessible(true);

        $manifest = $this->manifest();
        $out = [];

        foreach ($manifest['blocks'] as $name => $block) {
            if ($method->invoke(null, $name, $block, $parent, $ancestors, $manifest)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    public function test_a_parent_restricted_block_is_offered_only_under_its_parent(): void
    {
        $this->assertNotContains('acf/column', $this->placeable(''), 'not at the top level');
        $this->assertNotContains('acf/column', $this->placeable('acf/section'), 'not in a section');
        $this->assertContains('acf/column', $this->placeable('acf/row'), 'yes in a row');
    }

    public function test_an_ancestor_restricted_block_needs_that_ancestor_anywhere_above(): void
    {
        // `ancestor` is satisfied at any depth, so the whole stack is consulted -- a Slide two
        // levels inside a Slider is legal and must be offered.
        $this->assertNotContains('acf/slide', $this->placeable('acf/row', ['acf/section', 'acf/row']));
        $this->assertContains(
            'acf/slide',
            $this->placeable('acf/row', ['acf/slider', 'acf/row']),
            'a slider further up still counts'
        );
    }

    public function test_a_containers_whitelist_narrows_what_it_is_offered(): void
    {
        $inside = $this->placeable('acf/picky');

        $this->assertContains('acf/card', $inside);
        $this->assertNotContains('acf/section', $inside, 'picky allows only cards');
        $this->assertNotContains('acf/row', $inside);
    }

    public function test_an_unrestricted_block_is_offered_everywhere(): void
    {
        foreach (['', 'acf/section', 'acf/row', 'acf/card'] as $parent) {
            $this->assertContains('acf/card', $this->placeable($parent), "card should be offered in '{$parent}'");
        }
    }

    public function test_the_list_is_narrower_inside_a_whitelisting_container(): void
    {
        // A sanity check on the whole thing rather than one rule: the filter must actually filter.
        $this->assertLessThan(
            count($this->placeable('acf/row')),
            count($this->placeable('acf/picky')),
            'a whitelist must narrow the list'
        );
    }
}
