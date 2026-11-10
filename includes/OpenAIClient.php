<?php

namespace TimberlandAIPageBuilder;

use OpenAI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wraps the OpenAI PHP SDK for ChatGPT API calls.
 */
class OpenAIClient implements LLMClientInterface
{
    private \OpenAI\Client $client;
    private string $model;
    private int $max_tokens;

    public function __construct(?string $model_override = null)
    {
        $settings = Plugin::get_settings();

        $api_key = defined('TAIPB_OPENAI_API_KEY')
            ? TAIPB_OPENAI_API_KEY
            : ($settings['openai_api_key'] ?? '');

        if (empty($api_key)) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $this->client = OpenAI::client($api_key);
        $this->model = $model_override ?? 'gpt-4o';
        $this->max_tokens = (int) ($settings['max_tokens'] ?? 8192);
    }

    /**
     * Send a generation request to the OpenAI Chat Completions API.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate(string $system_prompt, string $user_prompt): array
    {
        $prev_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '600');

        try {
            $response = $this->client->chat()->create([
                'model' => $this->model,
                'max_tokens' => $this->max_tokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_prompt],
                ],
            ]);

            $content = $response->choices[0]->message->content ?? '';

            // Normalize stop reason to Claude convention
            $finish_reason = $response->choices[0]->finishReason ?? 'stop';
            $stop_reason = match ($finish_reason) {
                'stop' => 'end_turn',
                'length' => 'max_tokens',
                default => $finish_reason,
            };

            return [
                'content' => $content,
                'input_tokens' => $response->usage->promptTokens ?? 0,
                'output_tokens' => $response->usage->completionTokens ?? 0,
                'model' => $response->model,
                'stop_reason' => $stop_reason,
            ];
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            throw new \RuntimeException('OpenAI API error: ' . $e->getMessage());
        } catch (\OpenAI\Exceptions\TransporterException $e) {
            throw new \RuntimeException('OpenAI connection error: ' . $e->getMessage());
        } finally {
            ini_set('default_socket_timeout', $prev_timeout ?: '60');
        }
    }

    /**
     * Run the tool loop against the Chat Completions API.
     *
     * The shape differs from Anthropic's in every detail -- `tools` wrap the schema in a
     * `function` envelope, the model answers with `tool_calls` carrying a JSON STRING of
     * arguments rather than a decoded object, and each result goes back as its own
     * `role: tool` message instead of content blocks inside one user turn. Keeping that
     * divergence inside the client is the entire reason `LLMClientInterface` takes a
     * provider-neutral tool array and a dispatcher.
     *
     * No `cache_control` here: OpenAI caches long prefixes automatically, so the cached-prefix
     * saving arrives without being asked for. The reported cache figure comes from
     * `cachedTokens` when the SDK exposes it, and 0 otherwise -- a 0 means "not reported",
     * not "no caching happened".
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
        $api_tools = [];

        foreach ($tools as $tool) {
            $api_tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => []],
                ],
            ];
        }

        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_prompt],
        ];

        $turns = 0;
        $input_tokens = 0;
        $output_tokens = 0;
        $cache_read = 0;
        $model = $this->model;

        $prev_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '600');

        try {
            while (true) {
                $payload = [
                    'model' => $this->model,
                    'max_tokens' => $this->max_tokens,
                    'messages' => $messages,
                ];

                if ($turns < $max_tool_turns && $api_tools !== []) {
                    $payload['tools'] = $api_tools;
                }

                $response = $this->client->chat()->create($payload);

                // No `?? 0`: both are declared non-nullable on CreateResponseUsage, and the
                // coalesce in generate() above only survives because it predates the baseline.
                $input_tokens += $response->usage->promptTokens;
                $output_tokens += $response->usage->completionTokens;
                $model = $response->model;

                $message = $response->choices[0]->message ?? null;
                $tool_calls = $message->toolCalls ?? [];

                if ($tool_calls === []) {
                    $finish_reason = $response->choices[0]->finishReason ?? 'stop';

                    return [
                        'content' => $message->content ?? '',
                        'input_tokens' => $input_tokens,
                        'output_tokens' => $output_tokens,
                        'model' => $model,
                        'stop_reason' => match ($finish_reason) {
                            'stop' => 'end_turn',
                            'length' => 'max_tokens',
                            default => (string) $finish_reason,
                        },
                        'tool_turns' => $turns,
                        'cache_read_tokens' => $cache_read,
                        'cache_write_tokens' => 0,
                    ];
                }

                ++$turns;

                $assistant = ['role' => 'assistant', 'content' => $message->content ?? null, 'tool_calls' => []];

                foreach ($tool_calls as $call) {
                    $assistant['tool_calls'][] = [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->function->name,
                            'arguments' => $call->function->arguments,
                        ],
                    ];
                }

                $messages[] = $assistant;

                foreach ($tool_calls as $call) {
                    // Arguments arrive as a JSON string. A model that emits malformed JSON must
                    // get an answer it can correct, not an exception that ends the generation.
                    $decoded = json_decode((string) $call->function->arguments, true);

                    $result = is_array($decoded)
                        ? (string) $dispatch((string) $call->function->name, $decoded)
                        : 'Could not parse the arguments as JSON. Send a valid JSON object.';

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call->id,
                        'content' => $result,
                    ];
                }
            }
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            throw new \RuntimeException('OpenAI API error: ' . $e->getMessage());
        } catch (\OpenAI\Exceptions\TransporterException $e) {
            throw new \RuntimeException('OpenAI connection error: ' . $e->getMessage());
        } finally {
            ini_set('default_socket_timeout', $prev_timeout ?: '60');
        }
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
