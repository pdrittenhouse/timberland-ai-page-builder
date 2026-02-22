<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a simplified system prompt for the Structure step (Step 2).
 *
 * Unlike PromptBuilder which asks the LLM to produce raw block markup with
 * field keys and companion entries, this builder asks only for a JSON block
 * tree using human-readable field names. PHP (MarkupAssembler) handles all
 * mechanical serialization afterwards.
 */
class StructurePromptBuilder
{
    /** Blocks that are always included (layout scaffolding). */
    private const CORE_BLOCKS = [
        'acf/section',
        'acf/row',
        'acf/column',
    ];

    /** Blocks included when no specific match is found. */
    private const COMMON_BLOCKS = [
        'acf/card',
        'acf/card-grid',
        'acf/button',
        'acf/jumbotron',
        'acf/hero-unit',
        'acf/feature',
        'acf/promo',
        'acf/text-block',
    ];

    private array $manifest;

    public function __construct(array $manifest)
    {
        $this->manifest = $manifest;
    }

    /**
     * Build the system prompt for structure generation.
     *
     * @param array $decomposition The confirmed layout plan from Step 1.
     * @param array $use_patterns  Pattern/layout IDs selected by decomposition.
     */
    public function build(string $user_prompt, array $decomposition, array $use_patterns = []): string
    {
        $relevant_blocks = $this->filter_blocks_for_prompt($user_prompt, $decomposition);

        $sections = [];
        $sections[] = $this->build_rules_section();
        $sections[] = $this->build_block_catalog($relevant_blocks);
        $sections[] = $this->build_nesting_section();
        $sections[] = $this->build_layout_plan_section($decomposition);

        if (!empty($use_patterns)) {
            $sections[] = $this->build_pattern_reference_section($use_patterns);
        }

        return implode("\n\n---\n\n", array_filter($sections));
    }

    /**
     * Core rules for the structure LLM call.
     */
    private function build_rules_section(): string
    {
        return <<<'RULES'
# RULES

You are a layout structure assistant. Given a confirmed layout plan, output a JSON block tree that describes the page structure.

## Output Format
- Output ONLY valid JSON. No explanation, no markdown fences, no commentary.
- The root object has a single `blocks` array containing the top-level blocks.

## Block Tree Format
Each block node is an object with:
- `block`: the block name (e.g., "acf/section", "acf/hero-unit", "core/heading")
- `data`: an object of field name → value pairs (ACF blocks only, omit for core blocks)
- `children`: array of child block nodes (for container blocks that use InnerBlocks for layout structure like section, row, column)
- `inner_blocks`: array of core block nodes (for container blocks that accept content InnerBlocks like headings/paragraphs inside hero-unit)

## Core Block Format
For WordPress core blocks, use:
- `{"block": "core/heading", "level": 2, "content": "The heading text"}`
- `{"block": "core/paragraph", "content": "The paragraph text"}`
- `{"block": "core/list", "items": ["Item 1", "Item 2"]}`
- `{"block": "core/button", "text": "Click me", "url": "#"}`

## ACF Data Fields
- Use field NAMES (not field keys). The system handles key mapping automatically.
- For boolean/toggle fields, use "1" (true) or "0" (false) as strings.
- For select/radio fields, use the choice value (not label).
- For color fields, use: `{"name":"primary","slug":"primary","color":"var(--primary)"}`.
- Leave styling fields (padding, margin, bg_color) empty unless the user requests specific styling.
- For image fields, use the value "keep" to preserve existing pattern images, or provide a filename/URL string.

## Container vs Leaf Blocks
- Container blocks (marked "container" below) use `children` for structural nesting.
- Some containers also accept `inner_blocks` for content blocks (headings, paragraphs) rendered inside them.
- Leaf blocks (marked "leaf" below) have no children — all content goes in `data`.

## Layout Hierarchy
Every page layout MUST be wrapped in: section → row → column → content blocks.
- `acf/section` → contains `acf/row`(s) in `children`
- `acf/row` → contains `acf/column`(s) in `children`
- `acf/column` → contains content blocks in `children`
- Columns use Bootstrap 12-column grid. Set `col_width_0_width` to control width (e.g., "6" = 50%, "4" = 33%, "12" = full).
- Always set `col_width_0_breakpoint` to "lg" and `col_width` to 1.
RULES;
    }

    /**
     * Build a compact block catalog with field names and types (no field keys).
     */
    private function build_block_catalog(array $blocks): string
    {
        $output = "# AVAILABLE BLOCKS\n\n";

        foreach ($blocks as $name => $block) {
            $type_label = ($block['is_container'] ?? false) ? 'container' : 'leaf';
            $output .= "## {$block['title']} (`{$name}`) — {$type_label}\n";

            if (!empty($block['description'])) {
                $output .= "{$block['description']}\n";
            }

            if (!empty($block['parent'])) {
                $output .= "Parent restriction: " . implode(', ', $block['parent']) . "\n";
            }

            if (!empty($block['usage_notes'])) {
                $output .= "Notes: {$block['usage_notes']}\n";
            }

            // Field catalog: name → type (no field keys)
            $type_map = $block['field_type_map'] ?? [];
            $key_map = $block['field_key_map'] ?? [];

            if (!empty($key_map)) {
                $output .= "\nFields:\n";
                foreach ($key_map as $field_name => $field_key) {
                    $field_type = $type_map[$field_name] ?? 'text';

                    // Skip clone-type fields (internal ACF scaffolding)
                    if ($field_type === 'clone') {
                        continue;
                    }

                    $annotation = $this->get_field_annotation($field_name, $field_type, $type_map);
                    $output .= "  - `{$field_name}` ({$field_type}){$annotation}\n";
                }
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * Build nesting rules section.
     */
    private function build_nesting_section(): string
    {
        $rules = $this->manifest['nesting_rules'] ?? [];
        if (empty($rules)) {
            return '';
        }

        $output = "# NESTING RULES\n\n";
        $output .= "Container blocks (accept children): " . implode(', ', $rules['containers'] ?? []) . "\n\n";
        $output .= "Leaf blocks (no children): " . implode(', ', $rules['leaf_blocks'] ?? []) . "\n\n";

        if (!empty($rules['children_of'])) {
            $output .= "Parent → allowed children:\n";
            foreach ($rules['children_of'] as $parent => $children) {
                $output .= "- `{$parent}` → " . implode(', ', $children) . "\n";
            }
        }

        return $output;
    }

    /**
     * Build the confirmed layout plan section from decomposition.
     */
    private function build_layout_plan_section(array $decomposition): string
    {
        if (empty($decomposition['sections'])) {
            return '';
        }

        $output = "# CONFIRMED LAYOUT PLAN\n\n";

        if (!empty($decomposition['overall_intent'])) {
            $output .= "Overall intent: {$decomposition['overall_intent']}\n\n";
        }

        foreach ($decomposition['sections'] as $i => $section) {
            $num = $i + 1;
            $output .= "## Section {$num}: {$section['intent']}\n";

            if (!empty($section['pattern_hint'])) {
                $output .= "Pattern reference: {$section['pattern_hint']}\n";
            }

            if (!empty($section['content'])) {
                foreach ($section['content'] as $key => $value) {
                    if (!empty($value)) {
                        $output .= "  {$key}: {$value}\n";
                    }
                }
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * Build pattern reference section — structural templates stripped of field keys.
     */
    private function build_pattern_reference_section(array $pattern_ids): string
    {
        $output = "# PATTERN REFERENCES\n\n";
        $output .= "Use these patterns as structural templates. Adapt content to match the layout plan.\n\n";

        $pattern_num = 0;
        foreach ($pattern_ids as $pattern_id) {
            $resolved = $this->resolve_pattern($pattern_id);
            if (!$resolved) {
                continue;
            }

            $pattern_num++;
            $output .= "## Pattern {$pattern_num}: {$resolved['title']}\n";

            // Convert pattern markup to a simplified structural description
            $structure = $this->describe_pattern_structure($resolved['content']);
            if ($structure) {
                $output .= $structure . "\n";
            }

            $output .= "\n";
        }

        return $pattern_num > 0 ? $output : '';
    }

    /**
     * Describe a pattern's block structure in a simplified, human-readable form.
     * Instead of raw markup, outputs a tree of block names + key data values.
     */
    private function describe_pattern_structure(string $markup): string
    {
        $lines = [];

        // Extract all ACF blocks with their JSON data
        preg_match_all(
            '/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) (?:\/)?-->/s',
            $markup,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $block_name = 'acf/' . $match[1];
            $block_json = json_decode($match[2], true);
            $data = $block_json['data'] ?? [];

            // Filter to meaningful data fields (skip companions, empty values, internal fields)
            $meaningful = [];
            foreach ($data as $key => $value) {
                if (str_starts_with($key, '_')) {
                    continue;
                }
                if ($value === '' || $value === null) {
                    continue;
                }
                if (in_array($key, ['mode', 'alignText', 'alignContent'], true)) {
                    continue;
                }
                $meaningful[$key] = $value;
            }

            $line = "- `{$block_name}`";
            if (!empty($meaningful)) {
                $pairs = [];
                foreach ($meaningful as $k => $v) {
                    if (is_array($v) || is_object($v)) {
                        continue;
                    }
                    $display = is_string($v) && strlen($v) > 60 ? substr($v, 0, 57) . '...' : $v;
                    $pairs[] = "{$k}: " . json_encode($display, JSON_UNESCAPED_SLASHES);
                }
                if (!empty($pairs)) {
                    $line .= ' — ' . implode(', ', $pairs);
                }
            }

            $lines[] = $line;
        }

        // Also extract core blocks (headings, paragraphs)
        preg_match_all(
            '/<!-- wp:(heading|paragraph|button|list).*?-->(.*?)<!-- \/wp:\1 -->/s',
            $markup,
            $core_matches,
            PREG_SET_ORDER
        );

        foreach ($core_matches as $match) {
            $type = $match[1];
            $content = strip_tags(trim($match[2]));
            if (!empty($content)) {
                $display = strlen($content) > 60 ? substr($content, 0, 57) . '...' : $content;
                $lines[] = "- `core/{$type}`: \"{$display}\"";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve a pattern/layout ID to its title and content.
     *
     * @return array{title: string, content: string}|null
     */
    private function resolve_pattern(string $pattern_id): ?array
    {
        if (str_starts_with($pattern_id, 'pattern_')) {
            $id = (int) substr($pattern_id, 8);
            foreach ($this->manifest['patterns'] ?? [] as $pattern) {
                if (($pattern['id'] ?? 0) === $id) {
                    return [
                        'title' => $pattern['title'] ?? '',
                        'content' => $pattern['content'] ?? '',
                    ];
                }
            }
        } elseif (str_starts_with($pattern_id, 'layout_')) {
            $index = (int) substr($pattern_id, 7);
            $layouts = $this->manifest['layouts'] ?? [];
            if (isset($layouts[$index])) {
                return [
                    'title' => $layouts[$index]['name'] ?? '',
                    'content' => $layouts[$index]['content'] ?? '',
                ];
            }
        }
        return null;
    }

    /**
     * Get a human-readable annotation for a field.
     */
    private function get_field_annotation(string $field_name, string $field_type, array $type_map): string
    {
        if ($field_type === 'image') {
            return ' — attachment ID or filename';
        }
        if ($field_type === 'select' && str_ends_with($field_name, '_image_type')) {
            return ' — "file" or "url"';
        }
        if ($field_type === 'url' && str_ends_with($field_name, '_image_url')) {
            return ' — full URL string';
        }
        if ($field_type === 'wysiwyg') {
            return ' — HTML content';
        }
        if ($field_type === 'true_false') {
            return ' — "1" or "0"';
        }

        return '';
    }

    /**
     * Filter manifest blocks to those relevant to the prompt and decomposition.
     */
    private function filter_blocks_for_prompt(string $user_prompt, array $decomposition): array
    {
        $all_blocks = $this->manifest['blocks'] ?? [];
        $prompt_lower = strtolower($user_prompt);
        $prompt_words = preg_split('/[\s,.\-\/]+/', $prompt_lower, -1, PREG_SPLIT_NO_EMPTY);

        $selected = [];

        // Always include core layout blocks
        foreach (self::CORE_BLOCKS as $name) {
            if (isset($all_blocks[$name])) {
                $selected[$name] = $all_blocks[$name];
            }
        }

        // Include blocks referenced in decomposition pattern hints
        foreach ($decomposition['sections'] ?? [] as $section) {
            $hint = strtolower($section['pattern_hint'] ?? '');
            if (empty($hint)) {
                continue;
            }
            foreach ($all_blocks as $name => $block) {
                if (isset($selected[$name])) {
                    continue;
                }
                $block_title = strtolower($block['title'] ?? '');
                $block_short = str_replace('acf/', '', $name);
                if (str_contains($hint, $block_short) || str_contains($hint, $block_title)) {
                    $selected[$name] = $block;
                }
            }
        }

        // Score remaining blocks by relevance to user prompt
        foreach ($all_blocks as $name => $block) {
            if (isset($selected[$name])) {
                continue;
            }

            $score = $this->score_block($block, $prompt_words, $prompt_lower);
            if ($score > 0) {
                $selected[$name] = $block;
            }
        }

        // If we only matched core blocks, add common blocks as fallback
        if (count($selected) <= count(self::CORE_BLOCKS)) {
            foreach (self::COMMON_BLOCKS as $name) {
                if (isset($all_blocks[$name]) && !isset($selected[$name])) {
                    $selected[$name] = $all_blocks[$name];
                }
            }
        }

        return $selected;
    }

    /**
     * Score a block's relevance to the user prompt.
     */
    private function score_block(array $block, array $prompt_words, string $prompt_lower): int
    {
        $score = 0;

        $short_name = str_replace('acf/', '', $block['name'] ?? '');
        $name_parts = preg_split('/[\-_]+/', $short_name);

        foreach ($name_parts as $part) {
            if (in_array($part, $prompt_words, true)) {
                $score += 3;
            }
        }

        $title_words = preg_split('/[\s\-_]+/', strtolower($block['title'] ?? ''));
        foreach ($title_words as $word) {
            if (in_array($word, $prompt_words, true)) {
                $score += 2;
            }
        }

        foreach ($block['keywords'] ?? [] as $keyword) {
            if (str_contains($prompt_lower, strtolower($keyword))) {
                $score += 2;
            }
        }

        return $score;
    }
}
