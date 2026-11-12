<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\BlockPlacement;
use TimberlandAIPageBuilder\RestController;
use TimberlandAIPageBuilder\MarkupValidator;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * UC5's server half: which blocks may legally sit at a position.
 *
 * The filtering is the point. Offering all 48 and letting validation reject the illegal ones would
 * be worse than offering nothing, because the operator would reasonably trust the list.
 *
 * These once invoked a private `RestController::may_sit_here()` through reflection -- a SECOND
 * implementation of the three primitives, which meant the agreement this file claimed to pin was
 * the one thing it could not see. Both callers now go through {@see BlockPlacement}, and
 * `test_the_dropdown_and_the_validator_agree_on_every_position` checks the two against each other
 * directly rather than asserting the same rules twice.
 */
class PlaceableBlocksTest extends TestCase
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
        // Through the endpoint's own code path, not a reconstruction of it.
        return array_column(
            RestController::placeable_blocks($this->manifest(), $parent, $ancestors),
            'name'
        );
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

    /**
     * The property this file always claimed and could not check: the dropdown offers exactly what
     * the validator accepts, at every position in the fixture.
     *
     * Asserting the same three rules twice -- once here, once in `BlockPlacementTest` -- proves
     * each copy self-consistent and says nothing about whether they agree. This drives both sides
     * from one fixture and compares their verdicts, so a divergence fails here even if someone
     * "fixes" one caller and leaves the other.
     */
    public function test_the_dropdown_and_the_validator_agree_on_every_position(): void
    {
        $blocks = $this->manifest()['blocks'];

        // `parent` is always the innermost of the stack, which is how the validator derives it.
        $stacks = [
            [],
            ['acf/section'],
            ['acf/section', 'acf/row'],
            ['acf/slider', 'acf/row'],
            ['acf/picky'],
            ['acf/slider'],
        ];

        $compared = 0;

        foreach ($stacks as $stack) {
            $parent = $stack === [] ? '' : (string) end($stack);

            foreach (array_keys($blocks) as $name) {
                $offered = in_array($name, $this->placeable($parent, $stack), true);
                $rejected = $this->validatorRejectsPlacement((string) $name, $stack);

                $this->assertSame(
                    $offered,
                    !$rejected,
                    sprintf(
                        'disagreement on `%s` at [%s]: dropdown %s, validator %s',
                        $name,
                        implode(' > ', $stack) ?: 'top level',
                        $offered ? 'offers' : 'withholds',
                        $rejected ? 'rejects' : 'accepts'
                    )
                );

                $compared++;
            }
        }

        // Guards against the comparison silently covering nothing -- an empty loop asserts nothing
        // and passes, which is the failure mode this whole test exists to prevent.
        $this->assertSame(42, $compared, 'every block at every position');
    }

    /**
     * Whether `MarkupValidator` reports a PLACEMENT error for this block at this position.
     *
     * Only placement errors count: the validator also warns about blocks outside the catalogue and
     * validates field data, neither of which the dropdown speaks to.
     *
     * @param list<string> $stack
     */
    private function validatorRejectsPlacement(string $name, array $stack): bool
    {
        $markup = '';

        foreach ($stack as $ancestor) {
            $markup .= '<!-- wp:' . $ancestor . ' {"data":{}} -->';
        }

        $markup .= '<!-- wp:' . $name . ' {"data":{}} /-->';

        foreach (array_reverse($stack) as $ancestor) {
            $markup .= '<!-- /wp:' . $ancestor . ' -->';
        }

        $result = (new MarkupValidator($this->manifest()))->validate($markup);

        foreach ($result['errors'] as $error) {
            $placement = str_contains($error, 'may only be placed inside')
                || str_contains($error, 'may only appear beneath')
                || str_contains($error, 'is not accepted by');

            // Only the candidate's own placement -- an ancestor in the stack is checked too.
            if ($placement && str_contains($error, '`' . $name . '`')) {
                return true;
            }
        }

        return false;
    }
}
