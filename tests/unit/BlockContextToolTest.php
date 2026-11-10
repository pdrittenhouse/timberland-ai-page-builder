<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\BlockContextTool;
use TimberlandAIPageBuilder\PromptBudget;
use TimberlandAIPageBuilder\PromptBuilder;
use TimberlandAIPageBuilder\Tests\Fixture\FakeLLMClient;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * The on-demand block context tool, and the safety property the whole design rests on.
 *
 * The property: **a block the model was not shown must be a block the model is told not to write.**
 * Anything else reproduces the `jumbotron_container` / `jumbotron_fluid` failure, where the model
 * invented six field names for a block it had never seen described. These tests pin the wording
 * the model actually receives, because that string is the only thing standing between a withheld
 * block and an invented one.
 */
class BlockContextToolTest extends TestCase
{
    use WordPressStubs;

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    private function tool(?PromptBudget $budget = null): BlockContextTool
    {
        $manifest = ManifestFixture::manifest();

        return new BlockContextTool(
            $manifest,
            $budget ?? new PromptBudget([], 8000),
            new PromptBuilder($manifest)
        );
    }

    public function test_serves_field_detail_for_a_requested_block(): void
    {
        $this->stubWordPress();
        $result = $this->tool()->dispatch(BlockContextTool::NAME, ['slugs' => ['card']]);

        $this->assertStringContainsString('acf/card', $result);
        $this->assertStringContainsString('card_title', $result);
        $this->assertStringContainsString('card_body', $result);
    }

    public function test_one_call_serves_several_blocks(): void
    {
        $this->stubWordPress();
        $tool = $this->tool();
        $result = $tool->dispatch(BlockContextTool::NAME, ['slugs' => ['section', 'card']]);

        $this->assertStringContainsString('acf/section', $result);
        $this->assertStringContainsString('acf/card', $result);
        $this->assertSame(['acf/section', 'acf/card'], $tool->served());
    }

    public function test_accepts_both_the_bare_and_prefixed_spelling(): void
    {
        $this->stubWordPress();
        $prefixed = $this->tool();
        $prefixed->dispatch(BlockContextTool::NAME, ['slugs' => ['acf/card']]);
        $this->assertSame(['acf/card'], $prefixed->served());

        $bare = $this->tool();
        $bare->dispatch(BlockContextTool::NAME, ['slugs' => ['card']]);
        $this->assertSame(['acf/card'], $bare->served(), 'both spellings must resolve to the same block');
    }

    public function test_an_unknown_block_is_named_and_refused_not_ignored(): void
    {
        $this->stubWordPress();
        $tool = $this->tool();
        $result = $tool->dispatch(BlockContextTool::NAME, ['slugs' => ['jumbotron']]);

        $this->assertStringContainsString('NOT A BLOCK ON THIS SITE', $result);
        $this->assertStringContainsString('Do not use it', $result);
        $this->assertSame(['jumbotron'], $tool->unknown());
        $this->assertSame([], $tool->served());
    }

    public function test_a_block_the_budget_cannot_afford_is_declared_unavailable(): void
    {
        $this->stubWordPress();
        // A ceiling too small for any block's field detail.
        $tool = $this->tool(new PromptBudget([], 5));
        $result = $tool->dispatch(BlockContextTool::NAME, ['slugs' => ['card']]);

        $this->assertStringContainsString('UNAVAILABLE', $result);
        $this->assertStringContainsString('Do NOT write this block', $result);
        $this->assertStringNotContainsString('card_title', $result, 'a withheld block must not leak its fields');
        $this->assertSame([], $tool->served(), 'it must not be recorded as served');
        $this->assertContains('acf/card', $tool->withheld());
    }

    public function test_an_unknown_tool_name_keeps_the_model_in_the_loop(): void
    {
        $this->stubWordPress();
        $result = $this->tool()->dispatch('get_everything', []);

        $this->assertStringContainsString('Unknown tool', $result);
        $this->assertStringContainsString(BlockContextTool::NAME, $result);
    }

    public function test_a_missing_slugs_argument_is_answered_not_thrown(): void
    {
        $this->stubWordPress();
        $result = $this->tool()->dispatch(BlockContextTool::NAME, []);

        $this->assertStringContainsString('`slugs` argument is required', $result);
    }

    public function test_the_loop_runs_end_to_end_through_the_client_seam(): void
    {
        $this->stubWordPress();
        $tool = $this->tool();

        $client = new FakeLLMClient([
            [['name' => BlockContextTool::NAME, 'input' => ['slugs' => ['section', 'card']]]],
            '<!-- wp:acf/card {"name":"acf/card","data":{"card_title":"Hi"}} /-->',
        ]);

        $response = $client->generate_with_tools(
            'system',
            'Add a card',
            [$tool->definition()],
            fn(string $name, array $input): string => $tool->dispatch($name, $input)
        );

        $this->assertSame(1, $response['tool_turns']);
        $this->assertStringContainsString('acf/card', $response['content']);
        $this->assertSame(['acf/section', 'acf/card'], $tool->served());

        $calls = $client->toolCalls();
        $this->assertCount(1, $calls, 'the plural argument means one call, not one per block');
        $this->assertStringContainsString('card_title', $calls[0]['result']);
    }

    public function test_the_turn_cap_stops_the_loop_without_failing_the_generation(): void
    {
        $this->stubWordPress();
        $tool = $this->tool();

        // A model that keeps asking and never writes.
        $client = new FakeLLMClient([
            [['name' => BlockContextTool::NAME, 'input' => ['slugs' => ['card']]]],
            [['name' => BlockContextTool::NAME, 'input' => ['slugs' => ['section']]]],
            '<!-- wp:acf/card /-->',
        ]);

        $response = $client->generate_with_tools(
            'system',
            'Add a card',
            [$tool->definition()],
            fn(string $name, array $input): string => $tool->dispatch($name, $input),
            2
        );

        $this->assertSame('max_tool_turns', $response['stop_reason']);
        $this->assertSame(2, $response['tool_turns']);
        $this->assertNotSame('', $response['content'], 'hitting the cap must still yield a page');
    }
}
