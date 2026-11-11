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

        $this->validate_placement($markup, $errors, $warnings);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'block_count' => $block_count,
        ];
    }

    /**
     * Where each block SITS, which nothing checked until now.
     *
     * Two distinct failures, both observed in live generations and both invisible to every other
     * check here, because the rest of this class validates a block's `data` and never its position:
     *
     *  1. A block declaring `parent` placed somewhere else. `acf/table-cell` belongs inside
     *     `acf/table-row` and nowhere else -- 11 of 48 blocks carry such a restriction -- and
     *     WordPress enforces it in the editor while nothing enforced it on generated markup.
     *  2. A block outside the catalogue entirely. A generation emitted `core/heading` and
     *     `core/paragraph` after `get_block_fields` had explicitly reported them UNAVAILABLE and
     *     the prompt had said "there are no others". They render, so nothing complained -- but they
     *     carry no ACF fields and no design-system styling, which is the silent approximation
     *     `report_gap` exists to replace. A warning, not an error: core blocks are legal WordPress
     *     and an operator may want one; what they must not be is unremarked.
     *
     * Deliberately NOT enforced here: the curated `avoid_when` guidance, such as "Feature is a
     * full-width band, not for a Column". That is prose, and prose is a label rather than a gate --
     * FG3's rule. Making it enforceable needs a machine-readable negative-placement vocabulary,
     * which WordPress itself does not have (`parent`, `ancestor` and `allowedBlocks` are all
     * positive), and inventing one is a decision rather than a fix. Measured: `acf/feature` is
     * still placed inside `acf/column` with its AVOID clause reaching the model.
     *
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function validate_placement(string $markup, array &$errors, array &$warnings): void
    {
        $blocks = $this->manifest['blocks'] ?? [];
        $stack = [];
        $seen_foreign = [];

        // The namespace is OPTIONAL in block markup, and that is not a detail.
        //
        // WordPress serialises its own blocks without it -- `<!-- wp:heading -->` is `core/heading`
        // -- so a pattern requiring `ns/name` matches every ACF block and no core one. The first
        // version of this check did exactly that and reported zero warnings on markup containing
        // two headings and two paragraphs, which read as "nothing to report" rather than "the check
        // cannot see them".
        preg_match_all(
            '/<!--\s*(\/)?wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?:\s+\{.*?\})?\s*(\/)?-->/s',
            $markup,
            $tokens,
            PREG_SET_ORDER
        );

        foreach ($tokens as $token) {
            $closing = '' !== ($token[1] ?? '');
            $name = (string) $token[2];
            $self_closing = '' !== ($token[3] ?? '');

            // Normalise to the canonical name so `wp:heading` and `core/heading` are one thing.
            if (!str_contains($name, '/')) {
                $name = 'core/' . $name;
            }

            if ($closing) {
                array_pop($stack);
                continue;
            }

            $parent = end($stack) ?: '';

            if (!str_starts_with($name, 'acf/')) {
                // Reported once per block type: a page of paragraphs should not produce a page of
                // identical warnings.
                if (!isset($seen_foreign[$name])) {
                    $seen_foreign[$name] = true;
                    $warnings[] = sprintf(
                        'Block `%s` is not in the block catalogue. It will render, but it carries no '
                        . 'ACF fields and no design-system styling%s.',
                        $name,
                        '' !== $parent ? sprintf(' (inside `%s`)', $parent) : ''
                    );
                }
            }

            $allowed = $blocks[$name]['parent'] ?? null;

            if (is_array($allowed) && $allowed !== [] && !in_array($parent, $allowed, true)) {
                $errors[] = sprintf(
                    'Block `%s` may only be placed inside %s, but it is %s.',
                    $name,
                    '`' . implode('`, `', $allowed) . '`',
                    '' !== $parent ? 'inside `' . $parent . '`' : 'at the top level'
                );
            }

            if (!$self_closing) {
                $stack[] = $name;
            }
        }
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

        $field_type_map = $manifest_block['field_type_map'] ?? [];
        $field_variants = $manifest_block['field_variants'] ?? [];

        // Check that every field value has its _fieldname companion
        foreach ($data as $key => $value) {
            // Skip underscore-prefixed keys (they are the companions)
            if (str_starts_with($key, '_')) {
                continue;
            }

            $companion_key = '_' . $key;
            $expected_key = $this->map_key_for($key, $field_key_map, $field_type_map);

            if (!isset($data[$companion_key])) {
                // Check if this field exists in the field key map
                if ($expected_key !== null) {
                    $errors[] = "Block `{$name}`: field `{$key}` is missing its `{$companion_key}` field key companion. Expected value: `{$expected_key}`.";
                } else {
                    $warnings[] = "Block `{$name}`: field `{$key}` has no `{$companion_key}` companion and is not in the field key map.";
                }
            } else {
                // Verify the companion value matches the known field key.
                //
                // A name ACF defines MORE THAN ONCE has several legitimate keys -- `logo_count`
                // is a select under flickity and a number under slick -- and the generator picks
                // between them from the conditional rules. Accepting only the key map's single
                // answer reported the correct choice as wrong.
                $permitted = $this->permitted_keys($key, $expected_key, $field_key_map, $field_type_map, $field_variants);

                if ($permitted !== [] && !in_array($data[$companion_key], $permitted, true)) {
                    $expected_list = count($permitted) > 1
                        ? 'one of `' . implode('`, `', $permitted) . '`'
                        : '`' . $permitted[0] . '`';
                    $errors[] = "Block `{$name}`: field `{$key}` has wrong field key `{$data[$companion_key]}`. Expected {$expected_list}.";
                }
            }
        }

        $this->validate_against_schema($name, $data, $errors, $warnings);
    }

    /**
     * Every field key this data key may legitimately carry.
     *
     * Usually one. More when ACF defines the name several times under conditional logic, where
     * each variant's key is correct for the branch that variant belongs to -- checking which
     * branch the data is actually on is the generator's job, not the validator's, so any of them
     * is accepted here.
     *
     * @param array<string, string> $field_key_map
     * @param array<string, string> $field_type_map
     * @param array<string, array<int, array{key: string}>> $field_variants
     * @return array<int, string> empty when the block has no such field at all
     */
    private function permitted_keys(
        string $key,
        ?string $expected_key,
        array $field_key_map,
        array $field_type_map,
        array $field_variants
    ): array {
        $name = $key;

        // A repeater row carries its index; variants are declared on the flattened name.
        if (!isset($field_variants[$name]) && preg_match('/^(.+?)_(\d+)_(.+)$/', $key, $parts)) {
            [, $parent, , $sub] = $parts;
            $parent_type = $field_type_map[$parent] ?? '';

            if ($parent_type === 'repeater' || $parent_type === 'flexible_content') {
                $name = $parent . '_' . $sub;
            }
        }

        if (isset($field_variants[$name])) {
            $keys = [];

            foreach ($field_variants[$name] as $variant) {
                if (!empty($variant['key'])) {
                    $keys[] = (string) $variant['key'];
                }
            }

            if ($keys !== []) {
                return $keys;
            }
        }

        return $expected_key === null ? [] : [$expected_key];
    }

    /**
     * Resolve a data key to its authoritative ACF field key, or null if the block has no such
     * field.
     *
     * The direct lookup covers most keys. The second branch exists because a **repeater row is
     * stored with its index in the name** -- `col_width_0_breakpoint` -- while the key map holds
     * sub-fields without it (`col_width_breakpoint`). Without stripping the index, every
     * generated repeater row was reported as "not in the field key map": a warning that named a
     * real, correctly-mapped field and told the operator nothing actionable. `acf/column` alone
     * produced four of them per generation.
     *
     * The index is only stripped when the parent really is a repeater or flexible-content field,
     * so a field whose own name happens to contain `_0_` is never silently rewritten to a
     * different field's key.
     *
     * @param array<string, string> $field_key_map
     * @param array<string, string> $field_type_map
     */
    private function map_key_for(string $key, array $field_key_map, array $field_type_map): ?string
    {
        if (isset($field_key_map[$key])) {
            return $field_key_map[$key];
        }

        if (!preg_match('/^(.+?)_(\d+)_(.+)$/', $key, $parts)) {
            return null;
        }

        [, $parent, , $sub] = $parts;
        $parent_type = $field_type_map[$parent] ?? '';

        if ($parent_type !== 'repeater' && $parent_type !== 'flexible_content') {
            return null;
        }

        return $field_key_map[$parent . '_' . $sub] ?? null;
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
        // phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
        // -- `wp_json_encode()` is the right call for anything that reaches a response. These
        // two do not: they only convert arrays into the stdClass tree json-schema requires, and
        // the result is consumed in the next statement. Using the WordPress wrapper would add a
        // WordPress dependency to a pure-logic path that the unit suite deliberately runs
        // without WordPress loaded, which is a worse trade than this annotation.
        $subject = json_decode(json_encode($payload === [] ? new \stdClass() : $payload));

        $validator->validate($subject, json_decode(json_encode($schema)));
        // phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode

        foreach ($validator->getErrors() as $error) {
            $property = (string) ($error['property'] ?? '');
            $message = (string) ($error['message'] ?? '');
            $constraint = $error['constraint'] ?? '';
            $constraint = is_array($constraint) ? (string) ($constraint['name'] ?? '') : (string) $constraint;

            $where = $property !== '' ? "field `{$property}`" : 'data object';

            if ($constraint === 'required') {
                // A required field that declares a `default` is genuinely harmless to omit --
                // ACF writes the default on save, so the stored block is correct either way.
                // `show_ids` is required on all 48 blocks and defaults to 0, which made it 11 of
                // the ~14 warnings on every single generation: noise that trained the operator to
                // ignore the warning list, including the entries that did mean something.
                // Required fields WITHOUT a default still warn, because there the omission
                // really can leave the block incomplete. This is NOT the conditional case:
                // a field ACF only requires on a branch (`modal_id` when `trigger_modal` is on)
                // is no longer in `required` at all -- the generator moved it into an
                // `anyOf`/`not` guard that asserts it only when its branch is live.
                if ($property !== '' && array_key_exists('default', $schema['properties'][$property] ?? [])) {
                    continue;
                }

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
