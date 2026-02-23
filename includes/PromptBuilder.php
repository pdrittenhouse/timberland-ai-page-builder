<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assembles the system prompt from the manifest for Claude API calls.
 * Manages token budget by filtering the manifest to relevant blocks.
 */
class PromptBuilder
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
     * Build the full system prompt for a given user prompt and context.
     *
     * @param array $use_patterns Pattern/layout IDs to use as base (e.g., ["pattern_123", "layout_5"]).
     */
    public function build(string $user_prompt, string $post_type = 'page', array $use_patterns = []): string
    {
        $relevant_blocks = $this->filter_blocks_for_prompt($user_prompt);

        $sections = [];

        $sections[] = $this->build_rules_section();
        $sections[] = $this->build_blocks_section($relevant_blocks);
        $sections[] = $this->build_nesting_section();
        $sections[] = $this->build_post_type_section($post_type);

        // If specific patterns were selected, include them prominently
        if (!empty($use_patterns)) {
            $sections[] = $this->build_selected_patterns_section($use_patterns);
        } else {
            $sections[] = $this->build_layouts_section($user_prompt);
            $sections[] = $this->build_patterns_section($user_prompt);
        }

        $custom = $this->build_custom_prompt_section();
        if ($custom) {
            $sections[] = $custom;
        }

        return implode("\n\n---\n\n", array_filter($sections));
    }

    /**
     * Build custom system prompt section from site settings.
     */
    private function build_custom_prompt_section(): string
    {
        $custom = trim(Plugin::get_settings()['custom_system_prompt'] ?? '');
        if ($custom === '') {
            return '';
        }

        return "# SITE-SPECIFIC INSTRUCTIONS\n\n{$custom}";
    }

    /**
     * Core rules that Claude must follow.
     */
    private function build_rules_section(): string
    {
        return <<<'RULES'
# RULES

You generate WordPress Gutenberg block markup for a site using ACF (Advanced Custom Fields) blocks.

## Output Format
- Output ONLY raw block markup (HTML comments). No explanation, no markdown fences, no commentary.
- Every page layout MUST be wrapped in section > row > column structure.

## ACF Block Format
- Container blocks (jsx: true) use open/close tags:
  `<!-- wp:acf/block-name {"name":"acf/block-name","data":{...},"mode":"preview","alignText":"left","alignContent":"top"} -->`
  `<!-- /wp:acf/block-name -->`

- Leaf blocks (jsx: false) use self-closing tags:
  `<!-- wp:acf/block-name {"name":"acf/block-name","data":{...},"mode":"preview","alignText":"left","alignContent":"top"} /-->`

- IMPORTANT: Always include `"alignText":"left"` and `"alignContent":"top"` in the block JSON alongside `"mode":"preview"`. These are required for Gutenberg block validation.

## CRITICAL: ACF Data Object
Every ACF block has a `data` object in its JSON. For EVERY field value you set, you MUST include a companion entry with an underscore prefix mapping to the field key:

```
"data": {
  "field_name": "the value",
  "_field_name": "field_XXXXX"
}
```

If you omit the `_field_name` → `field_key` companion, ACF will NOT read the value. This is the most important rule.

## CRITICAL: Field Key Source of Truth
The AVAILABLE BLOCKS section below contains the authoritative field key map for each block. You MUST ONLY use field keys from those maps. NEVER copy field keys from layout/pattern examples — those examples may contain outdated keys. Always look up the correct key from the block's field key map.

## Using Patterns and Layouts
When the user references a known pattern or layout by name (e.g., "telco hero", "home cards"), use the matching REFERENCE PATTERN or REFERENCE LAYOUT as a starting point. Adapt the content to match the user's request (change text, titles, etc.) but preserve the block structure. Always replace any field keys in the example with the correct keys from the AVAILABLE BLOCKS field key maps.

## Using Multiple Patterns
When multiple BASE PATTERNs are provided, combine them in order to build a complete page. Each pattern is a distinct section — output them sequentially. Do not merge patterns into one block — keep each pattern's block structure intact as a separate section of the page.

## Container vs Leaf Blocks
- Container blocks (section, row, column, hero-unit, card-grid, feature) have `jsx: true` — they accept InnerBlocks (child blocks between open/close tags).
- Leaf blocks (card, button, promo, text-block, jumbotron) have `jsx: false` — they are self-closing and store all content in the `data` object.

## CRITICAL: InnerBlocks Content vs Data Fields
Container blocks can have BOTH data fields AND InnerBlocks (child blocks between open/close tags). When adapting a pattern:
- **If a pattern has content in InnerBlocks** (e.g., `wp:heading`, `wp:paragraph` between the open/close tags), modify THOSE blocks — do NOT duplicate the content by also setting data fields like `title` or `text`.
- **If a pattern has content in data fields** (e.g., `"title":"Some Title"` in the block JSON), modify those data fields.
- Follow the CONTENT LOCATION NOTES in the BASE PATTERN section (if present) for specific guidance on each block.
- NEVER set both the data field AND create an InnerBlocks equivalent for the same content.

## Layout Hierarchy
- `acf/section` → contains `acf/row`(s)
- `acf/row` → contains `acf/column`(s)
- `acf/column` → contains content blocks (leaf or nested containers)
- Columns use Bootstrap 12-column grid. Set `col_width_0_width` to control width (e.g., "6" = 50%, "4" = 33%, "12" = full).
- Always set `col_width_0_breakpoint` to "lg" and `col_width` to 1 (the repeater count).

## Field Values
- For boolean/toggle fields, use "1" (true) or "0" (false) as strings.
- For select/radio fields, use the choice value (not label).
- For color fields using theme palette, use the color object format: `{"name":"primary","slug":"primary","color":"var(--primary)","text":"has-text-color has-primary-color","background":"has-background has-primary-background-color"}`.
- Leave styling fields (padding, margin, bg_color) empty unless the user requests specific styling.
- Always include `"mode":"preview","alignText":"left","alignContent":"top"` in the block JSON.

## CRITICAL: Image Fields (Module: Image)
Many blocks use a cloned "Module: Image" with THREE related fields. Image fields in the field key map are annotated with `[IMAGE_TYPE]`, `[IMAGE_FILE]`, or `[IMAGE_URL]`. These MUST be used together correctly:

**For file-based images (default — use when keeping a pattern's existing image):**
```
"image_image_type": "file",     "_image_image_type": "field_xxx",
"image_image": 72,              "_image_image": "field_yyy"
```
The `image_image` value MUST be an integer (WordPress attachment ID). ACF hydrates this into the full image array at render time.

**For URL-based images (use ONLY when the user provides a full URL):**
```
"image_image_type": "url",      "_image_image_type": "field_xxx",
"image_image_url": "https://example.com/image.jpg", "_image_image_url": "field_zzz"
```

**Rules:**
- When adapting a pattern/layout, ALWAYS preserve the original image field values (image_type, image, image_url) EXACTLY as they appear unless the user explicitly asks to change the image.
- If the user provides a full URL, set `image_type` to `"url"` and put the URL in the `[IMAGE_URL]` field. The system will automatically check if it's a local image and convert to file mode if possible.
- If the user references an image by filename (e.g., "hero-image.jpg") or description (e.g., "company logo"), put the filename or description string in the `[IMAGE_FILE]` field and set `image_type` to `"file"`. The system will automatically resolve it to the correct attachment ID.
- If no image is referenced and you're not using a pattern, leave image fields empty.
- The prefix varies by block (e.g., `image_image`, `bg_image_bg_image`, `image_one_image`). Always check the field key map annotations to identify the correct field names.
RULES;
    }

    /**
     * Build the available blocks section with field key maps.
     */
    private function build_blocks_section(array $blocks): string
    {
        $output = "# AVAILABLE BLOCKS\n\n";

        foreach ($blocks as $name => $block) {
            $output .= "## {$block['title']} (`{$name}`)\n";

            if (!empty($block['description'])) {
                $output .= "{$block['description']}\n";
            }

            $output .= "- Type: " . ($block['is_container'] ? 'container (jsx)' : 'leaf') . "\n";

            if (!empty($block['keywords'])) {
                $output .= "- Keywords: " . implode(', ', $block['keywords']) . "\n";
            }

            if (!empty($block['parent'])) {
                $output .= "- Parent restriction: " . implode(', ', $block['parent']) . "\n";
            }

            if (!empty($block['usage_notes'])) {
                $output .= "- Usage notes: {$block['usage_notes']}\n";
            }

            // Field key map with image annotations
            // Skip clone-type fields — they are internal ACF scaffolding (always empty
            // strings) used to reconstruct nested field structures. The post-processor
            // handles them automatically; showing them to Claude would be confusing.
            if (!empty($block['field_key_map'])) {
                $type_map = $block['field_type_map'] ?? [];
                $output .= "\nField key map:\n";
                $output .= "```\n";
                foreach ($block['field_key_map'] as $field_name => $field_key) {
                    if (($type_map[$field_name] ?? '') === 'clone') {
                        continue;
                    }
                    $annotation = $this->get_image_annotation($field_name, $type_map);
                    $output .= "  \"{$field_name}\" => \"{$field_key}\"{$annotation}\n";
                }
                $output .= "```\n";
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

        $output .= "Container blocks (accept InnerBlocks): " . implode(', ', $rules['containers'] ?? []) . "\n\n";
        $output .= "Leaf blocks (self-closing, no children): " . implode(', ', $rules['leaf_blocks'] ?? []) . "\n\n";

        if (!empty($rules['children_of'])) {
            $output .= "Parent → allowed children:\n";
            foreach ($rules['children_of'] as $parent => $children) {
                $output .= "- `{$parent}` → " . implode(', ', $children) . "\n";
            }
        }

        return $output;
    }

    /**
     * Build post type context section.
     */
    private function build_post_type_section(string $post_type): string
    {
        $post_types = $this->manifest['post_types'] ?? [];

        $current = null;
        foreach ($post_types as $pt) {
            if ($pt['name'] === $post_type) {
                $current = $pt;
                break;
            }
        }

        if (!$current) {
            return '';
        }

        $output = "# POST TYPE CONTEXT\n\n";
        $output .= "Current post type: **{$current['label']}** (`{$current['name']}`)\n";
        $output .= "Supports: " . implode(', ', $current['supports'] ?? []) . "\n";

        if (!empty($current['taxonomies'])) {
            $tax_names = array_map(fn($t) => $t['label'], $current['taxonomies']);
            $output .= "Taxonomies: " . implode(', ', $tax_names) . "\n";
        }

        if (!empty($current['usage_notes'])) {
            $output .= "Notes: {$current['usage_notes']}\n";
        }

        return $output;
    }

    /**
     * Build section for multiple user-selected patterns/layouts.
     * Delegates to the single-pattern method when only one is selected.
     */
    private function build_selected_patterns_section(array $pattern_ids): string
    {
        if (count($pattern_ids) === 1) {
            return $this->build_selected_pattern_section($pattern_ids[0]);
        }

        $output = "# BASE PATTERNS\n\n";
        $output .= "The user selected " . count($pattern_ids) . " patterns as starting points. ";
        $output .= "You MUST use ALL of these patterns, combining them in the order listed. ";
        $output .= "Each pattern contributes a distinct section of the page. ";
        $output .= "Adapt the content (titles, text, etc.) according to the user's prompt, but preserve each pattern's block structure.\n\n";
        $output .= "IMPORTANT: All field keys in these patterns have been replaced with `USE_FIELD_KEY_MAP`. You MUST look up the correct field keys from the AVAILABLE BLOCKS field key maps above.\n\n";
        $output .= "IMPORTANT: Preserve ALL image field values (image_type, image attachment IDs, image_url) EXACTLY as they appear unless the user explicitly asks to change an image.\n\n";

        $pattern_num = 0;
        foreach ($pattern_ids as $pattern_id) {
            $pattern_num++;

            $content = null;
            $title = '';

            if (str_starts_with($pattern_id, 'pattern_')) {
                $id = (int) substr($pattern_id, 8);
                foreach ($this->manifest['patterns'] ?? [] as $pattern) {
                    if (($pattern['id'] ?? 0) === $id) {
                        $content = $pattern['content'] ?? '';
                        $title = $pattern['title'] ?? '';
                        break;
                    }
                }
            } elseif (str_starts_with($pattern_id, 'layout_')) {
                $index = (int) substr($pattern_id, 7);
                $layouts = $this->manifest['layouts'] ?? [];
                if (isset($layouts[$index])) {
                    $content = $layouts[$index]['content'] ?? '';
                    $title = $layouts[$index]['name'] ?? '';
                }
            }

            if (!$content) {
                continue;
            }

            $stripped = $this->strip_field_keys_from_markup($content);
            $innerblocks_notes = $this->analyze_pattern_innerblocks($content);

            $output .= "## BASE PATTERN {$pattern_num}: {$title}\n\n";

            if (!empty($innerblocks_notes)) {
                $output .= "### CONTENT LOCATION NOTES\n";
                foreach ($innerblocks_notes as $note) {
                    $output .= "- **{$note['block']}**: {$note['instruction']}\n";
                }
                $output .= "\n";
            }

            $output .= "```\n{$stripped}\n```\n\n";
        }

        return $output;
    }

    /**
     * Build section for a user-selected pattern/layout to use as the base.
     */
    private function build_selected_pattern_section(string $pattern_id): string
    {
        $content = null;
        $title = '';

        if (str_starts_with($pattern_id, 'pattern_')) {
            $id = (int) substr($pattern_id, 8);
            foreach ($this->manifest['patterns'] ?? [] as $pattern) {
                if (($pattern['id'] ?? 0) === $id) {
                    $content = $pattern['content'] ?? '';
                    $title = $pattern['title'] ?? '';
                    break;
                }
            }
        } elseif (str_starts_with($pattern_id, 'layout_')) {
            $index = (int) substr($pattern_id, 7);
            $layouts = $this->manifest['layouts'] ?? [];
            if (isset($layouts[$index])) {
                $content = $layouts[$index]['content'] ?? '';
                $title = $layouts[$index]['name'] ?? '';
            }
        }

        if (!$content) {
            return '';
        }

        $stripped = $this->strip_field_keys_from_markup($content);
        $innerblocks_notes = $this->analyze_pattern_innerblocks($content);

        $output = "# BASE PATTERN: {$title}\n\n";
        $output .= "The user selected this pattern as the starting point. You MUST use this pattern's structure as your base. Adapt the content (titles, text, etc.) according to the user's prompt, but preserve the block structure.\n\n";
        $output .= "IMPORTANT: All field keys in this pattern have been replaced with `USE_FIELD_KEY_MAP`. You MUST look up the correct field keys from the AVAILABLE BLOCKS field key maps above.\n\n";
        $output .= "IMPORTANT: Preserve ALL image field values (image_type, image attachment IDs, image_url) EXACTLY as they appear in this pattern unless the user explicitly asks to change an image. Integer values for image fields are attachment IDs — do NOT change them.\n\n";

        if (!empty($innerblocks_notes)) {
            $output .= "## CONTENT LOCATION NOTES\n";
            $output .= "The following blocks in this pattern have specific content locations. Follow these instructions carefully:\n\n";
            foreach ($innerblocks_notes as $note) {
                $output .= "- **{$note['block']}**: {$note['instruction']}\n";
            }
            $output .= "\n";
        }

        $output .= "```\n{$stripped}\n```\n";

        return $output;
    }

    /**
     * Analyze a pattern's markup to detect InnerBlocks usage and generate
     * specific instructions about where content lives.
     *
     * @return array[] Each entry: ['block' => block_name, 'instruction' => string]
     */
    private function analyze_pattern_innerblocks(string $markup): array
    {
        $notes = [];

        // Find all ACF container blocks (open + close tag pairs)
        preg_match_all(
            '/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) -->/s',
            $markup,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $block_slug = $match[1][0];
            $block_name = 'acf/' . $block_slug;
            $json_str = $match[2][0];
            $open_end = $match[0][1] + strlen($match[0][0]);

            // Find the closing tag
            $close_tag = "<!-- /wp:acf/{$block_slug} -->";
            $close_pos = strpos($markup, $close_tag, $open_end);
            if ($close_pos === false) {
                continue;
            }

            // Get the InnerBlocks content between open and close
            $inner_content = substr($markup, $open_end, $close_pos - $open_end);

            // Parse the block JSON for hero_type
            $block_json = json_decode($json_str, true);
            $data = $block_json['data'] ?? [];

            // Check for hero-unit specific types
            if ($block_slug === 'hero-unit') {
                $hero_type = $data['hero_type'] ?? '';
                $has_inner_heading = (bool) preg_match('/<!-- wp:heading/', $inner_content);
                $has_inner_paragraph = (bool) preg_match('/<!-- wp:paragraph/', $inner_content);
                $has_inner_content = $has_inner_heading || $has_inner_paragraph;

                if ($hero_type === 'section') {
                    $notes[] = [
                        'block' => $block_name,
                        'instruction' => 'This hero uses type "section" — ALL visible text content (titles, descriptions) is rendered from InnerBlocks ONLY. Data fields like `title` and `text` are NOT displayed. To change the title or description, modify the `wp:heading` and `wp:paragraph` blocks inside this hero, NOT the data fields.',
                    ];
                } elseif ($hero_type === 'feature' || $hero_type === 'jumbotron') {
                    if ($has_inner_content) {
                        $notes[] = [
                            'block' => $block_name,
                            'instruction' => "This hero uses type \"{$hero_type}\" with InnerBlocks content. The data fields `title`, `subtitle`, and `text` ARE rendered by the template. The InnerBlocks content is placed in the `innerblocks_location` area. Update the data fields for the main title/description. Only modify InnerBlocks if the user specifically asks about the content in that area.",
                        ];
                    } else {
                        $notes[] = [
                            'block' => $block_name,
                            'instruction' => "This hero uses type \"{$hero_type}\". The title, subtitle, and description are rendered from data fields (`title`, `subtitle`, `text`). Modify those data fields to change the content.",
                        ];
                    }
                }
            } else {
                // Generic container block analysis
                $has_inner_heading = (bool) preg_match('/<!-- wp:heading/', $inner_content);
                $has_inner_paragraph = (bool) preg_match('/<!-- wp:paragraph/', $inner_content);

                if ($has_inner_heading || $has_inner_paragraph) {
                    $content_types = [];
                    if ($has_inner_heading) {
                        $content_types[] = 'headings';
                    }
                    if ($has_inner_paragraph) {
                        $content_types[] = 'paragraphs';
                    }
                    $types_str = implode(' and ', $content_types);

                    $notes[] = [
                        'block' => $block_name,
                        'instruction' => "This block has {$types_str} in its InnerBlocks. To change text content, modify those inner blocks rather than duplicating into data fields.",
                    ];
                }
            }
        }

        return $notes;
    }

    /**
     * Include relevant Genesis layout examples as few-shot references.
     */
    private function build_layouts_section(string $user_prompt): string
    {
        $layouts = $this->manifest['layouts'] ?? [];
        if (empty($layouts)) {
            return '';
        }

        // Score layouts by keyword relevance to user prompt
        $scored = $this->score_by_relevance($layouts, $user_prompt, 'keywords');

        // Take top 2 most relevant layouts
        $top = array_slice($scored, 0, 2);
        if (empty($top)) {
            return '';
        }

        $output = "# REFERENCE LAYOUTS\n\n";
        $output .= "These are existing layouts on the site. Use them as structural templates — adapt the content to match the user's request. WARNING: The field keys in these examples may be outdated. ALWAYS replace them with the correct keys from the AVAILABLE BLOCKS field key maps above.\n\n";
        $output .= "When the user references a layout by name, use the matching layout as the base and modify as requested.\n\n";

        foreach ($top as $layout) {
            $output .= "## {$layout['name']} ({$layout['type']})\n";
            if (!empty($layout['collection'])) {
                $output .= "Collection: {$layout['collection']}\n";
            }
            if (!empty($layout['usage_notes'])) {
                $output .= "Notes: {$layout['usage_notes']}\n";
            }
            $output .= "```\n" . $this->strip_field_keys_from_markup($layout['content']) . "\n```\n\n";
        }

        return $output;
    }

    /**
     * Include relevant editor patterns as references.
     */
    private function build_patterns_section(string $user_prompt): string
    {
        $patterns = $this->manifest['patterns'] ?? [];
        if (empty($patterns)) {
            return '';
        }

        // Score patterns by title/category relevance
        $scored = $this->score_patterns_by_relevance($patterns, $user_prompt);

        $top = array_slice($scored, 0, 2);
        if (empty($top)) {
            return '';
        }

        $output = "# REFERENCE PATTERNS\n\n";
        $output .= "These are saved editor patterns. Use them as structural templates. WARNING: Replace all field keys with the correct keys from the AVAILABLE BLOCKS field key maps above.\n\n";
        $output .= "When the user references a pattern by name, use the matching pattern as the base and modify as requested.\n\n";

        foreach ($top as $pattern) {
            $output .= "## {$pattern['title']}\n";
            if (!empty($pattern['categories'])) {
                $output .= "Categories: " . implode(', ', $pattern['categories']) . "\n";
            }
            if (!empty($pattern['usage_notes'])) {
                $output .= "Notes: {$pattern['usage_notes']}\n";
            }
            $output .= "```\n" . $this->strip_field_keys_from_markup($pattern['content']) . "\n```\n\n";
        }

        return $output;
    }

    /**
     * Filter manifest blocks to those relevant to the user's prompt.
     */
    private function filter_blocks_for_prompt(string $user_prompt): array
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

        // Score remaining blocks by relevance
        foreach ($all_blocks as $name => $block) {
            if (isset($selected[$name])) {
                continue;
            }

            $score = $this->score_block($block, $prompt_words, $prompt_lower);
            if ($score > 0) {
                $block['_relevance_score'] = $score;
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

        // Check block name (without acf/ prefix)
        $short_name = str_replace('acf/', '', $block['name'] ?? '');
        $name_parts = preg_split('/[\-_]+/', $short_name);

        foreach ($name_parts as $part) {
            if (in_array($part, $prompt_words, true)) {
                $score += 3;
            }
        }

        // Check title words
        $title_words = preg_split('/[\s\-_]+/', strtolower($block['title'] ?? ''));
        foreach ($title_words as $word) {
            if (in_array($word, $prompt_words, true)) {
                $score += 2;
            }
        }

        // Check keywords
        foreach ($block['keywords'] ?? [] as $keyword) {
            if (str_contains($prompt_lower, strtolower($keyword))) {
                $score += 2;
            }
        }

        // Check description
        if (!empty($block['description']) && $this->text_overlaps($prompt_lower, strtolower($block['description']))) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Score layouts/sections by keyword relevance.
     */
    private function score_by_relevance(array $items, string $user_prompt, string $keyword_field): array
    {
        $prompt_lower = strtolower($user_prompt);
        $prompt_words = preg_split('/[\s,.\-\/]+/', $prompt_lower, -1, PREG_SPLIT_NO_EMPTY);

        $scored = [];
        foreach ($items as $item) {
            $score = 0;

            // Match keywords
            foreach ($item[$keyword_field] ?? [] as $keyword) {
                if (str_contains($prompt_lower, strtolower($keyword))) {
                    $score += 2;
                }
            }

            // Match name
            $name_words = preg_split('/[\s\-_]+/', strtolower($item['name'] ?? ''));
            foreach ($name_words as $word) {
                if (in_array($word, $prompt_words, true)) {
                    $score += 3;
                }
            }

            // Match category
            foreach ($item['category'] ?? [] as $cat) {
                if (str_contains($prompt_lower, strtolower($cat))) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $item['_relevance_score'] = $score;
                $scored[] = $item;
            }
        }

        usort($scored, fn($a, $b) => ($b['_relevance_score'] ?? 0) <=> ($a['_relevance_score'] ?? 0));

        return $scored;
    }

    /**
     * Score patterns by title/category relevance.
     */
    private function score_patterns_by_relevance(array $patterns, string $user_prompt): array
    {
        $prompt_lower = strtolower($user_prompt);
        $prompt_words = preg_split('/[\s,.\-\/]+/', $prompt_lower, -1, PREG_SPLIT_NO_EMPTY);

        $scored = [];
        foreach ($patterns as $pattern) {
            $score = 0;

            // Match title words
            $title_words = preg_split('/[\s\-_]+/', strtolower($pattern['title'] ?? ''));
            foreach ($title_words as $word) {
                if (in_array($word, $prompt_words, true)) {
                    $score += 3;
                }
            }

            // Match categories
            foreach ($pattern['categories'] ?? [] as $cat) {
                if (str_contains($prompt_lower, strtolower($cat))) {
                    $score += 2;
                }
            }

            if ($score > 0) {
                $pattern['_relevance_score'] = $score;
                $scored[] = $pattern;
            }
        }

        usort($scored, fn($a, $b) => ($b['_relevance_score'] ?? 0) <=> ($a['_relevance_score'] ?? 0));

        return $scored;
    }

    /**
     * Get image field annotation for the field key map output.
     *
     * Detects "Module: Image" clone patterns:
     * - *_image_type (select) → [IMAGE_TYPE]
     * - *_image (image) → [IMAGE_FILE: integer attachment ID]
     * - *_image_url (url next to image_type) → [IMAGE_URL]
     */
    private function get_image_annotation(string $field_name, array $type_map): string
    {
        $type = $type_map[$field_name] ?? '';

        // Direct image field type
        if ($type === 'image') {
            return '  [IMAGE_FILE: integer attachment ID]';
        }

        // Detect image_type select fields (field names ending in _image_type)
        if ($type === 'select' && str_ends_with($field_name, '_image_type')) {
            return '  [IMAGE_TYPE: "file" or "url"]';
        }

        // Detect image_url fields (url type, field name ending in _image_url)
        if ($type === 'url' && str_ends_with($field_name, '_image_url')) {
            return '  [IMAGE_URL: full URL string]';
        }

        return '';
    }

    /**
     * Strip field key values from layout/pattern markup so Claude uses the field key maps instead.
     * Replaces "_fieldname":"field_xxxxx" with "_fieldname":"LOOKUP_FROM_FIELD_KEY_MAP"
     */
    private function strip_field_keys_from_markup(string $markup): string
    {
        // Replace "_anything":"field_hex" patterns with a placeholder
        return preg_replace(
            '/"(_[a-z][a-z0-9_]*)"\s*:\s*"(field_[a-f0-9]+(?:_field_[a-f0-9]+)*)"/',
            '"$1":"USE_FIELD_KEY_MAP"',
            $markup
        );
    }

    /**
     * Check if any meaningful words from the prompt appear in the text.
     */
    private function text_overlaps(string $prompt, string $text): bool
    {
        $words = preg_split('/[\s,.\-\/]+/', $prompt, -1, PREG_SPLIT_NO_EMPTY);
        $stop_words = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'with', 'and', 'or', 'for', 'to', 'in', 'on', 'at', 'of', 'that', 'this', 'it', 'me', 'my', 'i', 'we', 'our', 'make', 'create', 'add', 'build', 'generate', 'page', 'post'];

        foreach ($words as $word) {
            if (strlen($word) > 2 && !in_array($word, $stop_words, true) && str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
