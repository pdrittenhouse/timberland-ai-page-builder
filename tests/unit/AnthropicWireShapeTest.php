<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\Tool\InputSchema;
use Anthropic\Messages\ToolResultBlockParam;
use Anthropic\Messages\ToolUseBlockParam;
use PHPUnit\Framework\TestCase;

/**
 * The exact JSON `ClaudeClient::generate_with_tools()` puts on the wire.
 *
 * The tool loop cannot be covered by `FakeLLMClient` -- the fake proves the pipeline, not the
 * payload -- and a live call costs money and needs a key, so the wire shape would otherwise be
 * unverified until the first real generation failed.
 *
 * It catches a real defect in `anthropic-ai/sdk` v0.1.0: **an SDK block object nested inside
 * `MessageParam::$content` serialises with PHP property names instead of API ones.**
 * `json_encode($block)` alone gives `tool_use_id`; the same block inside a `MessageParam` gives
 * `toolUseID`, which the API rejects. The property is typed `string|array`, so the converter
 * treats the array as opaque rather than recursing into it. Calling `jsonSerialize()` on each
 * block before nesting is the fix, and these assertions are what keep it applied.
 */
class AnthropicWireShapeTest extends TestCase
{
    public function test_a_nested_sdk_block_serialises_with_php_names_which_is_why_we_pre_serialise(): void
    {
        $block = ToolResultBlockParam::with(toolUseID: 'toolu_x', content: 'fields');

        // The SDK on its own is right...
        $this->assertStringContainsString('"tool_use_id"', (string) json_encode($block));

        // ...but not once nested. This asserts the DEFECT, so the day the SDK fixes it this test
        // fails and tells us the workaround can go, rather than leaving it in place for ever.
        $nested = MessageParam::with(role: 'user', content: [$block]);
        $this->assertStringContainsString(
            '"toolUseID"',
            (string) json_encode($nested),
            'SDK nesting defect appears fixed — drop the jsonSerialize() workaround in ClaudeClient'
        );
    }

    public function test_pre_serialised_tool_result_uses_the_api_field_name(): void
    {
        $message = MessageParam::with(role: 'user', content: [
            ToolResultBlockParam::with(toolUseID: 'toolu_x', content: 'fields')->jsonSerialize(),
        ]);

        $json = (string) json_encode($message);

        $this->assertStringContainsString('"tool_use_id":"toolu_x"', $json);
        $this->assertStringNotContainsString('toolUseID', $json);
        $this->assertStringContainsString('"type":"tool_result"', $json);
    }

    public function test_pre_serialised_assistant_turn_carries_text_then_tool_use(): void
    {
        $message = MessageParam::with(role: 'assistant', content: [
            TextBlockParam::with(text: 'looking that up')->jsonSerialize(),
            ToolUseBlockParam::with(id: 'toolu_x', input: ['slugs' => ['hero-unit']], name: 'get_block_fields')
                ->jsonSerialize(),
        ]);

        $json = (string) json_encode($message);

        $this->assertStringContainsString('"role":"assistant"', $json);
        $this->assertStringContainsString('"type":"text"', $json);
        $this->assertStringContainsString('"type":"tool_use"', $json);
        $this->assertStringContainsString('"id":"toolu_x"', $json);
        $this->assertStringContainsString('"slugs":["hero-unit"]', $json);
    }

    public function test_the_cached_system_prefix_keeps_its_api_names(): void
    {
        // A plain PHP array, not an SdkModel, so the converter is never involved -- which is why
        // this one needs no workaround. Asserted so a future refactor that wraps it in a model
        // does not silently lose `cache_control`.
        $system = [TextBlockParam::with(text: 'rules', cacheControl: CacheControlEphemeral::with())];

        $json = (string) json_encode($system);

        $this->assertStringContainsString('"cache_control":{"type":"ephemeral"}', $json);
        $this->assertStringNotContainsString('cacheControl', $json);
    }

    public function test_the_tool_definition_serialises_as_input_schema(): void
    {
        $tool = Tool::with(
            inputSchema: InputSchema::with(
                properties: ['slugs' => ['type' => 'array', 'items' => ['type' => 'string']]],
                required: ['slugs'],
            ),
            name: 'get_block_fields',
            description: 'Get field names for blocks.',
        );

        $json = (string) json_encode($tool);

        $this->assertStringContainsString('"input_schema"', $json);
        $this->assertStringNotContainsString('inputSchema', $json);
        $this->assertStringContainsString('"required":["slugs"]', $json);
    }
}
