<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates the appropriate LLM client based on model name.
 */
class LLMClientFactory
{
    private const ANTHROPIC_PREFIXES = ['claude-'];
    private const OPENAI_PREFIXES = ['gpt-', 'o1-', 'o3-', 'o4-'];

    /**
     * Create an LLM client for the given model.
     * Falls back to the admin settings default if no model is specified.
     */
    public static function create(?string $model = null): LLMClientInterface
    {
        $model ??= Plugin::get_settings()['model'] ?? 'claude-sonnet-4-5-20250929';

        $provider = self::detect_provider($model);

        return match ($provider) {
            'openai' => new OpenAIClient($model),
            'anthropic' => new ClaudeClient($model),
            default => throw new \RuntimeException("Unknown model provider for model: {$model}"),
        };
    }

    /**
     * Detect the provider from a model name string.
     */
    public static function detect_provider(string $model): string
    {
        foreach (self::ANTHROPIC_PREFIXES as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return 'anthropic';
            }
        }

        foreach (self::OPENAI_PREFIXES as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return 'openai';
            }
        }

        return 'unknown';
    }
}
