<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts a JSON block tree into valid WordPress block markup.
 *
 * This is Step 3 of the multi-step pipeline. It handles all mechanical
 * concerns that were previously the LLM's responsibility:
 * - Field key mapping (field names → field_XXXXX keys)
 * - Companion key insertion (_field_name → field_key)
 * - Image field handling (type/file/url triads)
 * - Clone parent field scaffolding
 * - Block comment syntax (container vs leaf, open/close vs self-closing)
 * - Default block attributes (mode, alignText, alignContent)
 *
 * No LLM call — pure PHP transformation.
 */
class MarkupAssembler
{
    private array $manifest;

    public function __construct(array $manifest)
    {
        $this->manifest = $manifest;
    }

    /**
     * Assemble a block tree into valid WordPress block markup.
     *
     * @param array $block_tree The JSON block tree from the Structure step.
     *                          Expected format: {"blocks": [{block node}, ...]}
     * @return string Valid WordPress block markup.
     */
    public function assemble(array $block_tree): string
    {
        $blocks = $block_tree['blocks'] ?? [];
        $lines = [];

        foreach ($blocks as $node) {
            $lines[] = $this->assemble_node($node);
        }

        return implode("\n\n", array_filter($lines));
    }

    /**
     * Recursively assemble a single block node into markup.
     */
    private function assemble_node(array $node): string
    {
        $block_name = $node['block'] ?? '';
        if (empty($block_name)) {
            return '';
        }

        // Core blocks (wp:heading, wp:paragraph, etc.)
        if (str_starts_with($block_name, 'core/')) {
            return $this->assemble_core_block($node);
        }

        // ACF blocks
        return $this->assemble_acf_block($node);
    }

    /**
     * Assemble a WordPress core block.
     */
    private function assemble_core_block(array $node): string
    {
        $block_name = $node['block'];
        $type = str_replace('core/', '', $block_name);

        switch ($type) {
            case 'heading':
                $level = $node['level'] ?? 2;
                $content = $node['content'] ?? '';
                $attrs = $level !== 2 ? ' ' . wp_json_encode(['level' => (int) $level], JSON_UNESCAPED_SLASHES) : '';
                return "<!-- wp:heading{$attrs} -->\n<h{$level}>{$content}</h{$level}>\n<!-- /wp:heading -->";

            case 'paragraph':
                $content = $node['content'] ?? '';
                return "<!-- wp:paragraph -->\n<p>{$content}</p>\n<!-- /wp:paragraph -->";

            case 'list':
                $items = $node['items'] ?? [];
                $li_tags = implode('', array_map(fn($item) => "<li>{$item}</li>", $items));
                return "<!-- wp:list -->\n<ul>{$li_tags}</ul>\n<!-- /wp:list -->";

            case 'button':
                $text = $node['text'] ?? '';
                $url = $node['url'] ?? '#';
                return "<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link\" href=\"{$url}\">{$text}</a></div>\n<!-- /wp:button -->";

            case 'buttons':
                $children = $node['children'] ?? $node['inner_blocks'] ?? [];
                $inner = implode("\n\n", array_map(fn($child) => $this->assemble_node($child), $children));
                return "<!-- wp:buttons -->\n{$inner}\n<!-- /wp:buttons -->";

            case 'image':
                $url = $node['url'] ?? '';
                $alt = $node['alt'] ?? '';
                $attrs = !empty($node['id']) ? ' ' . wp_json_encode(['id' => (int) $node['id']], JSON_UNESCAPED_SLASHES) : '';
                return "<!-- wp:image{$attrs} -->\n<figure class=\"wp-block-image\"><img src=\"{$url}\" alt=\"{$alt}\"/></figure>\n<!-- /wp:image -->";

            default:
                // Generic core block passthrough
                $content = $node['content'] ?? '';
                return "<!-- wp:{$type} -->\n{$content}\n<!-- /wp:{$type} -->";
        }
    }

    /**
     * Assemble an ACF block with full data object, companion keys, and correct syntax.
     */
    private function assemble_acf_block(array $node): string
    {
        $block_name = $node['block'];
        $block_def = $this->manifest['blocks'][$block_name] ?? null;

        $is_container = $block_def['is_container'] ?? false;
        $key_map = $block_def['field_key_map'] ?? [];
        $type_map = $block_def['field_type_map'] ?? [];

        // Build the data object from the node's field values
        $data = $this->build_data_object($node['data'] ?? [], $key_map, $type_map);

        // Build the block JSON attributes
        $block_attrs = [
            'name' => $block_name,
            'data' => (object) $data,
            'mode' => 'preview',
            'alignText' => 'left',
            'alignContent' => 'top',
        ];

        $json = wp_json_encode($block_attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Assemble children (structural nesting) and inner_blocks (content InnerBlocks)
        $children = $node['children'] ?? [];
        $inner_blocks = $node['inner_blocks'] ?? [];
        $has_inner_content = !empty($children) || !empty($inner_blocks);

        if ($is_container || $has_inner_content) {
            // Container: open/close tags with children between
            $inner_parts = [];

            foreach ($children as $child) {
                $assembled = $this->assemble_node($child);
                if ($assembled) {
                    $inner_parts[] = $assembled;
                }
            }

            foreach ($inner_blocks as $ib) {
                $assembled = $this->assemble_node($ib);
                if ($assembled) {
                    $inner_parts[] = $assembled;
                }
            }

            $inner_markup = implode("\n\n", $inner_parts);
            $slug = str_replace('acf/', '', $block_name);

            return "<!-- wp:acf/{$slug} {$json} -->\n{$inner_markup}\n<!-- /wp:acf/{$slug} -->";
        }

        // Leaf: self-closing tag
        $slug = str_replace('acf/', '', $block_name);
        return "<!-- wp:acf/{$slug} {$json} /-->";
    }

    /**
     * Build the complete data object for an ACF block.
     *
     * Takes the LLM's simplified field name → value pairs and produces
     * the full ACF data object with:
     * - Companion keys (_field_name → field_key)
     * - Clone parent fields (empty string placeholders)
     * - Image field normalization (type/file/url triads)
     * - Integer casting for numeric values
     */
    private function build_data_object(array $input_data, array $key_map, array $type_map): array
    {
        $data = [];

        // Step 1: Ensure clone parent fields exist
        foreach ($type_map as $field_name => $field_type) {
            if ($field_type === 'clone') {
                $data[$field_name] = '';
            }
        }

        // Step 2: Copy field values from input, with type coercion
        foreach ($input_data as $field_name => $value) {
            // Skip companion keys the LLM might have included
            if (str_starts_with($field_name, '_')) {
                continue;
            }

            $field_type = $type_map[$field_name] ?? '';

            // Type coercion
            if ($field_type === 'image' && is_numeric($value)) {
                $data[$field_name] = (int) $value;
            } elseif ($field_type === 'number' && is_numeric($value)) {
                $data[$field_name] = (int) $value;
            } else {
                $data[$field_name] = $value;
            }
        }

        // Step 3: Handle image field groups
        $image_groups = $this->detect_image_groups($type_map);
        foreach ($image_groups as $group) {
            $type_field = $group['type_field'];
            $file_field = $group['file_field'];
            $url_field = $group['url_field'];

            $file_value = $data[$file_field] ?? '';
            $url_value = $data[$url_field] ?? '';
            $type_value = $data[$type_field] ?? '';

            // Handle "keep" sentinel — leave image fields empty for pattern preservation
            if ($file_value === 'keep' || $url_value === 'keep' || $type_value === 'keep') {
                $data[$type_field] = 'file';
                $data[$file_field] = '';
                $data[$url_field] = '';
                continue;
            }

            // Resolve file references
            if (is_string($file_value) && !empty($file_value) && !is_numeric($file_value)) {
                if (preg_match('#^https?://#i', $file_value)) {
                    // URL in file field — try resolving to local attachment
                    $resolved_id = $this->resolve_image_reference($file_value);
                    if ($resolved_id) {
                        $data[$file_field] = $resolved_id;
                        $data[$type_field] = 'file';
                    } else {
                        $data[$type_field] = 'url';
                        $data[$url_field] = $file_value;
                        $data[$file_field] = '';
                    }
                } else {
                    // Filename or description — try resolving
                    $resolved_id = $this->resolve_image_reference($file_value);
                    if ($resolved_id) {
                        $data[$file_field] = $resolved_id;
                        $data[$type_field] = 'file';
                    } else {
                        $data[$file_field] = '';
                    }
                }
            } elseif (is_numeric($file_value) && (int) $file_value > 0) {
                $data[$file_field] = (int) $file_value;
                if (empty($type_value)) {
                    $data[$type_field] = 'file';
                }
            }

            // Ensure all three fields exist
            if (!array_key_exists($type_field, $data)) {
                $data[$type_field] = 'file';
            }
            if (!array_key_exists($file_field, $data)) {
                $data[$file_field] = '';
            }
            if (!array_key_exists($url_field, $data)) {
                $data[$url_field] = '';
            }
        }

        // Step 4: Add companion keys for ALL fields present in data
        foreach ($key_map as $field_name => $field_key) {
            if (array_key_exists($field_name, $data)) {
                $data['_' . $field_name] = $field_key;
            }
        }

        return $data;
    }

    /**
     * Detect image field groups from a block's field type map.
     *
     * @return array[] Each entry: ['type_field' => ..., 'file_field' => ..., 'url_field' => ...]
     */
    private function detect_image_groups(array $type_map): array
    {
        $groups = [];
        $seen = [];

        foreach ($type_map as $field_name => $field_type) {
            if ($field_type === 'image') {
                $type_field = $field_name . '_type';
                $url_field = $field_name . '_url';

                if (isset($type_map[$type_field]) || isset($type_map[$url_field])) {
                    if (!isset($seen[$field_name])) {
                        $seen[$field_name] = true;
                        $groups[] = [
                            'type_field' => $type_field,
                            'file_field' => $field_name,
                            'url_field' => $url_field,
                        ];
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * Resolve an image reference (URL, filename, or description) to a WordPress attachment ID.
     *
     * @return int|null Attachment ID if resolved, null otherwise.
     */
    private function resolve_image_reference(string $value): ?int
    {
        $value = trim($value);
        if (empty($value)) {
            return null;
        }

        // Full URL — try local attachment lookup
        if (preg_match('#^https?://#i', $value)) {
            $id = attachment_url_to_postid($value);
            if ($id > 0) {
                return $id;
            }
            $stripped = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $value);
            if ($stripped !== $value) {
                $id = attachment_url_to_postid($stripped);
                if ($id > 0) {
                    return $id;
                }
            }
            return null;
        }

        // Filename with extension — query _wp_attached_file meta
        if (preg_match('/\.\w{2,5}$/', $value)) {
            $filename = sanitize_file_name(basename($value));
            global $wpdb;
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file'
                 AND meta_value LIKE %s
                 ORDER BY post_id DESC LIMIT 1",
                '%' . $wpdb->esc_like($filename)
            ));
            if ($id) {
                return (int) $id;
            }
        }

        // Plain text — search attachment post titles
        $posts = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            's'           => $value,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        if (!empty($posts)) {
            return (int) $posts[0];
        }

        return null;
    }
}
