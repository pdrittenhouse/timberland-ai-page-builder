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

    private CuratedContext $curated;

    public function __construct(FieldKeyMap $field_key_map, ?CuratedContext $curated = null)
    {
        $this->field_key_map = $field_key_map;
        // Defaulted rather than required so existing callers keep working; injectable so the
        // unit suite can point it at a fixture directory instead of a live theme.
        $this->curated = $curated ?? new CuratedContext();
    }

    /**
     * Build the full manifest.
     */
    public function build(): array
    {
        // Build field key maps first (reads JSON files)
        $block_field_maps = $this->field_key_map->build();
        $block_field_types = $this->field_key_map->get_all_types();
        $block_field_variants = $this->field_key_map->get_all_variants();

        $settings = Plugin::get_settings();

        $manifest = [
            'version' => TAIPB_VERSION,
            'generated_at' => gmdate('c'),
            'blocks' => $this->collect_blocks($block_field_maps, $block_field_types, $block_field_variants),
            'layouts' => $settings['include_genesis_layouts'] ? $this->collect_genesis_layouts() : [],
            'patterns' => $settings['include_editor_patterns'] ? $this->collect_editor_patterns() : [],
            'post_types' => $this->collect_post_types(),
            'taxonomies' => $this->collect_taxonomies(),
            'nesting_rules' => [],
        ];

        // Derive nesting rules from block data
        $manifest['nesting_rules'] = $this->derive_nesting_rules($manifest['blocks']);

        // A missing curated artifact is a broken install, not a supported configuration: this
        // plugin ships against the framework and its themes, whose build emits them. Recorded
        // in the manifest so it is visible wherever the manifest is -- the /manifest endpoint,
        // the admin screen -- rather than showing up as prompts that are quietly worse.
        $missing = $this->curated->missing();

        if ($missing !== []) {
            $manifest['curation_missing'] = $missing;
        }

        return $manifest;
    }

    /**
     * Collect ACF blocks from WordPress's own block registry.
     *
     * This used to walk `block.json` files across directories it had hardcoded per tier, and it
     * was wrong in two ways at once on any install that is not natural-rose. Measured
     * 2026-11-09 against updater-starter: the framework path it looked under was
     * `vendor/pdrittenhouse/timberland`, but that install's vendor namespace is
     * `vendor/updater/updater-framework`, so **all 41 framework blocks were invisible**; and it
     * included `timberland-extended`, which is present on disk there but NOT ACTIVE, so 7
     * blocks WordPress will never render were in the manifest. 19 blocks found where the
     * registry reports 53.
     *
     * `WP_Block_Type_Registry` has no such problem, because it is not a guess. It returns the
     * blocks that are actually registered — resolved across framework, plugin, parent and child
     * by the tier machinery itself, and filtered by plugin activation, which no build-time
     * artifact and no directory scan can see. Counts match the framework build exactly: 48 on
     * natural-rose, 53 on updater-starter.
     *
     * `WP_Block_Type` carries everything the old scan read out of `block.json` — `title`,
     * `description`, `category`, `keywords`, `parent`, `supports`, context — so nothing is
     * lost. Verified on a live install: all 48 blocks report a title, description, keywords and
     * category, and 42 declare `supports.jsx`.
     *
     * `usage_notes` is the one field the registry cannot supply, and the only thing read from
     * the build. See {@see CuratedContext}.
     *
     * @param  array<string, array<string, string>> $block_field_maps  field name => ACF key
     * @param  array<string, array<string, string>> $block_field_types field name => ACF type
     * @param  array<string, array<string, array<int, array{key: string, type: string, choices: array<int, string>, conditional: array}>>> $block_field_variants
     * @return array<string, array<string, mixed>>
     */
    private function collect_blocks(
        array $block_field_maps,
        array $block_field_types = [],
        array $block_field_variants = []
    ): array
    {
        $blocks = [];

        foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block_type) {
            // ACF blocks only. Core and third-party blocks are registered here too, and the
            // manifest describes what this stack can compose, not everything on the site.
            if (!str_starts_with($name, 'acf/')) {
                continue;
            }

            $supports = is_array($block_type->supports) ? $block_type->supports : [];

            $blocks[$name] = [
                'name' => $name,
                'title' => (string) $block_type->title,
                'description' => (string) $block_type->description,
                'category' => (string) $block_type->category,
                'keywords' => is_array($block_type->keywords) ? $block_type->keywords : [],
                // InnerBlocks support is what makes a block a container, exactly as before --
                // the same key, read from the same data, via the registry rather than the file.
                'is_container' => !empty($supports['jsx']),
                'parent' => is_array($block_type->parent) ? $block_type->parent : null,
                'provides_context' => !empty($block_type->provides_context),
                'uses_context' => !empty($block_type->uses_context),
                'supports' => $this->simplify_supports($supports),
                'field_key_map' => $block_field_maps[$name] ?? [],
                'field_type_map' => $block_field_types[$name] ?? [],
                // Present only for the names ACF defines more than once -- 3 pairs across 48
                // blocks today -- so the common block carries no extra bytes.
                'field_variants' => $block_field_variants[$name] ?? [],
                'field_count' => count($block_field_maps[$name] ?? []),
                'usage_notes' => $this->curated->usage_notes_for($name),
            ];
        }

        ksort($blocks);

        return $blocks;
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
