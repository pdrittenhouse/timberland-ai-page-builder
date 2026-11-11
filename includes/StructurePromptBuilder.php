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
    /*
     * CORE_BLOCKS / COMMON_BLOCKS moved to BlockRelevance. They were byte-identical to
     * PromptBuilder's copies, which is how both ended up with the same unreachable fallback.
     */

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

        $custom = $this->build_custom_prompt_section();
        if ($custom) {
            $sections[] = $custom;
        }

        return implode("\n\n---\n\n", array_filter($sections));
    }

    /**
     * The tool-mode system prompt: every block by name, no field lists.
     *
     * **Why this exists, measured.** `build()` picks which blocks to describe by keyword-scoring
     * the operator's prose, and against the real 48-block manifest a Figma-derived prompt scored
     * **6 blocks, none of which could hold content** -- so the model invented field names on the
     * structural blocks it had. Three separate defects in that scorer have been fixed and the
     * number barely moved, because the approach is the problem: a Figma prompt describes
     * provenance, not content, and any keyword rule is one accidental substring from starving the
     * catalogue again.
     *
     * The field lists are what made selection seem necessary: 14,972 fields across 48 blocks, about
     * 312 each, or roughly 149,500 tokens to inline them all. The names alone are ~1,400 -- one
     * percent. So every block is named here and the detail is fetched on demand through
     * {@see BlockContextTool}, which is what `MarkupGenerator` already does for direct mode. There
     * is then no selection step to get wrong.
     *
     * **The catalogue is built from the MANIFEST, not from the curated index.** The curated index
     * covers 41 of 48 blocks, and the seven it omits include `card`, `card-grid`'s sibling
     * `hero-unit`, `jumbotron`, `button` and `image` -- the content blocks a page is mostly made
     * of. Listing only curated entries would reintroduce the same starvation one layer down, and
     * would make the prompt's claim that these are the only blocks that exist false.
     *
     * @param array<string, mixed>                       $decomposition the confirmed layout plan
     * @param array<string, array{best_for: string, avoid_when: string}> $curated keyed by BARE slug
     */
    public function build_for_tools(string $user_prompt, array $decomposition, array $curated = []): string
    {
        $sections = [];
        $sections[] = $this->build_rules_section();
        $sections[] = $this->build_tool_catalog($curated);
        $sections[] = $this->build_vocabulary_section();
        $sections[] = $this->build_nesting_section();
        $sections[] = $this->build_layout_plan_section($decomposition);

        $custom = $this->build_custom_prompt_section();

        if ($custom) {
            $sections[] = $custom;
        }

        return implode("\n\n---\n\n", array_filter($sections));
    }

    /**
     * The values a page may style itself with.
     *
     * **Added because the absence was measured, not assumed.** Generated pages applied no
     * background or text colours at all, and the cause was not comprehension: colour fields never
     * reached the model, and the prompt told it to leave styling alone. Naming the vocabulary is
     * half of fixing that; the other half is letting those fields through (UC6).
     *
     * Costs ~478 tokens and is TOOL-MODE ONLY. The inline path carries a hand-tuned fixed preamble
     * against a 2,250-token ceiling with ~75 to spare, so adding this there would trip a ratchet
     * that exists precisely to make such additions deliberate. Tool mode already spends ~4,100 on
     * the catalogue against a settable budget, so it can afford to say what the colours are.
     *
     * Icons are NAMED, 33 of them; a model cannot pick an icon from a count. Utility classes are
     * not -- 14,252 names is roughly 43,000 tokens, so families are described and the names come
     * from `get_utility_classes` on demand.
     */
    private function build_vocabulary_section(): string
    {
        $vocab = (new DesignVocabulary())->summary();

        $lines = ["# DESIGN VOCABULARY\n"];
        $lines[] = "These are the ONLY values this site defines. Do not invent a colour name, a";
        $lines[] = "spacing step or an icon: an unrecognised value is dropped silently at render, which";
        $lines[] = "looks like the design system ignoring you.\n";

        $named = [
            'Colours (slugs)' => $vocab['colors'],
            'Gradients' => $vocab['gradients'],
            'Font sizes' => $vocab['font_sizes'],
            'Spacing steps' => $vocab['spacing'],
            'Breakpoints' => $vocab['breakpoints'],
            'Icons' => $vocab['icons'],
        ];

        foreach ($named as $label => $values) {
            if ($values === []) {
                continue;
            }

            $lines[] = sprintf('%s: %s', $label, implode(', ', $values));
        }

        $lines[] = sprintf(
            "\nUtility classes: %d exist. Families: %s.",
            $vocab['utility_count'],
            implode('; ', array_map(
                static fn(string $prefix, string $what): string => $prefix . '-* ' . $what,
                array_keys($vocab['utility_families']),
                array_values($vocab['utility_families'])
            ))
        );
        $lines[] = 'Call `' . DesignTokenTool::NAME . '` to see the exact class names in a family.';
        $lines[] = 'Prefer a block\'s own fields over a utility class: a field is design-system';
        $lines[] = 'styling, a utility is an override. Reach for a utility only when no field fits.';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Every block the manifest has, one line each.
     *
     * Ordered by the manifest so the text is byte-identical between calls with the same manifest,
     * which is what lets the prefix cache. Curated guidance is preferred where it exists because it
     * says what a block is FOR; the manifest description is the fallback, and the slug alone is the
     * last resort -- a named block with no description is still choosable, whereas an omitted one
     * is not.
     *
     * @param array<string, array{best_for: string, avoid_when: string}> $curated keyed by bare slug
     */
    private function build_tool_catalog(array $curated): string
    {
        $blocks = $this->manifest['blocks'] ?? [];

        $out = "# BLOCK CATALOGUE\n\n";
        // "There are no others" was not enough, measured. A generation emitted `core/heading` and
        // `core/paragraph` for a band that `acf/link-group` fits exactly -- a titled list of
        // category links -- and reported no gap. The model knows core blocks exist in WordPress, so
        // it read "no others" as "no other ACF blocks" rather than as a prohibition. Saying so
        // explicitly costs one line.
        $out .= "Every block that exists, with what it is for. There are no others.\n";
        $out .= "This EXCLUDES WordPress core blocks. Do NOT emit `core/heading`, `core/paragraph`,\n";
        $out .= "`core/image` or any other `core/*` block: they render, but they carry none of this\n";
        $out .= "site's fields or styling, so they are an invisible downgrade rather than a choice.\n";
        $out .= "A heading or a paragraph is a FIELD on a block above, not a block of its own.\n";
        $out .= "You do NOT yet know their field names. Call `" . BlockContextTool::NAME . "` with\n";
        $out .= "every block you intend to use, in ONE call, before writing any block tree.\n";
        $out .= "Field names are never guessable: a plausible name that is not in a block's field\n";
        $out .= "list is rejected by validation, and the value is lost.\n\n";

        $out .= BlockCatalogue::lines($blocks, $curated);

        $out .= "\nIf a section needs something no block above can express, call `" . GapTool::NAME . "`\n";
        $out .= "instead of substituting the nearest block OR reaching for a core block, then build\n";
        $out .= "the parts that ARE expressible. A reported gap beside a partial page is useful; a\n";
        $out .= "page that silently approximates the design is not. Reaching for `core/*` is the\n";
        $out .= "silent approximation this rule exists to prevent, not an exception to it.\n";

        return $out;
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

            // `PromptDecomposer` asks the model for `"content": { ... }` and validates only
            // that a `sections` key came back, not that each section's content is an object. A
            // model returning a plain string there is a routine deviation, and iterating it
            // unguarded emitted a PHP warning and dropped that section's content from the
            // prompt entirely -- so the next model built a tree from an incomplete plan and
            // nothing reported it. A string is kept as the section's content rather than
            // discarded, because it is still what the planner meant.
            if (!empty($section['content'])) {
                if (is_scalar($section['content'])) {
                    $output .= "  content: {$section['content']}\n";
                } elseif (is_array($section['content'])) {
                    foreach ($section['content'] as $key => $value) {
                        if (!empty($value) && is_scalar($value)) {
                            $output .= "  {$key}: {$value}\n";
                        }
                    }
                }
            }

            /*
             * FG4 -- what the band is MADE OF, when the plan carries it.
             *
             * A Figma-derived plan knows a band holds six repeated elements each with an image; a
             * prompt-derived one does not, so this is emitted only when present rather than being a
             * required key. One compact line per group, because the shape ("6x 573x558, image") is
             * the whole signal -- the model does not need coordinates to tell a six-up grid from a
             * split hero, it needs to know there are six of them.
             *
             * Before this existed the Home frame's 61 nested frames and 27 image fills reached the
             * model as nothing at all, and a 1728x1115 band arrived as two strings and a height.
             */
            if (!empty($section['structure']) && is_array($section['structure'])) {
                $output .= "  composed of:\n";

                foreach ($section['structure'] as $group) {
                    if (!is_array($group)) {
                        continue;
                    }

                    $count = (int) ($group['count'] ?? 1);
                    $line = sprintf('%s%s', $count > 1 ? $count . 'x ' : '', (string) ($group['size'] ?? ''));

                    if (!empty($group['images'])) {
                        $line .= ', image';
                    }

                    // The attachment IDs, once the assets have been imported. This is the whole
                    // point of importing them: the prompt's image rules ask for an integer
                    // attachment ID, and until these existed the plan could say "image" and give
                    // the model nothing to put in the field -- measured as 10 slots described and
                    // 0 set, which was unreachable rather than ignored.
                    $ids = array_values(array_filter((array) ($group['image_ids'] ?? []), 'is_numeric'));

                    if ($ids !== []) {
                        // The CORRESPONDENCE is stated, not just the list. Measured: a page given
                        // six ids for a six-up grid used 11 of 24 imported images overall, because
                        // a bare list does not say that the sixth id belongs to the sixth card.
                        // "one per element, in order" is the whole difference between a grid of six
                        // photographs and a grid of one repeated six times.
                        $ids = array_map('intval', array_slice($ids, 0, 12));

                        $line .= sprintf(
                            ' [IMAGE_FILE ids%s: %s]',
                            $count > 1
                                ? sprintf(', one per element in order — use all %d', count($ids))
                                : '',
                            implode(', ', $ids)
                        );
                    }

                    if (!empty($group['texts']) && is_array($group['texts'])) {
                        $line .= ' "' . implode('" / "', array_slice($group['texts'], 0, 3)) . '"';
                    }

                    $output .= "    - {$line}\n";
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
     * Which blocks this prompt gets to see.
     *
     * Delegated to {@see BlockRelevance}: this method and `score_block()` were near-duplicates of
     * `PromptBuilder`'s, and so carried the same three defects -- substring keyword matching, a
     * count-gated fallback that the substring bug made unreachable, and scoring only the operator's
     * prose while the confirmed plan's own content went unread.
     *
     * `score_description` stays false here, which is what this builder already did.
     *
     * @param array<string, mixed> $decomposition
     *
     * @return array<string, array<string, mixed>>
     */
    private function filter_blocks_for_prompt(string $user_prompt, array $decomposition): array
    {
        return BlockRelevance::select($this->manifest['blocks'] ?? [], $user_prompt, $decomposition, false);
    }
}
