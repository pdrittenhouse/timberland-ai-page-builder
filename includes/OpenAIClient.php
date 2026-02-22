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
