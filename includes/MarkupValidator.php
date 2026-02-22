<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates generated block markup before sending it to the editor.
 *
 * Uses regex-based parsing instead of WordPress's parse_blocks() to
 * avoid memory exhaustion on large generated markup. The WordPress
 * block parser duplicates innerHTML for every block node, which can
 * easily exceed PHP memory limits on complex layouts.
 */
class MarkupValidator
{
    private array $manifest;

    public function __construct(array $manifest)
    {
        $this->manifest = $manifest;
    }

    /**
     * Validate a markup string.
     *
     * @return array{valid: bool, errors: string[], warnings: string[], block_count: int}
     */
    public function validate(string $markup): array
    {
        $errors = [];
        $warnings = [];

        // Strip markdown fences if Claude wrapped the output
        $markup = $this->strip_markdown_fences($markup);

        if (empty(trim($markup))) {
            return [
                'valid' => false,
                'errors' => ['Empty markup'],
                'warnings' => [],
                'block_count' => 0,
            ];
        }

        // Count all blocks via regex (core + ACF, including nested)
        $block_count = $this->count_blocks_regex($markup);

        if ($block_count === 0) {
            $errors[] = 'No valid blocks found in markup.';
            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'block_count' => 0,
            ];
        }

        // Validate ACF blocks by extracting their JSON attributes via regex
        preg_match_all(
            '/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) (?:\/)?-->/s',
            $markup,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $block_name = 'acf/' . $match[1];
            $block_data = json_decode($match[2], true);
            if (!$block_data) {
                continue;
            }
            $this->validate_acf_block($block_name, $block_data, $errors, $warnings);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'block_count' => $block_count,
        ];
    }

    /**
     * Lightweight validation that checks ACF block attributes via regex
     * without counting blocks. Use after post-processing where block
     * structure hasn't changed — only JSON attributes were fixed.
     *
     * @param int $block_count Block count from a prior full validation.
     * @return array{valid: bool, errors: string[], warnings: string[], block_count: int}
     */
    public function validate_attributes(string $markup, int $block_count): array
    {
        $markup = $this->strip_markdown_fences($markup);
        $errors = [];
        $warnings = [];

        // Extract ACF block JSON via regex
        preg_match_all(
            '/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) (?:\/)?-->/s',
            $markup,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $block_name = 'acf/' . $match[1];
            $block_data = json_decode($match[2], true);
            if (!$block_data) {
                continue;
            }
            $this->validate_acf_block($block_name, $block_data, $errors, $warnings);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'block_count' => $block_count,
        ];
    }

    /**
     * Count blocks in markup using regex. Matches block opening comments
     * like <!-- wp:core/heading --> and <!-- wp:acf/hero {"data":{}} -->.
     */
    private function count_blocks_regex(string $markup): int
    {
        // Match block openers: <!-- wp:namespace/name or <!-- wp:name
        // This captures both self-closing (/-->) and paired blocks.
        return preg_match_all(
            '/<!-- wp:([a-z][a-z0-9-]*\/)?[a-z][a-z0-9-]*[\s\{]/s',
            $markup
        );
    }

    /**
     * Validate an individual ACF block.
     */
    private function validate_acf_block(string $name, array $attrs, array &$errors, array &$warnings): void
    {
        $manifest_block = $this->manifest['blocks'][$name] ?? null;

        // Check block exists in manifest
        if (!$manifest_block) {
            $warnings[] = "Block `{$name}` not found in manifest. It may still be valid if registered.";
            return;
        }

        $data = $attrs['data'] ?? [];
        if (empty($data)) {
            $warnings[] = "Block `{$name}` has no data object.";
            return;
        }

        $field_key_map = $manifest_block['field_key_map'] ?? [];
        if (empty($field_key_map)) {
            return;
        }

        // Check that every field value has its _fieldname companion
        foreach ($data as $key => $value) {
            // Skip underscore-prefixed keys (they are the companions)
            if (str_starts_with($key, '_')) {
                continue;
            }

            $companion_key = '_' . $key;

            if (!isset($data[$companion_key])) {
                // Check if this field exists in the field key map
                if (isset($field_key_map[$key])) {
                    $errors[] = "Block `{$name}`: field `{$key}` is missing its `{$companion_key}` field key companion. Expected value: `{$field_key_map[$key]}`.";
                } else {
                    $warnings[] = "Block `{$name}`: field `{$key}` has no `{$companion_key}` companion and is not in the field key map.";
                }
            } else {
                // Verify the companion value matches the known field key
                if (isset($field_key_map[$key]) && $data[$companion_key] !== $field_key_map[$key]) {
                    $errors[] = "Block `{$name}`: field `{$key}` has wrong field key `{$data[$companion_key]}`. Expected `{$field_key_map[$key]}`.";
                }
            }
        }
    }

    /**
     * Strip markdown code fences and any non-block text from LLM output.
     */
    private function strip_markdown_fences(string $markup): string
    {
        $markup = preg_replace('/^```(?:html|xml|php|plaintext)?\s*\n?/im', '', $markup);
        $markup = preg_replace('/\n?```\s*$/m', '', $markup);

        $first_block = strpos($markup, '<!-- wp:');
        if ($first_block !== false && $first_block > 0) {
            $markup = substr($markup, $first_block);
        }

        $last_close = strrpos($markup, '-->');
        if ($last_close !== false) {
            $markup = substr($markup, 0, $last_close + 3);
        }

        return trim($markup);
    }
}
