<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\PromptBudget;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;
use Brain\Monkey\Filters;

/**
 * The budget as a PROPERTY, not a figure.
 *
 * `PromptTokenBudgetTest` records what today's prompt costs and ratchets it; that number drifts by
 * design and has been raised once already. This suite asserts something a measurement cannot: that
 * no sequence of requests can push admitted context over the ceiling, however many are made.
 *
 * It also pins the part that is easy to get backwards -- enforcement must DEGRADE, never refuse.
 * A ceiling that could stop a legitimate page from generating would be worse than the drift it
 * prevents, which is why the override exists and why unlimited is a supported setting.
 */
class PromptBudgetTest extends TestCase
{
    use WordPressStubs;

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    /** A block's worth of field text, roughly the size of the largest real one (promo, ~950). */
    private function blockText(int $tokens = 950): string
    {
        return str_repeat('x', $tokens * PromptBudget::CHARS_PER_TOKEN);
    }

    public function test_no_sequence_of_requests_can_exceed_the_ceiling(): void
    {
        $this->stubWordPress();
        $budget = new PromptBudget([], 8000);

        // Far more than any real generation: every block in the library, ten times over.
        for ($i = 0; $i < 410; $i++) {
            $budget->admit("block {$i}", $this->blockText());
        }

        $report = $budget->report();

        $this->assertLessThanOrEqual(8000, $report['spent'], 'admitted context must never cross the ceiling');
        $this->assertFalse($report['over']);
        $this->assertNotEmpty($report['withheld'], 'the rest must be reported as withheld, not dropped silently');
    }

    public function test_withheld_items_are_named_so_the_caller_can_remove_the_block(): void
    {
        $this->stubWordPress();
        $budget = new PromptBudget([], 1000);

        $this->assertTrue($budget->admit('acf/hero-unit', $this->blockText(600)));
        $this->assertFalse($budget->admit('acf/promo', $this->blockText(600)));

        $report = $budget->report();

        $this->assertSame('acf/promo', $report['withheld'][0]['what']);
        $this->assertSame(600, $report['withheld'][0]['tokens']);
    }

    public function test_non_negotiable_context_is_charged_even_when_it_goes_over(): void
    {
        // RULES and the user's own prompt are not optional. Recording them as spent is what makes
        // an overage visible instead of quietly unaccounted for.
        $this->stubWordPress();
        $budget = new PromptBudget([], 100);
        $budget->charge('RULES', $this->blockText(500));

        $report = $budget->report();

        $this->assertSame(500, $report['spent']);
        $this->assertTrue($report['over'], 'an overage on non-negotiable context must be reported as one');
        $this->assertSame(0, $report['remaining']);
    }

    public function test_a_ceiling_of_zero_means_unlimited_and_says_so(): void
    {
        $this->stubWordPress();
        $budget = new PromptBudget([], 0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($budget->admit("block {$i}", $this->blockText()), 'nothing is withheld when unenforced');
        }

        $report = $budget->report();

        $this->assertFalse($report['enforced']);
        $this->assertFalse($report['over'], 'an unenforced budget is never "over"');
        $this->assertSame(-1, $report['remaining'], 'remaining is meaningless unenforced, and must not read as 0');
        $this->assertEmpty($report['withheld']);
    }

    public function test_per_request_escape_disables_enforcement(): void
    {
        $this->stubWordPress();
        $this->assertSame(0, PromptBudget::resolve_ceiling(['ignore_budget' => true]));
        $this->assertSame(PromptBudget::DEFAULT_CEILING, PromptBudget::resolve_ceiling([]));
    }

    public function test_option_overrides_the_default(): void
    {
        $this->stubWordPress(['token_budget' => 12000]);
        $this->assertSame(12000, PromptBudget::resolve_ceiling([]));
    }

    public function test_option_of_zero_means_use_the_default(): void
    {
        $this->stubWordPress(['token_budget' => 0]);
        $this->assertSame(PromptBudget::DEFAULT_CEILING, PromptBudget::resolve_ceiling([]));
    }

    public function test_filter_wins_and_receives_the_context(): void
    {
        $this->stubWordPress();

        Filters\expectApplied('taipb_token_budget')
            ->once()
            ->with(PromptBudget::DEFAULT_CEILING, ['post_type' => 'page'])
            ->andReturn(4242);

        $this->assertSame(4242, PromptBudget::resolve_ceiling(['post_type' => 'page']));
    }

    /**
     * The constant layer is deliberately NOT asserted here.
     *
     * `TAIPB_TOKEN_BUDGET` cannot be undefined once set, so a test defining it would change the
     * resolved ceiling for every test that runs afterwards in the same process -- the assertion
     * would pass and quietly break its neighbours. Its behaviour is a two-line `defined()` branch
     * in `resolve_ceiling()`; the filter test above covers the precedence mechanism.
     */
    public function test_constant_layer_is_documented_not_asserted(): void
    {
        $this->assertTrue(true);
    }
}
