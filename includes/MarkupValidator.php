<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates generated block markup before sending it to the editor.
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

        // Parse blocks using WordPress parser
        $blocks = parse_blocks($markup);
        $blocks = array_filter($blocks, fn($b) => !empty($b['blockName']));

        if (empty($blocks)) {
            $errors[] = 'No valid blocks found in markup.';
            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'block_count' => 0,
            ];
        }

        $block_count = 0;
        $this->validate_blocks_recursive($blocks, $errors, $warnings, $block_count);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'block_count' => $block_count,
        ];
    }

    /**
     * Recursively validate parsed blocks.
     */
    private function validate_blocks_recursive(array $blocks, array &$errors, array &$warnings, int &$block_count, int $depth = 0): void
    {
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            $block_count++;
            $name = $block['blockName'];

            // Check if this is an ACF block
            if (str_starts_with($name, 'acf/')) {
                $this->validate_acf_block($name, $block['attrs'] ?? [], $errors, $warnings);
            }

            // Validate inner blocks recursively
            if (!empty($block['innerBlocks'])) {
                // Check that this block is a container
                $manifest_block = $this->manifest['blocks'][$name] ?? null;
                if ($manifest_block && !$manifest_block['is_container']) {
                    $warnings[] = "Block `{$name}` has inner blocks but is not marked as a container (jsx: false).";
                }

                $this->validate_blocks_recursive($block['innerBlocks'], $errors, $warnings, $block_count, $depth + 1);
            }
        }
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
     * Strip markdown code fences from Claude output.
     */
    private function strip_markdown_fences(string $markup): string
    {
        // Remove ```html ... ``` or ``` ... ``` wrappers
        $markup = preg_replace('/^```(?:html)?\s*\n/i', '', $markup);
        $markup = preg_replace('/\n```\s*$/', '', $markup);

        return trim($markup);
    }
}
