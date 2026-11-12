<?php

namespace TimberlandAIPageBuilder;

use Anthropic\Client;
use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\Tool\InputSchema;
use Anthropic\Messages\ToolResultBlockParam;
use Anthropic\Messages\ToolUseBlockParam;
use Anthropic\Core\Errors\AuthenticationError;
use Anthropic\Core\Errors\RateLimitError;
use Anthropic\Core\Errors\AnthropicError;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wraps the Anthropic PHP SDK for Claude API calls.
 */
class ClaudeClient implements LLMClientInterface
{
    private Client $client;
    private string $model;
    private int $max_tokens;

    public function __construct(?string $model_override = null)
    {
        $settings = Plugin::get_settings();

        $api_key = defined('TAIPB_API_KEY') ? TAIPB_API_KEY : ($settings['api_key'] ?? '');

        if (empty($api_key)) {
            throw new \RuntimeException('Anthropic API key is not configured.');
        }

        $this->client = new Client(apiKey: $api_key);
        $this->model = $model_override ?? ($settings['model'] ?? 'claude-sonnet-4-5-20250929');
        $this->max_tokens = (int) ($settings['max_tokens'] ?? 8192);
    }

    /**
     * Send a generation request to the Claude API.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate(string $system_prompt, string $user_prompt): array
    {
        // Increase PHP's socket timeout for long Claude API calls.
        // Symfony HttpClient uses default_socket_timeout as its idle timeout,
        // and complex prompts can take 2-3 minutes before Claude starts responding.
        $prev_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '600');

        try {
            $response = $this->client->messages->create(
                model: $this->model,
                maxTokens: $this->max_tokens,
                system: $system_prompt,
                messages: [
                    MessageParam::with(
                        role: 'user',
                        content: $user_prompt,
                    ),
                ],
            );

            // Extract text content from response blocks
            $content = '';
            foreach ($response->content as $block) {
                if ($block->type === 'text') {
                    $content = $block->text;
                    break;
                }
            }

            return [
                'content' => $content,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'model' => $response->model,
                'stop_reason' => $response->stopReason,
            ];
        } catch (AuthenticationError $e) {
            // Redacted because this is the exact path that printed an API key in clear text:
            // a 404 body echoed back the value sent as the model name, and the message was
            // raised verbatim. Provider error text is untrusted output, not a diagnostic.
            throw new \RuntimeException('Invalid API key: ' . Redact::credentials($e->getMessage()));
        } catch (RateLimitError $e) {
            throw new \RuntimeException('Anthropic rate limit reached. Please wait and try again.');
        } catch (AnthropicError $e) {
            throw new \RuntimeException('Claude API error: ' . Redact::credentials($e->getMessage()));
        } finally {
            ini_set('default_socket_timeout', $prev_timeout ?: '60');
        }
    }

    /**
     * Run the tool loop against the Messages API.
     *
     * **The system prompt is sent as a cached block.** It is byte-identical across every turn of
     * a generation and across generations within the cache TTL, and at ~4,500 tokens it clears
     * the 1,024-token minimum comfortably. A cache hit bills that prefix at a fraction of the
     * input rate, which is what makes it affordable to keep the block catalogue in context for
     * the whole conversation instead of dropping it before composing.
     *
     * **Tools stop being offered at the cap rather than the loop erroring.** A model that keeps
     * fetching is asked for its final answer with no tools attached, so it still produces a page.
     * Throwing there would turn a verbose model into a failed generation.
     *
     * Usage is accumulated across every turn, because a caller shown only the last turn's figures
     * would under-report what the generation actually cost -- the same reason the retry path sums
     * both attempts.
     *
     * @param array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param callable(string, array<string, mixed>): string $dispatch
     */
    public function generate_with_tools(
        string $system_prompt,
        string $user_prompt,
        array $tools,
        callable $dispatch,
        int $max_tool_turns = 4
    ): array {
        $sdk_tools = [];

        foreach ($tools as $tool) {
            $schema = $tool['input_schema'] ?? [];
            $sdk_tools[] = Tool::with(
                inputSchema: InputSchema::with(
                    properties: $schema['properties'] ?? null,
                    required: $schema['required'] ?? null,
                ),
                name: (string) $tool['name'],
                description: isset($tool['description']) ? (string) $tool['description'] : null,
            );
        }

        $system = [
            TextBlockParam::with(text: $system_prompt, cacheControl: CacheControlEphemeral::with()),
        ];

        $messages = [MessageParam::with(role: 'user', content: $user_prompt)];

        $turns = 0;
        $input_tokens = 0;
        $output_tokens = 0;
        $cache_read = 0;
        $cache_write = 0;
        $model = $this->model;

        // Same reason as generate(): Symfony HttpClient uses default_socket_timeout as its idle
        // timeout, and a tool loop makes several long calls rather than one.
        $prev_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '600');

        try {
            while (true) {
                $offer_tools = $turns < $max_tool_turns && $sdk_tools !== [];

                $response = $offer_tools
                    ? $this->client->messages->create(
                        model: $this->model,
                        maxTokens: $this->max_tokens,
                        system: $system,
                        messages: $messages,
                        tools: $sdk_tools,
                    )
                    : $this->client->messages->create(
                        model: $this->model,
                        maxTokens: $this->max_tokens,
                        system: $system,
                        messages: $messages,
                    );

                $input_tokens += $response->usage->inputTokens;
                $output_tokens += $response->usage->outputTokens;
                $cache_read += $response->usage->cacheReadInputTokens ?? 0;
                $cache_write += $response->usage->cacheCreationInputTokens ?? 0;
                $model = $response->model;

                $text = '';
                $tool_uses = [];

                foreach ($response->content as $block) {
                    if ($block->type === 'text') {
                        $text .= $block->text;
                    } elseif ($block->type === 'tool_use') {
                        $tool_uses[] = $block;
                    }
                }

                if ($tool_uses === []) {
                    return [
                        'content' => $text,
                        'input_tokens' => $input_tokens,
                        'output_tokens' => $output_tokens,
                        'model' => $model,
                        'stop_reason' => (string) $response->stopReason,
                        'tool_turns' => $turns,
                        'cache_read_tokens' => $cache_read,
                        'cache_write_tokens' => $cache_write,
                    ];
                }

                ++$turns;

                // The assistant's turn is replayed exactly as received -- text first, then every
                // tool_use. Dropping the text would lose reasoning the model refers back to, and
                // omitting a tool_use makes its tool_result unmatchable.
                //
                // Each block is serialised BEFORE going into the array. Nesting SDK objects
                // inside `MessageParam::$content` silently emits PHP property names instead of
                // API ones -- `toolUseID` rather than `tool_use_id` -- because that property is
                // typed `string|array` and the converter treats the array as opaque rather than
                // recursing. Verified offline: `json_encode($block)` alone gives `tool_use_id`,
                // the same block nested in a MessageParam gives `toolUseID`, and the API rejects
                // the latter. `jsonSerialize()` keeps the SDK's own `#[Api]` names authoritative
                // instead of hand-writing the keys here.
                $assistant = [];

                if ($text !== '') {
                    $assistant[] = TextBlockParam::with(text: $text)->jsonSerialize();
                }

                foreach ($tool_uses as $use) {
                    $assistant[] = ToolUseBlockParam::with(
                        id: $use->id,
                        input: $use->input,
                        name: $use->name,
                    )->jsonSerialize();
                }

                // The SDK declares `$content` as a list of block OBJECTS, but passing objects
                // is exactly what triggers its nesting defect (see above and
                // AnthropicWireShapeTest): they serialise with PHP property names. The runtime
                // signature is `string|array`, so pre-serialised arrays are accepted -- and are
                // the only shape the API accepts. The directive must be the LAST line before the
                // statement or it silences nothing.
                // @phpstan-ignore-next-line argument.type
                $messages[] = MessageParam::with(role: 'assistant', content: $assistant);

                // Every tool_use must be answered in ONE user turn, or the API rejects the next
                // request for an unmatched tool_use id.
                $results = [];

                foreach ($tool_uses as $use) {
                    $results[] = ToolResultBlockParam::with(
                        toolUseID: $use->id,
                        content: (string) $dispatch((string) $use->name, (array) $use->input),
                    )->jsonSerialize();
                }

                // The SDK declares `$content` as a list of block OBJECTS, but passing objects
                // is exactly what triggers its nesting defect (see above and
                // AnthropicWireShapeTest): they serialise with PHP property names. The runtime
                // signature is `string|array`, so pre-serialised arrays are accepted -- and are
                // the only shape the API accepts. The directive must be the LAST line before the
                // statement or it silences nothing.
                // @phpstan-ignore-next-line argument.type
                $messages[] = MessageParam::with(role: 'user', content: $results);
            }
        } catch (AuthenticationError $e) {
            throw new \RuntimeException('Invalid API key: ' . Redact::credentials($e->getMessage()));
        } catch (RateLimitError $e) {
            throw new \RuntimeException('Anthropic rate limit reached. Please wait and try again.');
        } catch (AnthropicError $e) {
            throw new \RuntimeException('Claude API error: ' . Redact::credentials($e->getMessage()));
        } finally {
            ini_set('default_socket_timeout', $prev_timeout ?: '60');
        }
    }

    /**
     * FG1's reduced-fidelity path: generate from an image instead of a Figma URL.
     *
     * Bytes, never a URL. `ImageBlockParam` also accepts a `URLImageSource`, which would have the
     * provider fetch a host we name -- the same SSRF shape FG2 closed on the Figma side, except
     * the request would come from the provider's network instead of ours. Base64 keeps the fetch
     * here, where the capability checks already are.
     *
     * The image goes BEFORE the prompt in the content array, which is Anthropic's documented
     * ordering for image-plus-question requests.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate_with_image(
        string $system_prompt,
        string $user_prompt,
        string $image_base64,
        string $media_type
    ): array {
        $prev_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '600');

        try {
            $response = $this->client->messages->create(
                model: $this->model,
                maxTokens: $this->max_tokens,
                system: $system_prompt,
                messages: [
                    MessageParam::with(
                        role: 'user',
                        content: [
                            /*
                             * A PLAIN ARRAY, not `ImageBlockParam`. Do not "tidy" this into the
                             * SDK's own class: nested inside a `MessageParam`, the SDK drops its
                             * own `#[Api('media_type')]` rename and serialises the field as
                             * `mediaType`, so the API rejects the request with
                             * `source.base64.media_type: Field required`. Verified by encoding
                             * both forms -- `Base64ImageSource` alone emits `media_type`
                             * correctly, and the same object inside a `MessageParam` does not.
                             *
                             * The text path does not hit this because `TextBlockParam` has no
                             * renamed fields, which is why the bug is invisible everywhere else
                             * in this client.
                             */
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => $media_type,
                                    'data' => $image_base64,
                                ],
                            ],
                            TextBlockParam::with(text: $user_prompt),
                        ],
                    ),
                ],
            );

            $content = '';

            foreach ($response->content as $block) {
                if ($block->type === 'text') {
                    $content = $block->text;
                    break;
                }
            }

            return [
                'content' => $content,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'model' => $response->model,
                'stop_reason' => $response->stopReason,
            ];
        } catch (AuthenticationError $e) {
            throw new \RuntimeException('Invalid API key: ' . Redact::credentials($e->getMessage()));
        } catch (RateLimitError $e) {
            throw new \RuntimeException('Anthropic rate limit reached. Please wait and try again.');
        } catch (AnthropicError $e) {
            throw new \RuntimeException('Claude API error: ' . Redact::credentials($e->getMessage()));
        } finally {
            ini_set('default_socket_timeout', (string) $prev_timeout);
        }
    }

    /** Verified live on 2026-11-11: a synthetic 5-band design came back as 5 sections in order. */
    public function vision_is_verified(): bool
    {
        return true;
    }

    /**
     * Send a generation request with a retry that includes validation feedback.
     */
    public function generate_with_retry(string $system_prompt, string $user_prompt, array $validation_errors): array
    {
        $retry_prompt = $user_prompt . "\n\n"
            . "Your previous response had validation errors. Please fix these issues:\n"
            . implode("\n", array_map(fn($e) => "- {$e}", $validation_errors))
            . "\n\nOutput only the corrected block markup, nothing else.";

        return $this->generate($system_prompt, $retry_prompt);
    }
}
