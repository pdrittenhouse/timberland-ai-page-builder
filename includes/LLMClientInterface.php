<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Common interface for LLM API clients.
 *
 * All providers must return the same normalized response array.
 */
interface LLMClientInterface
{
    /**
     * Send a generation request.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate(string $system_prompt, string $user_prompt): array;

    /**
     * Send a generation request with a retry that includes validation feedback.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate_with_retry(string $system_prompt, string $user_prompt, array $validation_errors): array;
}
