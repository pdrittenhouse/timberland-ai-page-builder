<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decomposes free-text prompts into structured layout sections
 * using a lightweight LLM call for better pattern matching.
 */
class PromptDecomposer
{
    /**
     * Decompose a user prompt into structured sections.
     *
     * @return array{sections: array, overall_intent: string, suggested_pattern_ids: string[]}
     */
    public function decompose(string $user_prompt, array $manifest): array
    {
        $available = $this->build_pattern_list($manifest);

        $system = <<<PROMPT
You are a layout planning assistant. Given a user's description of a web page layout, decompose it into discrete sections/components.

Available patterns and layouts:
{$available}

Respond with ONLY valid JSON in this exact structure:
{
  "sections": [
    {
      "intent": "brief description of this section's purpose",
      "pattern_hint": "name of the closest matching pattern/layout, or empty string if none match",
      "pattern_id": "the pattern/layout ID (e.g. pattern_123 or layout_5), or empty string if none match",
      "content": {
        "title": "extracted title text if mentioned",
        "subtitle": "extracted subtitle if mentioned",
        "body": "extracted body/description text if mentioned"
      }
    }
  ],
  "overall_intent": "one-sentence summary of the full page",
  "suggested_pattern_ids": ["pattern_123", "layout_5"]
}

Rules:
- Extract as many discrete sections as the user describes
- Map each section to the closest available pattern/layout if possible
- Extract specific content values the user mentions (titles, descriptions, etc.)
- If the user doesn't specify content for a field, omit it from the content object
- Only suggest patterns that are a genuine match — do not force matches
- suggested_pattern_ids should contain every unique pattern_id from the sections array
- If the user describes structural elements like sections/rows/columns without mentioning a specific component, still create a section entry describing the structure but leave pattern_hint and pattern_id empty
PROMPT;

        $client = $this->get_decomposer_client();
        $response = $client->generate($system, $user_prompt);

        return $this->parse_decomposition($response['content']);
    }

    /**
     * Get the cheapest available LLM client for decomposition.
     */
    private function get_decomposer_client(): LLMClientInterface
    {
        $settings = Plugin::get_settings();
        $has_anthropic = defined('TAIPB_API_KEY') || !empty($settings['api_key']);
        $has_openai = defined('TAIPB_OPENAI_API_KEY') || !empty($settings['openai_api_key']);

        if ($has_anthropic) {
            return new ClaudeClient('claude-haiku-4-5-20251001');
        }

        if ($has_openai) {
            return new OpenAIClient('gpt-4o-mini');
        }

        // Fall back to user's default model
        return LLMClientFactory::create();
    }

    /**
     * Build a compact list of available patterns and layouts for the decomposer prompt.
     */
    private function build_pattern_list(array $manifest): string
    {
        $lines = [];

        foreach ($manifest['patterns'] ?? [] as $pattern) {
            $id = 'pattern_' . ($pattern['id'] ?? '');
            $title = $pattern['title'] ?? '';
            $cats = implode(', ', $pattern['categories'] ?? []);
            $lines[] = "- {$id}: {$title}" . ($cats ? " [{$cats}]" : '');
        }

        foreach ($manifest['layouts'] ?? [] as $index => $layout) {
            $id = 'layout_' . $index;
            $name = $layout['name'] ?? '';
            $type = $layout['type'] ?? '';
            $lines[] = "- {$id}: {$name} ({$type})";
        }

        return implode("\n", $lines);
    }

    /**
     * Parse the LLM's JSON response into a structured array.
     */
    private function parse_decomposition(string $raw): array
    {
        $empty = [
            'sections' => [],
            'overall_intent' => '',
            'suggested_pattern_ids' => [],
        ];

        // Strip markdown code fences if present
        $raw = preg_replace('/^```(?:json)?\s*\n?/i', '', trim($raw));
        $raw = preg_replace('/\n?```\s*$/', '', $raw);

        $parsed = json_decode(trim($raw), true);

        if (!is_array($parsed) || !isset($parsed['sections'])) {
            return $empty;
        }

        // Ensure suggested_pattern_ids is populated from sections if missing
        if (empty($parsed['suggested_pattern_ids'])) {
            $ids = [];
            foreach ($parsed['sections'] as $section) {
                if (!empty($section['pattern_id'])) {
                    $ids[] = $section['pattern_id'];
                }
            }
            $parsed['suggested_pattern_ids'] = array_values(array_unique($ids));
        }

        return [
            'sections' => $parsed['sections'] ?? [],
            'overall_intent' => $parsed['overall_intent'] ?? '',
            'suggested_pattern_ids' => $parsed['suggested_pattern_ids'] ?? [],
        ];
    }
}
