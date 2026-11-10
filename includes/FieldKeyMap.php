<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds field_name → field_key maps for each ACF block.
 *
 * This is critical for generating valid ACF block markup where every
 * field value must have a companion _fieldname → field_key entry.
 *
 * Only loads "Block: " and "Module: " field groups — block-targeted
 * groups and the utility groups they clone.
 */
class FieldKeyMap
{
    /** @var array<string, array<string, string>> Block name → [field_name → field_key] */
    private array $block_field_maps = [];

    /** @var array<string, array<string, string>> Block name → [field_name → field_type] */
    private array $block_field_types = [];

    /**
     * Block name → [field_name → list of candidate definitions], for names ACF defines MORE THAN
     * ONCE.
     *
     * ACF permits several fields with one name in a block, separated only by conditional logic --
     * `acf/logo-slider.logo_count` is a `select` of words when `slider == flickity` and a `number`
     * when `slider == slick`. A name-keyed map keeps whichever group is merged last, so the other
     * variant's companion key was simply wrong, with nothing to indicate it.
     *
     * Only ambiguous names appear here; a name with one definition is absent, so a consumer can
     * treat `isset()` as "this needs disambiguating".
     *
     * @var array<string, array<string, array<int, array{key: string, type: string, choices: array<int, string>, conditional: array<int, array<int, array{field: string, operator: string, value: string}>>}>>>
     */
    private array $block_field_variants = [];

    /** @var array<string, array> Loaded field group cache: group_key → group data */
    private array $group_cache = [];

    /** @var array<string, array> Loaded individual field cache: field_key → field data */
    private array $field_cache = [];

    /** @var string[] Field group title prefixes to load */
    private const RELEVANT_PREFIXES = ['Block: ', 'Module: '];

    /**
     * Build field key maps for all ACF blocks.
     *
     * @return array<string, array<string, string>> Block name → [field_name → field_key]
     */
    public function build(): array
    {
        $this->block_field_maps = [];
        $this->block_field_types = [];
        $this->block_field_variants = [];
        $this->group_cache = [];
        $this->field_cache = [];

        // Load only Block: and Module: field groups
        $this->load_relevant_groups();

        /**
         * `block == all` is an ACF WILDCARD, not a block name.
         *
         * `Block: Buttons` targets every block that way. Taking the rule's value literally
         * created a pseudo-block called `all` holding 157 keys, and those keys then reached NO
         * real block: `acf/section` carried 0 of them and `acf/card` only 3, by coincidental name
         * overlap. So a writer setting `button_list` on any block had no `field_xxx` key for it,
         * silently -- the same shape as the two other lookups in this class that found nothing.
         *
         * Wildcard groups are collected here and merged into every block below.
         */
        $wildcard = ['fields' => [], 'types' => []];

        // For each group that targets a block, build the field key map
        foreach ($this->group_cache as $group_key => $group) {
            $block_names = $this->extract_block_targets($group);

            foreach ($block_names as $block_name) {
                $types = [];
                $defs = [];
                $fields = $this->flatten_fields($group['fields'] ?? [], '', 0, $types, $defs);

                if ($block_name === 'all') {
                    $wildcard['fields'] = array_merge($wildcard['fields'], $fields);
                    $wildcard['types'] = array_merge($wildcard['types'], $types);
                    continue;
                }

                // Accumulated across groups, because the same name can be defined in two of them.
                foreach ($defs as $name => $list) {
                    foreach ($list as $def) {
                        $this->block_field_variants[$block_name][$name][$def['key']] = $def;
                    }
                }

                if (!isset($this->block_field_maps[$block_name])) {
                    $this->block_field_maps[$block_name] = [];
                    $this->block_field_types[$block_name] = [];
                }

                $this->block_field_maps[$block_name] = array_merge(
                    $this->block_field_maps[$block_name],
                    $fields
                );
                $this->block_field_types[$block_name] = array_merge(
                    $this->block_field_types[$block_name],
                    $types
                );
            }
        }

        // A block's own fields win over a wildcard's on a name collision: the block declared it
        // deliberately, the wildcard applies to everything.
        if ($wildcard['fields']) {
            foreach ($this->block_field_maps as $block_name => $map) {
                $this->block_field_maps[$block_name] = array_merge($wildcard['fields'], $map);
                $this->block_field_types[$block_name] = array_merge(
                    $wildcard['types'],
                    $this->block_field_types[$block_name] ?? []
                );
            }
        }

        // A single definition is not a variant. Dropping those here is what lets a consumer read
        // `isset($variants[$name])` as "ambiguous" with no further test.
        foreach ($this->block_field_variants as $block_name => $names) {
            foreach ($names as $name => $defs) {
                if (count($defs) < 2) {
                    unset($this->block_field_variants[$block_name][$name]);
                }
            }

            if (empty($this->block_field_variants[$block_name])) {
                unset($this->block_field_variants[$block_name]);
            }
        }

        // Resolve each rule's field KEY to the field NAME a data object is keyed by. Without
        // this a consumer would have to reverse the map itself, and a rule naming a key absent
        // from this block would look like a rule that cannot be satisfied.
        foreach ($this->block_field_variants as $block_name => $names) {
            $by_key = array_flip($this->block_field_maps[$block_name] ?? []);

            foreach ($names as $name => $defs) {
                foreach ($defs as $i => $def) {
                    $groups = [];

                    foreach ($def['conditional'] as $group) {
                        $rules = [];

                        foreach ((array) $group as $rule) {
                            $dep = $by_key[$rule['field'] ?? ''] ?? null;

                            if ($dep === null) {
                                // Unresolvable, so the whole group is dropped rather than
                                // half-evaluated into a condition that is not the real one.
                                $rules = [];
                                break;
                            }

                            $rules[] = [
                                'field' => $dep,
                                'operator' => (string) ($rule['operator'] ?? '=='),
                                'value' => (string) ($rule['value'] ?? ''),
                            ];
                        }

                        if ($rules) {
                            $groups[] = $rules;
                        }
                    }

                    $defs[$i]['conditional'] = $groups;
                }

                $this->block_field_variants[$block_name][$name] = array_values($defs);
            }
        }

        return $this->block_field_maps;
    }

    /**
     * Candidate definitions for the names this block defines more than once.
     *
     * @return array<string, array<int, array{key: string, type: string, choices: array<int, string>, conditional: array<int, array<int, array{field: string, operator: string, value: string}>>}>>
     */
    public function get_block_variants(string $block_name): array
    {
        return $this->block_field_variants[$block_name] ?? [];
    }

    /**
     * @return array<string, array<string, array<int, array{key: string, type: string, choices: array<int, string>, conditional: array<int, array<int, array{field: string, operator: string, value: string}>>}>>>
     */
    public function get_all_variants(): array
    {
        return $this->block_field_variants;
    }

    /**
     * Get the field key map for a specific block.
     */
    public function get_block_map(string $block_name): array
    {
        return $this->block_field_maps[$block_name] ?? [];
    }

    /**
     * Get all block field maps.
     *
     * @return array<string, array<string, string>>
     */
    public function get_all(): array
    {
        return $this->block_field_maps;
    }

    /**
     * Get all block field type maps.
     *
     * @return array<string, array<string, string>> Block name → [field_name → field_type]
     */
    public function get_all_types(): array
    {
        return $this->block_field_types;
    }

    /**
     * Load "Block: " and "Module: " field group JSON, from the paths ACF itself is told about.
     *
     * AM1 -- this previously globbed `get_template_directory() . '/src/fields/group_*.json'`,
     * which was wrong twice over and therefore loaded NOTHING:
     *
     *   - no tier has a `src/fields` directory at all; the ACF JSON lives in `src/acf-json`
     *   - the files are not named `group_*.json`; they are `block-card.json`,
     *     `module-button.json` and so on
     *
     * So every field key map was empty, silently, and a writer had no `field_xxx` key to put in
     * a block's `data`.
     *
     * The fix is deliberately NOT a corrected directory list. Re-deriving tier resolution here
     * would be a second implementation of something this stack has already got wrong twice, and
     * it would drift from the real one. The framework registers every tier's path with ACF via
     * `acf/settings/load_json` (Timberland\Framework\Fields::acf_json_load_point), so asking
     * ACF is asking the authority. Measured on a four-tier install: 5 paths, 106 files --
     * framework 88, timberland-extended 17, child theme 1.
     */
    private function load_relevant_groups(): void
    {
        // `acf_get_setting()` exists whenever ACF is active, which is this plugin's hard
        // dependency. The fallback is for the ACF-absent case only, where it finds nothing and
        // the map is empty -- which is the correct answer, not a failure.
        $dirs = function_exists('acf_get_setting')
            ? (array) acf_get_setting('load_json')
            : [get_template_directory() . '/src/acf-json'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . '/*.json');
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $group = json_decode($content, true);
                if (!$group || empty($group['key']) || empty($group['title'])) {
                    continue;
                }

                // Only load Block: and Module: groups
                $is_relevant = false;
                foreach (self::RELEVANT_PREFIXES as $prefix) {
                    if (str_starts_with($group['title'], $prefix)) {
                        $is_relevant = true;
                        break;
                    }
                }

                if (!$is_relevant) {
                    continue;
                }

                $this->group_cache[$group['key']] = $group;
                $this->index_fields($group['fields'] ?? []);
            }
        }
    }

    /**
     * Recursively index all fields by their key for clone resolution.
     */
    private function index_fields(array $fields): void
    {
        foreach ($fields as $field) {
            if (!empty($field['key'])) {
                $this->field_cache[$field['key']] = $field;
            }

            if (!empty($field['sub_fields'])) {
                $this->index_fields($field['sub_fields']);
            }
        }
    }

    /**
     * Extract block names from a field group's location rules.
     *
     * @return string[] Block names (e.g., ['acf/card'])
     */
    private function extract_block_targets(array $group): array
    {
        $blocks = [];

        foreach ($group['location'] ?? [] as $location_group) {
            foreach ($location_group as $rule) {
                if (
                    isset($rule['param'], $rule['operator'], $rule['value'])
                    && $rule['param'] === 'block'
                    && $rule['operator'] === '=='
                ) {
                    $block_name = str_replace('\\/', '/', $rule['value']);
                    $blocks[] = $block_name;
                }
            }
        }

        return array_unique($blocks);
    }

    /**
     * Recursively flatten fields into a name → key map.
     *
     * Handles clone fields (with prefix_name), group sub_fields,
     * and repeater sub_fields.
     *
     * @param array  $fields The fields array to process
     * @param string $prefix Current name prefix (from parent clone/group)
     * @param int    $depth  Recursion guard
     * @param array  &$types Output: field_name → field_type (for image annotation)
     * @return array<string, string> field_name → field_key
     */
    private function flatten_fields(array $fields, string $prefix, int $depth = 0, array &$types = [], array &$defs = []): array
    {
        if ($depth > 10) {
            return [];
        }

        $map = [];

        foreach ($fields as $field) {
            // Skip UI-only fields (no data stored)
            if (in_array($field['type'] ?? '', ['accordion', 'tab', 'message'], true)) {
                continue;
            }

            $name = $field['name'] ?? '';
            $key = $field['key'] ?? '';
            $type = $field['type'] ?? '';

            if (empty($name) || empty($key)) {
                continue;
            }

            $full_name = $prefix . $name;

            if ($type === 'clone') {
                // Add the clone field ITSELF to the map — ACF needs the clone parent
                // entry (e.g., "image": "", "_image": "field_xxx") to reconstruct
                // nested field structures like fields.image from flat data.
                $map[$full_name] = $key;
                $types[$full_name] = $type;
                $map = array_merge($map, $this->resolve_clone($field, $prefix, $depth, $types));
            } elseif (!empty($field['layouts'])) {
                /**
                 * FLEXIBLE CONTENT keeps its fields under `layouts[].sub_fields`, not
                 * `sub_fields`, so descending only the latter reached nothing at all --
                 * `acf/posts-loop.promo_layout` has 13 layouts and the map had 0 children.
                 *
                 * A layout contributes NO name segment: ACF stores a row as
                 * `{field}_{index}_{subfield}`, so the un-indexed form is `{field}_{subfield}`.
                 * Every layout is flattened under the same prefix for that reason.
                 *
                 * Two layouts may define the same sub-field name with different types. That is
                 * the same shape as the conditional same-name case, and `$defs` already records
                 * every candidate, so a collision surfaces as a variant rather than one
                 * definition silently winning.
                 */
                $map[$full_name] = $key;
                $types[$full_name] = $type;
                $sub_prefix = $full_name . '_';

                foreach ($field['layouts'] as $layout) {
                    if (empty($layout['sub_fields'])) {
                        continue;
                    }

                    $map = array_merge(
                        $map,
                        $this->flatten_fields($layout['sub_fields'], $sub_prefix, $depth + 1, $types, $defs)
                    );
                }
            } elseif (!empty($field['sub_fields'])) {
                // Group or repeater: map parent, then recurse into sub_fields
                $map[$full_name] = $key;
                $types[$full_name] = $type;
                $sub_prefix = $full_name . '_';
                $map = array_merge($map, $this->flatten_fields($field['sub_fields'], $sub_prefix, $depth + 1, $types, $defs));
            } else {
                $map[$full_name] = $key;
                $types[$full_name] = $type;
            }

            // Recorded for EVERY leaf, because ambiguity is only visible once the whole group has
            // been walked. build() discards the names that turned out to have one definition.
            $defs[$full_name][] = [
                'key' => $key,
                'type' => $type,
                'choices' => array_map('strval', array_keys($field['choices'] ?? [])),
                // The rules are what SEPARATE two same-named fields, so they are the only
                // non-guessing way to tell which one a given data object means. Stored raw here
                // (rules reference field KEYS); build() resolves those to names once the block's
                // full map exists.
                'conditional' => $field['conditional_logic'] ?? [],
            ];
        }

        return $map;
    }

    /**
     * Resolve a clone field into its constituent field name → key mappings.
     *
     * Clone fields can reference:
     * - Full field groups (group_xxxxx) — inlines all fields from that group
     * - Individual fields (field_xxxxx) — inlines specific fields
     *
     * When prefix_name is 1, the clone field's name is prepended to all
     * cloned field names (e.g., clone "card_bg_color" + field "bg_color"
     * becomes "card_bg_color_bg_color").
     */
    private function resolve_clone(array $clone_field, string $parent_prefix, int $depth, array &$types = []): array
    {
        $map = [];
        $clone_refs = $clone_field['clone'] ?? [];
        $prefix_name = !empty($clone_field['prefix_name']);
        $clone_name = $clone_field['name'] ?? '';

        $clone_prefix = $parent_prefix;
        if ($prefix_name && $clone_name) {
            $clone_prefix .= $clone_name . '_';
        }

        foreach ($clone_refs as $ref) {
            if (str_starts_with($ref, 'group_')) {
                // Cloning an entire field group
                $group = $this->group_cache[$ref] ?? null;
                if ($group && !empty($group['fields'])) {
                    $map = array_merge(
                        $map,
                        $this->flatten_fields($group['fields'], $clone_prefix, $depth + 1, $types)
                    );
                }
            } elseif (str_starts_with($ref, 'field_')) {
                // Cloning individual fields
                $field = $this->field_cache[$ref] ?? null;
                if ($field) {
                    $field_name = $field['name'] ?? '';
                    if ($field_name) {
                        $full_name = $clone_prefix . $field_name;
                        $map[$full_name] = $field['key'];
                        $types[$full_name] = $field['type'] ?? '';

                        if (($field['type'] ?? '') === 'clone') {
                            // A cloned field that is ITSELF a clone has no `sub_fields` -- it has
                            // its own `clone` refs -- so descending sub_fields finds nothing and
                            // the whole subtree is lost. `acf/modal.header_close_button` clones a
                            // list of FIELD refs including `icon_left` and `icon_right`, both
                            // clones, and the map ended with `header_close_button_display` but no
                            // `header_close_button_display_display`.
                            //
                            // The GROUP branch above never had this problem: it hands the group's
                            // fields to flatten_fields, which handles a nested clone properly. That
                            // asymmetry is why `resolve_clone()` looked correct when tested --
                            // the test exercised the group branch.
                            //
                            // `$clone_prefix` is passed, not `$full_name`, so the nested clone's
                            // own `prefix_name` decides whether its name is added -- the same rule
                            // flatten_fields applies.
                            $map = array_merge(
                                $map,
                                $this->resolve_clone($field, $clone_prefix, $depth + 1, $types)
                            );
                        } elseif (!empty($field['sub_fields'])) {
                            $sub_prefix = $full_name . '_';
                            $map = array_merge(
                                $map,
                                $this->flatten_fields($field['sub_fields'], $sub_prefix, $depth + 1, $types)
                            );
                        }
                    }
                }
            }
        }

        return $map;
    }
}
