<?php

namespace TimberlandAIPageBuilder;

use Anthropic\Client;
use Anthropic\Messages\MessageParam;
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
            throw new \RuntimeException('Invalid API key: ' . $e->getMessage());
        } catch (RateLimitError $e) {
            throw new \RuntimeException('Anthropic rate limit reached. Please wait and try again.');
        } catch (AnthropicError $e) {
            throw new \RuntimeException('Claude API error: ' . $e->getMessage());
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
