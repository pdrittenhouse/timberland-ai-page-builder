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
        $this->group_cache = [];
        $this->field_cache = [];

        // Load only Block: and Module: field groups
        $this->load_relevant_groups();

        // For each group that targets a block, build the field key map
        foreach ($this->group_cache as $group_key => $group) {
            $block_names = $this->extract_block_targets($group);

            foreach ($block_names as $block_name) {
                if (!isset($this->block_field_maps[$block_name])) {
                    $this->block_field_maps[$block_name] = [];
                    $this->block_field_types[$block_name] = [];
                }

                $types = [];
                $fields = $this->flatten_fields($group['fields'] ?? [], '', 0, $types);
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

        return $this->block_field_maps;
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
     * Load "Block: " and "Module: " field group JSON files from parent and child themes.
     */
    private function load_relevant_groups(): void
    {
        $dirs = [];

        $parent_dir = get_template_directory() . '/src/fields';
        if (is_dir($parent_dir)) {
            $dirs[] = $parent_dir;
        }

        $child_dir = get_stylesheet_directory() . '/src/fields';
        if ($child_dir !== $parent_dir && is_dir($child_dir)) {
            $dirs[] = $child_dir;
        }

        foreach ($dirs as $dir) {
            $files = glob($dir . '/group_*.json');
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
    private function flatten_fields(array $fields, string $prefix, int $depth = 0, array &$types = []): array
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
            } elseif (!empty($field['sub_fields'])) {
                // Group or repeater: map parent, then recurse into sub_fields
                $map[$full_name] = $key;
                $types[$full_name] = $type;
                $sub_prefix = $full_name . '_';
                $map = array_merge($map, $this->flatten_fields($field['sub_fields'], $sub_prefix, $depth + 1, $types));
            } else {
                $map[$full_name] = $key;
                $types[$full_name] = $type;
            }
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

                        if (!empty($field['sub_fields'])) {
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
