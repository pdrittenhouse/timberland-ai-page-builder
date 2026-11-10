<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Strip credentials from text that is about to be shown, logged or sent to a model.
 *
 * Exists because an Anthropic API key was printed in clear text during this project: a smoke test
 * passed constructor arguments positionally, the key landed where the model name belonged, and the
 * API echoed it back inside a 404 body that was printed raw. Nothing in the key's own handling was
 * wrong -- the leak was an error message.
 *
 * So provider error text is treated as untrusted output, not as a diagnostic to print verbatim.
 */
class Redact
{
    /** Anthropic/OpenAI (`sk-`) and Figma (`figd_`). */
    private const PATTERNS = [
        '/\bsk-[A-Za-z0-9_\-]{8,}/',
        '/\bfigd_[A-Za-z0-9_\-]{8,}/',
    ];

    public static function credentials(string $text): string
    {
        return (string) preg_replace(self::PATTERNS, '<redacted>', $text);
    }
}
