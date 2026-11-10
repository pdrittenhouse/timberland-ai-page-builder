<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\BlockContextTool;
use TimberlandAIPageBuilder\GapTool;
use TimberlandAIPageBuilder\PromptBudget;
use TimberlandAIPageBuilder\PromptBuilder;
use TimberlandAIPageBuilder\Tests\Fixture\FakeLLMClient;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * L3 — a request no component can express is REPORTED, never approximated.
 *
 * The failure this prevents is the worst one available: the model picks the nearest block, the
 * page renders, nothing validates as wrong, and the operator concludes the product is low quality
 * rather than that their request was out of scope.
 *
 * The distinction these tests exist to pin: **a block the budget withheld is not a gap.** One is
 * a ceiling an operator can raise; the other is a component that does not exist. Conflating them
 * would put phantom entries on a roadmap and hide a real budget problem behind a feature request.
 */
class GapReportingTest extends TestCase
{
    use WordPressStubs;

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    public function test_a_reported_gap_is_structured_not_prose(): void
    {
        $tool = new GapTool();
        $reply = $tool->dispatch([
            'need' => 'a pricing comparison table with per-tier feature ticks',
            'why' => 'card-grid renders cards, not a comparison matrix',
            'closest' => 'acf/card-grid',
        ]);

        $this->assertTrue($tool->has_gaps());
        $this->assertSame([[
            'need' => 'a pricing comparison table with per-tier feature ticks',
            'why' => 'card-grid renders cards, not a comparison matrix',
            'closest' => 'acf/card-grid',
        ]], $tool->gaps());

        // The reply must keep the model producing the rest of the page.
        $this->assertStringContainsString('produce markup for the parts', $reply);
    }

    public function test_an_incomplete_gap_is_refused_with_guidance_not_recorded(): void
    {
        $tool = new GapTool();
        $reply = $tool->dispatch(['need' => 'something']);

        $this->assertFalse($tool->has_gaps(), 'a gap with no reason is not a usable roadmap entry');
        $this->assertStringContainsString('required', $reply);
    }

    public function test_closest_is_optional(): void
    {
        $tool = new GapTool();
        $tool->dispatch(['need' => 'a countdown timer', 'why' => 'nothing renders a timer']);

        $this->assertSame('', $tool->gaps()[0]['closest']);
    }

    public function test_a_budget_withheld_block_is_not_a_gap(): void
    {
        $this->stubWordPress();
        $manifest = ManifestFixture::manifest();

        // A ceiling too small for any field detail: card gets withheld.
        $budget = new PromptBudget([], 5);
        $context = new BlockContextTool($manifest, $budget, new PromptBuilder($manifest));
        $context->dispatch(BlockContextTool::NAME, ['slugs' => ['card']]);

        $this->assertContains('acf/card', $context->withheld());
        $this->assertSame([], $context->unknown(), 'a withheld block exists — it must not be reported as unknown');

        // And the gap tool, untouched, has nothing to say about it.
        $this->assertFalse((new GapTool())->has_gaps());
    }

    public function test_an_unknown_block_is_a_gap_signal(): void
    {
        $this->stubWordPress();
        $manifest = ManifestFixture::manifest();
        $context = new BlockContextTool($manifest, new PromptBudget([], 8000), new PromptBuilder($manifest));
        $context->dispatch(BlockContextTool::NAME, ['slugs' => ['countdown-timer']]);

        $this->assertSame(['countdown-timer'], $context->unknown());
        $this->assertSame([], $context->withheld(), 'a block that does not exist was never withheld');
    }

    public function test_both_tools_route_correctly_through_one_dispatcher(): void
    {
        $this->stubWordPress();
        $manifest = ManifestFixture::manifest();
        $context = new BlockContextTool($manifest, new PromptBudget([], 8000), new PromptBuilder($manifest));
        $gap = new GapTool();

        $dispatch = static function (string $name, array $input) use ($context, $gap): string {
            return $name === GapTool::NAME ? $gap->dispatch($input) : $context->dispatch($name, $input);
        };

        $client = new FakeLLMClient([
            [
                ['name' => BlockContextTool::NAME, 'input' => ['slugs' => ['card']]],
                ['name' => GapTool::NAME, 'input' => ['need' => 'a countdown', 'why' => 'no timer block']],
            ],
            '<!-- wp:acf/card {"name":"acf/card","data":{"card_title":"Hi"}} /-->',
        ]);

        $response = $client->generate_with_tools(
            'system',
            'A card and a countdown',
            [$context->definition(), $gap->definition()],
            $dispatch
        );

        // The page for the expressible part still came back...
        $this->assertStringContainsString('acf/card', $response['content']);
        $this->assertSame(['acf/card'], $context->served());
        // ...and the gap came back beside it, not instead of it.
        $this->assertTrue($gap->has_gaps());
        $this->assertSame('a countdown', $gap->gaps()[0]['need']);
    }
}
