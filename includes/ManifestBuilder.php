<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-generates a manifest from the WordPress environment:
 * ACF blocks, field schemas, Genesis layouts, editor patterns, and post types.
 */
class ManifestBuilder
{
    private FieldKeyMap $field_key_map;

    public function __construct(FieldKeyMap $field_key_map)
    {
        $this->field_key_map = $field_key_map;
    }

    /**
     * Build the full manifest.
     */
    public function build(): array
    {
        // Build field key maps first (reads JSON files)
        $block_field_maps = $this->field_key_map->build();
        $block_field_types = $this->field_key_map->get_all_types();

        $settings = Plugin::get_settings();

        $manifest = [
            'version' => TAIPB_VERSION,
            'generated_at' => gmdate('c'),
            'blocks' => $this->collect_blocks($block_field_maps, $block_field_types),
            'layouts' => $settings['include_genesis_layouts'] ? $this->collect_genesis_layouts() : [],
            'patterns' => $settings['include_editor_patterns'] ? $this->collect_editor_patterns() : [],
            'post_types' => $this->collect_post_types(),
            'taxonomies' => $this->collect_taxonomies(),
            'nesting_rules' => [],
        ];

        // Derive nesting rules from block data
        $manifest['nesting_rules'] = $this->derive_nesting_rules($manifest['blocks']);

        return $manifest;
    }

    /**
     * Collect ACF blocks from block.json files in parent and child themes.
     */
    private function collect_blocks(array $block_field_maps, array $block_field_types = []): array
    {
        $blocks = [];

        $block_dirs = $this->get_block_directories();

        foreach ($block_dirs as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $entries = scandir($directory);
            if (!$entries) {
                continue;
            }

            foreach ($entries as $entry) {
                // Skip hidden files and non-directories
                if (str_starts_with($entry, '.') || !is_dir($directory . '/' . $entry)) {
                    continue;
                }

                $block_json_path = $directory . '/' . $entry . '/block.json';
                if (!file_exists($block_json_path)) {
                    continue;
                }

                $content = file_get_contents($block_json_path);
                if ($content === false) {
                    continue;
                }

                $block_json = json_decode($content, true);
                if (!$block_json || empty($block_json['name'])) {
                    continue;
                }

                $name = $block_json['name'];
                $is_container = !empty($block_json['supports']['jsx']);

                $blocks[$name] = [
                    'name' => $name,
                    'title' => $block_json['title'] ?? $entry,
                    'description' => $block_json['description'] ?? '',
                    'category' => $block_json['category'] ?? '',
                    'keywords' => $block_json['keywords'] ?? [],
                    'is_container' => $is_container,
                    'parent' => $block_json['parent'] ?? null,
                    'provides_context' => !empty($block_json['providesContext']),
                    'uses_context' => !empty($block_json['usesContext']),
                    'supports' => $this->simplify_supports($block_json['supports'] ?? []),
                    'field_key_map' => $block_field_maps[$name] ?? [],
                    'field_type_map' => $block_field_types[$name] ?? [],
                    'field_count' => count($block_field_maps[$name] ?? []),
                    'usage_notes' => '',
                ];
            }
        }

        // Sort blocks alphabetically by name
        ksort($blocks);

        return $blocks;
    }

    /**
     * Get block directories from parent and child themes.
     */
    private function get_block_directories(): array
    {
        $dirs = [];

        $parent_dir = get_template_directory() . '/src/templates/blocks';
        if (is_dir($parent_dir)) {
            $dirs[] = $parent_dir;
        }

        $child_dir = get_stylesheet_directory() . '/src/templates/blocks';
        if ($child_dir !== $parent_dir && is_dir($child_dir)) {
            $dirs[] = $child_dir;
        }

        return $dirs;
    }

    /**
     * Simplify the supports object for the manifest (reduce token usage).
     */
    private function simplify_supports(array $supports): array
    {
        $simplified = [];

        if (!empty($supports['jsx'])) {
            $simplified[] = 'jsx';
        }
        if (!empty($supports['align'])) {
            $simplified[] = 'align';
        }
        if (!empty($supports['anchor'])) {
            $simplified[] = 'anchor';
        }
        if (!empty($supports['color']['background'])) {
            $simplified[] = 'color.background';
        }
        if (!empty($supports['color']['text'])) {
            $simplified[] = 'color.text';
        }
        if (!empty($supports['color']['gradients'])) {
            $simplified[] = 'color.gradients';
        }
        if (!empty($supports['typography']['fontSize'])) {
            $simplified[] = 'typography.fontSize';
        }

        return $simplified;
    }

    /**
     * Collect Genesis Blocks layouts and sections.
     */
    private function collect_genesis_layouts(): array
    {
        $layouts = [];

        // Check if Genesis Blocks is available
        if (!function_exists('genesis_blocks_get_layouts')) {
            return $layouts;
        }

        $gb_layouts = genesis_blocks_get_layouts();
        if (is_array($gb_layouts)) {
            foreach ($gb_layouts as $layout) {
                $layouts[] = [
                    'key' => $layout['key'] ?? '',
                    'name' => $layout['name'] ?? '',
                    'type' => 'layout',
                    'collection' => $layout['collection']['label'] ?? '',
                    'category' => $layout['category'] ?? [],
                    'keywords' => $layout['keywords'] ?? [],
                    'content' => $layout['content'] ?? '',
                    'usage_notes' => '',
                ];
            }
        }

        if (function_exists('genesis_blocks_get_sections')) {
            $gb_sections = genesis_blocks_get_sections();
            if (is_array($gb_sections)) {
                foreach ($gb_sections as $section) {
                    $layouts[] = [
                        'key' => $section['key'] ?? '',
                        'name' => $section['name'] ?? '',
                        'type' => 'section',
                        'collection' => $section['collection']['label'] ?? '',
                        'category' => $section['category'] ?? [],
                        'keywords' => $section['keywords'] ?? [],
                        'content' => $section['content'] ?? '',
                        'usage_notes' => '',
                    ];
                }
            }
        }

        return $layouts;
    }

    /**
     * Collect editor-saved patterns (wp_block post type).
     */
    private function collect_editor_patterns(): array
    {
        $patterns = [];

        $posts = get_posts([
            'post_type' => 'wp_block',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($posts as $post) {
            $categories = [];
            $terms = get_the_terms($post->ID, 'wp_pattern_category');
            if ($terms && !is_wp_error($terms)) {
                $categories = wp_list_pluck($terms, 'name');
            }

            $patterns[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'categories' => $categories,
                'content' => $post->post_content,
                'usage_notes' => get_post_meta($post->ID, 'taipb_usage_notes', true) ?: '',
            ];
        }

        return $patterns;
    }

    /**
     * Collect public post types with their supports and taxonomies.
     */
    private function collect_post_types(): array
    {
        $post_types = [];

        $types = get_post_types(['public' => true], 'objects');

        foreach ($types as $type) {
            // Skip attachment
            if ($type->name === 'attachment') {
                continue;
            }

            $taxonomies = get_object_taxonomies($type->name, 'objects');
            $tax_names = [];
            foreach ($taxonomies as $tax) {
                if ($tax->public) {
                    $tax_names[] = [
                        'name' => $tax->name,
                        'label' => $tax->label,
                    ];
                }
            }

            $post_types[] = [
                'name' => $type->name,
                'label' => $type->label,
                'supports' => array_keys(get_all_post_type_supports($type->name)),
                'taxonomies' => $tax_names,
                'has_archive' => (bool) $type->has_archive,
                'usage_notes' => '',
            ];
        }

        return $post_types;
    }

    /**
     * Collect public taxonomies.
     */
    private function collect_taxonomies(): array
    {
        $taxonomies = [];

        $taxs = get_taxonomies(['public' => true], 'objects');

        foreach ($taxs as $tax) {
            $taxonomies[] = [
                'name' => $tax->name,
                'label' => $tax->label,
                'post_types' => $tax->object_type,
                'hierarchical' => $tax->hierarchical,
            ];
        }

        return $taxonomies;
    }

    /**
     * Derive nesting rules from block definitions.
     */
    private function derive_nesting_rules(array $blocks): array
    {
        $containers = [];
        $leaf_blocks = [];

        foreach ($blocks as $name => $block) {
            if ($block['is_container']) {
                $containers[] = $name;
            } else {
                $leaf_blocks[] = $name;
            }
        }

        // Build parent → children mapping from block "parent" restrictions
        $children_of = [];
        foreach ($blocks as $name => $block) {
            if (!empty($block['parent'])) {
                foreach ($block['parent'] as $parent_name) {
                    $children_of[$parent_name][] = $name;
                }
            }
        }

        return [
            'containers' => $containers,
            'leaf_blocks' => $leaf_blocks,
            'children_of' => $children_of,
        ];
    }
}
