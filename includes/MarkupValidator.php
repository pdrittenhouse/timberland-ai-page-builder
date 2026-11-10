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

    private ?SchemaOracle $oracle = null;

    public function __construct(array $manifest, ?SchemaOracle $oracle = null)
    {        $this->manifest = $manifest;
        // Optional so every existing call site keeps working; injectable so the unit suite can
        // point it at fixture schemas instead of a live theme's build output.
        $this->oracle = $oracle;
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

        $this->validate_against_schema($name, $data, $errors, $warnings);
    }

    /**
     * Check a block's data object against its JSON Schema.
     *
     * This is the half the field-key check above cannot do. That one proves each value has the
     * right ACF key companion; this one proves the FIELD EXISTS on this block at all and that
     * its value is one the field accepts. Before it, a model could invent `card_subtitel`, pair
     * it with a plausible-looking key, and the output validated clean.
     *
     * Three things worth knowing about how the schemas are shaped:
     *
     * - **The `_` companions are stripped first.** Every schema is
     *   `additionalProperties: false` and declares NONE of the `_fieldname` keys — they are an
     *   ACF storage mechanism, not part of the field contract. Validating the raw data object
     *   would therefore report every companion as an unknown property, on every block. The
     *   companions are already checked above, so the split is clean rather than a gap.
     *
     * - **`required` is a warning, not an error.** Measured across all 41 schemas: 1-3 required
     *   fields each, 7 distinct names, and all of them settings ACF fills from a field default
     *   on save (`show_ids`, `responsive_settings`, `modal_id`, `image`). A generating model
     *   omitting one is normal; treating that as an error would reject almost every block.
     *
     * - **An unavailable schema is silence, not a pass.** `SchemaOracle` records what it could
     *   not resolve, and that is surfaced separately. Validation that quietly stops validating
     *   while still reporting "valid" is worse than none.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $errors
     * @param array<int, string>   $warnings
     */
    private function validate_against_schema(string $name, array $data, array &$errors, array &$warnings): void
    {
        if ($this->oracle === null) {
            return;
        }

        $schema = $this->oracle->schema_for($name);

        if ($schema === null) {
            return;
        }

        $payload = [];

        foreach ($data as $key => $value) {
            if (!str_starts_with((string) $key, '_')) {
                $payload[$key] = $value;
            }
        }

        $validator = new \JsonSchema\Validator();

        // The library works on decoded objects, not associative arrays. An empty data object has
        // to become `{}` rather than `[]`, or it validates as an array against an object schema.
        $subject = json_decode(json_encode($payload === [] ? new \stdClass() : $payload));

        $validator->validate($subject, json_decode(json_encode($schema)));

        foreach ($validator->getErrors() as $error) {
            $property = (string) ($error['property'] ?? '');
            $message = (string) ($error['message'] ?? '');
            $constraint = $error['constraint'] ?? '';
            $constraint = is_array($constraint) ? (string) ($constraint['name'] ?? '') : (string) $constraint;

            $where = $property !== '' ? "field `{$property}`" : 'data object';

            if ($constraint === 'required') {
                $warnings[] = "Block `{$name}`: {$where} {$message} ACF will fall back to the field's default.";
                continue;
            }

            $errors[] = "Block `{$name}`: {$where} {$message}";
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
