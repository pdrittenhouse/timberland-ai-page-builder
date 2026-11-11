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
    /**
     * ACF field types that carry CONTENT rather than presentation.
     *
     * Only these are listed in the prompt. The rest -- `number`, `select`, `true_false`,
     * `color_picker`, `editor_palette` and friends -- are the spacing, colour, alignment and
     * visibility scaffolding that ACF clone expansion multiplies across every block, and the
     * design system already has defaults for all of it.
     *
     * Measured on `acf/promo`, the worst case on natural-rose: 503 non-clone fields, of which
     * **50 (10%) are content-bearing**. The remainder is 134 `number`, 115 `select`, 58 `group`,
     * 57 `true_false`, 38 `editor_palette` and 38 `color_picker`. Listing all of them cost ~4,558
     * tokens for that one block, against a 3,000-8,000 allowance for the whole of component
     * context.
     *
     * A request that genuinely wants styling ("a hero with a blue background") is not served by
     * this and is the obvious next step -- include the styling fields that match the request
     * rather than all of them or none. Until then the omission is stated in the prompt so the
     * model leaves those fields alone instead of inventing names for them.
     */
    private const CONTENT_FIELD_TYPES = [
        'text',
        'textarea',
        'wysiwyg',
        'image',
        'link',
        'url',
        'email',
        'post_object',
        'relationship',
        'page_link',
        'gallery',
        'file',
        'oembed',
        'repeater',
    ];

    /**
     * The whole allowance for component context, from the cost model: 3,000-8,000 tokens.
     *
     * Every other ceiling here is derived from this one at runtime rather than fixed, because
     * fixed per-section numbers cannot add up when one of the sections is operator input.
     * Measured on natural-rose after activation: the site's `custom_system_prompt` is 6,107
     * characters (~1,533 tokens) of site-analysis prose, appended as SITE-SPECIFIC INSTRUCTIONS
     * and previously unbudgeted, which took an otherwise-compliant prompt to 8,024. The option
     * was empty every time this was measured through a probe harness, so the gap only appeared
     * once the plugin ran through its own bootstrap on a configured site.
     */
    private const PROMPT_TOKEN_BUDGET = 8000;

    /**
     * The least the AVAILABLE BLOCKS section may be squeezed to.
     *
     * Blocks are the contract the model must satisfy. If an operator writes enough custom
     * instruction to squeeze them below this, the budget is knowingly exceeded rather than
     * shipping a prompt that cannot describe a single block -- the overage is then attributable
     * to the setting that caused it.
     */
    private const BLOCKS_SECTION_TOKEN_FLOOR = 2500;

    /**
     * Token ceiling for the REFERENCE PATTERNS section.
     *
     * These are few-shot examples: a saved editor pattern's FULL markup, and a saved pattern can
     * be arbitrarily long. Measured on updater-starter, two of them cost 3,655 tokens and pushed
     * an otherwise-compliant prompt to 9,792 -- natural-rose has no saved patterns at all, so
     * the same code produced 6,449 there and the overage was invisible until both were measured.
     *
     * Budgeted lower than the blocks section deliberately. Blocks are the CONTRACT the model
     * must satisfy; patterns are a worked example of structure it can already infer from the
     * rules. When something has to give, the example gives.
     */
    private const PATTERNS_SECTION_TOKEN_BUDGET = 1100;

    /**
     * The layout primitives, still used below to label core vs content blocks in the catalogue.
     *
     * Aliased to BlockRelevance so selection and labelling cannot drift apart -- they were two
     * separate literals saying the same thing. COMMON_BLOCKS moved there outright; only the
     * fallback used it.
     */
    private const CORE_BLOCKS = BlockRelevance::CORE_BLOCKS;

    private array $manifest;


    private ?SchemaOracle $oracle;

    /**
     * @param array<string, mixed> $manifest
     * @param SchemaOracle|null    $oracle the SAME schemas the validator enforces, for field shapes
     */
    public function __construct(array $manifest, ?SchemaOracle $oracle = null)
    {
        $this->manifest = $manifest;
        // Resolved lazily, not here. `Plugin::get_schema_oracle()` reaches for
        // `get_template_directory()`, and constructing it eagerly made every unit test that builds
        // a prompt depend on WordPress being bootstrapped -- for a schema most blocks never need,
        // since only non-scalar fields are annotated.
        $this->oracle = $oracle;
    }

    /**
     * Build the full system prompt for a given user prompt and context.
     *
     * @param array $use_patterns Pattern/layout IDs to use as base (e.g., ["pattern_123", "layout_5"]).
     */
    public function build(string $user_prompt, string $post_type = 'page', array $use_patterns = []): string
    {
        $relevant_blocks = $this->filter_blocks_for_prompt($user_prompt);

        // Everything whose size this class does not control is built and MEASURED first; the
        // blocks section then gets whatever is left of the allowance. Build order is not output
        // order -- the sections are reassembled below in the order the model should read them.
        $rules = $this->build_rules_section();
        $nesting = $this->build_nesting_section();
        $post_type_section = $this->build_post_type_section($post_type);

        $pattern_sections = [];

        if (!empty($use_patterns)) {
            $pattern_sections[] = $this->build_selected_patterns_section($use_patterns);
        } else {
            $pattern_sections[] = $this->build_layouts_section($user_prompt);
            $pattern_sections[] = $this->build_patterns_section($user_prompt);
        }

        $custom = $this->build_custom_prompt_section();

        $committed = $this->estimate_tokens($rules)
            + $this->estimate_tokens($nesting)
            + $this->estimate_tokens($post_type_section)
            + $this->estimate_tokens($custom);

        foreach ($pattern_sections as $section) {
            $committed += $this->estimate_tokens($section);
        }

        // Separators and the section's own heading, so the remainder is not optimistic.
        $committed += 80;

        $blocks_budget = max(
            self::BLOCKS_SECTION_TOKEN_FLOOR,
            self::PROMPT_TOKEN_BUDGET - $committed
        );

        $sections = array_merge(
            [$rules, $this->build_blocks_section($relevant_blocks, $blocks_budget), $nesting, $post_type_section],
            $pattern_sections,
            $custom ? [$custom] : []
        );

        return implode("\n\n---\n\n", array_filter($sections));
    }

    /**
     * Build custom system prompt section from site settings.
     */
    /**
     * The framework's token estimator, `ceil(length / 4)`, so both sides of the stack count the
     * same way -- `AiManifestPlugin` enforces the selection index's ceiling with the identical
     * formula.
     */
    private function estimate_tokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

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
Every ACF block has a `data` object in its JSON. Set the field by NAME only:

```
"data": {
  "field_name": "the value"
}
```

Do NOT write `_field_name` companion entries and do NOT write ACF field keys (`field_XXXXX`)
anywhere. The server adds every companion key itself, from its own authoritative map, after you
respond — anything you write there is discarded and replaced. Use ONLY the field names listed
under each block in AVAILABLE BLOCKS; a name that is not listed will be rejected.

A field name sharing a prefix with a listed one does NOT imply it exists, and neither does the
block's visual style. `hero-unit` renders a Bootstrap jumbotron and lists
`hero_layout_jumbotron_layout`, but its content fields are plain: `title`, `subtitle`,
`bg_image_bg_image`. There is no `jumbotron_title`, no `jumbotron_container`, no
`jumbotron_fluid`. Never prefix a field name with a layout, style or block name — copy each name
exactly as listed.

## CRITICAL: Yes/No Fields

Write `"1"` for on and `"0"` for off. That is what ACF stores, and what the editor itself
writes.

Never write the strings `"true"` or `"false"`. `"false"` is especially dangerous: PHP casts a
non-empty string to boolean TRUE, so `"include_image": "false"` would turn the image ON. It is
rejected for that reason. A real JSON `true`/`false` and a bare `1`/`0` are both accepted.

## CRITICAL: Repeating Fields

A repeating field is NOT a JSON array. ACF stores the ROW COUNT on the field itself, and each
row's values as separate flat keys numbered from zero:

```
"data": {
  "col_width": 2,
  "col_width_0_breakpoint": "md",
  "col_width_0_width": "6",
  "col_width_1_breakpoint": "lg",
  "col_width_1_width": "4"
}
```

Writing `"col_width": [{...}]` is rejected. The row keys follow
`{field}_{index}_{subfield}` exactly.

## CRITICAL: Do Not Copy Field Keys From Examples
Reference patterns and layouts below may contain `_field_name` companions and `field_XXXXX`
keys from whenever they were captured. Ignore them entirely and never carry them into your
output. Keep the structure and the field NAMES; drop the keys.

## Using Patterns and Layouts
When the user references a known pattern or layout by name (e.g., "telco hero", "home cards"), use the matching REFERENCE PATTERN or REFERENCE LAYOUT as a starting point. Adapt the content to match the user's request (change text, titles, etc.) but preserve the block structure. Strip any field keys and `_` companions from the example — the server supplies those.

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
Many blocks use a cloned "Module: Image" with THREE related fields. Image fields in each block's field list are annotated with `[IMAGE_TYPE]`, `[IMAGE_FILE]`, or `[IMAGE_URL]`. These MUST be used together correctly:

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
- The prefix varies by block (e.g., `image_image`, `bg_image_bg_image`, `image_one_image`). Always check the field-list annotations to identify the correct field names.
RULES;
    }

    /**
     * Build the available blocks section: prose, curated usage notes and FIELD NAMES.
     *
     * Field keys are deliberately omitted -- see the note at the field list below.
     */
    private function build_blocks_section(array $blocks, int $budget): string
    {
        $output = "# AVAILABLE BLOCKS\n\n";
        $dropped = [];

        // Reserve for the dropped-block notice BEFORE filling, at its worst case: every
        // non-core candidate named. Measured at 132 tokens for 29 blocks on natural-rose, which
        // is what pushed an otherwise-exact budget 48 over -- the notice was emitted after the
        // ceiling had already been reached and was never counted against it. The worst case is
        // computable here, so it is reserved rather than estimated.
        $notice_reserve = 0;

        foreach ($blocks as $name => $_) {
            if (!in_array($name, self::CORE_BLOCKS, true)) {
                $notice_reserve += (int) ceil((strlen($name) + 4) / 4);
            }
        }

        if ($notice_reserve > 0) {
            $notice_reserve += 16; // the sentence around the list
        }

        $budget = max(0, $budget - $notice_reserve);
        $spent = 0;

        foreach ($blocks as $name => $block) {
            // Each block's text is built first and MEASURED before being appended. Testing
            // `$spent >= budget` on entry was the first attempt and let one oversized block
            // overshoot by however large it happened to be -- measured at 598 tokens over on
            // updater-starter. The cost has to be known before the decision, not after.
            $entry = $this->render_block_entry($name, $block);

            $cost = intdiv(strlen($entry), 4);
            $is_core = in_array($name, self::CORE_BLOCKS, true);

            // Core blocks go in regardless: without section/row/column the model cannot produce
            // a valid layout, so dropping one to save tokens buys a cheaper broken page.
            if (!$is_core && $spent + $cost > $budget) {
                $dropped[] = $name;
                continue;
            }

            $output .= $entry;
            $spent += $cost;
        }

        if ($dropped !== []) {
            // Named, not hidden. The model can still ask for one of these by name on a later
            // turn, and a reader of the prompt can see the budget bound rather than wondering
            // why a block it expected is absent.
            $output .= 'Also available, not described here to stay inside the context budget: '
                . implode(', ', array_map(static fn(string $n): string => "`{$n}`", $dropped))
                . ".\n\n";
        }

        return $output;
    }


    /**
     * The system prompt for a tool-driven generation: rules and a CATALOGUE, no field lists.
     *
     * The inline path guesses which blocks a request needs by keyword and then describes every
     * field of each. Measured on "Add a hero with a headline": 9 blocks, 4,194 tokens, 69% of the
     * prompt -- and 2,471 of those tokens described blocks the request could not use (`promo`,
     * `jumbotron`, `table-head`, `table-cell`). The catalogue covers all 41 blocks for ~2,530
     * tokens, and the model fetches field detail only for what it actually chooses.
     *
     * The catalogue is `best_for` / `avoid_when` per block -- the curation the framework already
     * commits (UC1/UC2). It is what a block is FOR, which is what choosing needs; field names are
     * what writing needs, and those arrive through the tool.
     *
     * Kept byte-identical across the turns of a generation so it can be sent as a cached prefix.
     * Nothing request-specific belongs in here.
     *
     * @param array<string, array{best_for: string, avoid_when: string}> $catalogue
     */
    public function build_for_tools(string $post_type, array $catalogue): string
    {
        $prompt = $this->build_rules_section();
        $prompt .= $this->build_post_type_section($post_type);
        $prompt .= $this->build_nesting_section();
        $prompt .= $this->build_custom_prompt_section();

        $prompt .= "\n# BLOCK CATALOGUE\n\n";
        $prompt .= "What each block is for. These are the ONLY blocks that exist.\n";
        $prompt .= "You do not yet know their field names: call `" . BlockContextTool::NAME . "` with\n";
        $prompt .= "every block you intend to use, in ONE call, before writing any markup.\n\n";

        // Membership comes from the manifest; `$catalogue` only decorates it. Iterating the curated
        // index instead -- which is what this did -- offered 41 of 48 blocks and omitted `button`,
        // `card`, `hero-unit`, `image`, `jumbotron`, `menu` and `posts-loop`, so the model could
        // not choose a card or a hero while being told there were no other blocks. It also printed
        // the index's BARE keys, naming `accordion` for a block that must be emitted as
        // `acf/accordion`.
        $prompt .= BlockCatalogue::lines($this->manifest['blocks'] ?? [], $catalogue);

        $prompt .= "\nIf the request needs something no block above can express, call `" . GapTool::NAME . "`\n";
        $prompt .= "instead of substituting the nearest block, then carry on and build the parts that\n";
        $prompt .= "ARE expressible. A gap reported beside a partial page is useful; a page that\n";
        $prompt .= "silently approximates the request is not, and reporting one is never wrong.\n";

        return $prompt;
    }

    /**
     * Everything the model needs in order to WRITE one block, as prompt text.
     *
     * Extracted so the on-demand tool and the inline catalogue cannot drift. `BlockContextTool`
     * serves exactly this text when the model asks for a block, and `build_blocks_section()`
     * still uses it for the legacy inline path -- two callers, one definition of what a block
     * looks like to a model.
     *
     * Returns '' for a block the manifest does not have, which the caller must treat as "do not
     * offer this block", never as "a block with no fields".
     */
    public function describe_block(string $block_name): string
    {
        $block = $this->manifest['blocks'][$block_name] ?? null;

        if (!is_array($block)) {
            return '';
        }

        return $this->render_block_entry($block_name, $block);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function render_block_entry(string $name, array $block): string
    {
        $entry = '';
        $entry .= "## {$block['title']} (`{$name}`)\n";

        if (!empty($block['description'])) {
            $entry .= "{$block['description']}\n";
        }

        $entry .= "- Type: " . ($block['is_container'] ? 'container (jsx)' : 'leaf') . "\n";

        if (!empty($block['keywords'])) {
            $entry .= "- Keywords: " . implode(', ', $block['keywords']) . "\n";
        }

        if (!empty($block['parent'])) {
            $entry .= "- Parent restriction: " . implode(', ', $block['parent']) . "\n";
        }

        if (!empty($block['usage_notes'])) {
            $entry .= "- Usage notes: {$block['usage_notes']}\n";
        }

        // FIELD NAMES ONLY. The ACF keys are deliberately not here.
        //
        // `MarkupGenerator::post_process_markup()` step 3 and
        // `MarkupAssembler::build_data_object()` step 4 both UNCONDITIONALLY overwrite every
        // `_fieldname` companion from the manifest's key map -- the generator's own comment
        // says why: "Claude may output wrong field keys that look valid. We always replace
        // with the authoritative keys." So every key sent to the model was discarded and
        // recomputed server-side.
        //
        // Measured on natural-rose's real 48-block manifest before this change: the
        // `name => key` pairs were **97% of the entire per-block prompt cost** -- 10,060
        // non-clone fields, ~143,000 tokens if every block were described, against a
        // 3,000-8,000 token allowance for all component context. A single request for "a
        // simple contact page" pulled 21 blocks and cost 48,313 tokens, almost all of it
        // teaching the model values that were then thrown away.
        //
        // Clone-type fields stay skipped: they are internal ACF scaffolding (always empty
        // strings) used to rebuild nested structures, and the post-processor handles them.
        if (!empty($block['field_key_map'])) {
            $type_map = $block['field_type_map'] ?? [];
            $listed = 0;
            $omitted = 0;
            $lines = '';

            foreach ($block['field_key_map'] as $field_name => $field_key) {
                $type = $type_map[$field_name] ?? '';

                if ($type === 'clone') {
                    continue;
                }

                if (!in_array($type, self::CONTENT_FIELD_TYPES, true)) {
                    $omitted++;
                    continue;
                }

                $annotation = $this->get_image_annotation($name, $field_name, $type_map);
                $lines .= "  {$field_name}{$annotation}\n";
                $listed++;
            }

            if ($listed > 0) {
                $entry .= "\nContent fields (give each a value in `data`; field keys are added for you):\n";
                $entry .= "```\n" . $lines . "```\n";
            }

            if ($omitted > 0) {
                // Say that they exist. Without this the model infers the listed set is
                // exhaustive and invents names for styling it wants to set -- which the
                // schema validator now rejects, turning a silent omission into a failed
                // generation. Telling it to leave them alone is cheaper than either.
                $entry .= "- {$omitted} further styling/layout fields exist (spacing, colour,"
                    . " alignment, visibility). They are not listed: leave them unset and the"
                    . " design system's defaults apply. Do NOT invent names for them.\n";
            }
        } else {
            // A block the manifest knows but has NO fields for.
            //
            // Silence here is the failure mode this whole tool loop exists to prevent. The model
            // was shown a title, a description and then nothing -- indistinguishable from "this
            // block takes no content" -- so a model asked to fill a section invents plausible
            // field names, which is precisely how `col_width` and `cards` reached a block tree and
            // cost 40 validation errors.
            //
            // It happens without anyone doing anything wrong: block membership comes from
            // `WP_Block_Type_Registry` while fields come from ACF field groups titled `Block: ` or
            // `Module: `. A block that registers before its group exists, or whose group is titled
            // anything else, lands here. `describe_block()`'s contract already says an ABSENT block
            // must never read as "a block with no fields"; this says the same thing out loud for a
            // present one.
            // Kept to two lines on purpose. The budget test caught the first version at five, and
            // was right to: the model needs a directive, not an explanation, and this is prose
            // charged against every described block in the inline path. The reason it happens
            // belongs in this comment, not in the prompt.
            $entry .= "- NO field definitions available: do not guess field names for this block.\n";
            $entry .= "  Use a block whose fields you have, or call `" . GapTool::NAME . "`.\n";
        }

        $entry .= "\n";
        return $entry;
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
        $output .= "IMPORTANT: Field keys in these patterns have been blanked to `USE_FIELD_KEY_MAP`. Delete those entries entirely -- do not look up replacements and do not emit any `_` companion. The server fills them in.\n\n";
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
        $output .= "IMPORTANT: Field keys in this pattern have been blanked to `USE_FIELD_KEY_MAP`. Delete those entries entirely -- do not look up replacements and do not emit any `_` companion. The server fills them in.\n\n";
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
        $output .= "These are existing layouts on the site. Use them as structural templates — adapt the content to match the user's request. WARNING: These examples may carry outdated field keys and `_` companions. Drop them; keep the structure and the field names only.\n\n";
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
        $output .= "These are saved editor patterns. Use them as structural templates. WARNING: Drop every field key and `_` companion from these examples; keep the structure and the field names only.\n\n";
        $output .= "When the user references a pattern by name, use the matching pattern as the base and modify as requested.\n\n";

        $spent = 0;
        $included = 0;

        foreach ($top as $pattern) {
            $markup = $this->strip_field_keys_from_markup($pattern['content']);
            $cost = intdiv(strlen($markup), 4);

            $remaining = self::PATTERNS_SECTION_TOKEN_BUDGET - $spent;

            if ($remaining <= 0) {
                continue;
            }

            // TRUNCATE rather than skip. Exempting "at least one example" from the ceiling was
            // the first attempt and did nothing: a single saved pattern on updater-starter is
            // ~3,655 tokens on its own, so the exemption let the whole overage through. A
            // truncated example still shows the block nesting, which is the only thing this
            // section is for -- the content inside it is throwaway sample copy.
            $truncated = false;

            if ($cost > $remaining) {
                $markup = substr($markup, 0, $remaining * 4);
                $truncated = true;
                $cost = $remaining;
            }

            $output .= "## {$pattern['title']}\n";
            if (!empty($pattern['categories'])) {
                $output .= "Categories: " . implode(', ', $pattern['categories']) . "\n";
            }
            if (!empty($pattern['usage_notes'])) {
                $output .= "Notes: {$pattern['usage_notes']}\n";
            }
            $output .= "```\n" . $markup . "\n```\n";

            if ($truncated) {
                // Said out loud. A silently cut example reads as a complete one, and a model
                // copying its shape would reproduce an unclosed tree.
                $output .= "(example truncated to stay inside the context budget -- the structure"
                    . " above is illustrative and may be incomplete)\n";
            }

            $output .= "\n";

            $spent += $cost;
            $included++;
        }

        return $output;
    }

    /**
     * Filter manifest blocks to those relevant to the user's prompt.
     */
    /**
     * Which blocks this prompt gets to see.
     *
     * Delegated to {@see BlockRelevance}, which this and `StructurePromptBuilder` had near-identical
     * copies of -- and therefore identical defects. `score_description` stays true here, which is
     * the one thing the two copies genuinely differed on and the only behaviour this refactor must
     * not change for direct mode.
     *
     * @return array<string, array<string, mixed>>
     */
    private function filter_blocks_for_prompt(string $user_prompt): array
    {
        return BlockRelevance::select($this->manifest['blocks'] ?? [], $user_prompt, [], true);
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
     * Get image field annotation for the field list output.
     *
     * Detects "Module: Image" clone patterns:
     * - *_image_type (select) → [IMAGE_TYPE]
     * - *_image (image) → [IMAGE_FILE: integer attachment ID]
     * - *_image_url (url next to image_type) → [IMAGE_URL]
     */
    /**
     * The field's stored SHAPE, read from the schema the validator enforces.
     *
     * Returns '' for anything a plain string satisfies, so the common field stays a bare name and
     * costs nothing. Only the shapes that actually reject a string are annotated.
     *
     * The `anyOf` in these schemas is always `[the real shape, the unset sentinel]` -- ACF writes
     * `false`, `''`, `'null'` or `null` for an empty field -- so the branch to describe is the one
     * that is not that enum.
     *
     * `link`'s key names are ACF's own, from `class-acf-field-link.php`: `title`, `url`, `target`.
     * The schema says only `type: object`, which is enough to stop a string but not enough to
     * produce a working link, so the keys are named where ACF's type tells us what they are.
     */
    private function schema_shape(string $block_name, string $field_name, string $acf_type): string
    {
        $oracle = $this->oracle ??= function_exists('get_template_directory')
            ? Plugin::get_schema_oracle()
            : null;

        if (!$oracle instanceof SchemaOracle) {
            return '';
        }

        $schema = $oracle->schema_for($block_name);

        if (!is_array($schema)) {
            return '';
        }

        $prop = $schema['properties'][$field_name] ?? null;

        if (!is_array($prop)) {
            return '';
        }

        $branches = isset($prop['anyOf']) && is_array($prop['anyOf']) ? $prop['anyOf'] : [$prop];
        $type = null;

        foreach ($branches as $branch) {
            // Skip the unset sentinel; what is left is the shape a value must take.
            if (!is_array($branch) || isset($branch['enum'])) {
                continue;
            }

            $type = $branch['type'] ?? null;
        }

        $types = array_map('strval', (array) $type);

        if (in_array('object', $types, true)) {
            return 'link' === $acf_type
                ? '  [OBJECT: {"title","url","target"} — never a bare string]'
                : '  [OBJECT — never a bare string]';
        }

        if (in_array('array', $types, true)) {
            return '  [ARRAY of post IDs]';
        }

        return '';
    }

    private function get_image_annotation(string $block_name, string $field_name, array $type_map): string
    {
        $type = $type_map[$field_name] ?? '';

        // Direct image field type
        if ($type === 'image') {
            return '  [IMAGE_FILE: integer attachment ID]';
        }

        // Anything whose stored value is not a plain string, stated from the SCHEMA.
        //
        // Measured failure this fixes: `acf/button.link` was listed as the bare name `link`, the
        // model sent `"link": "/shop"`, and validation rejected it three times over -- "String
        // value found, but an object is required", plus both halves of the anyOf. The field was
        // visible and its SHAPE was not, so a plausible string was the only thing to send.
        //
        // Read from `SchemaOracle`, deliberately, rather than from a hand-kept map of ACF types.
        // The validator judges against that schema, so describing anything else is how a prompt
        // and its validator drift -- and five of the non-scalar ACF types do not even occur in
        // this install's schemas, so a hardcoded table would be asserting shapes nobody verified.
        $shape = $this->schema_shape($block_name, $field_name, $type);

        if ($shape !== '') {
            return $shape;
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
     * Blank field key values in layout/pattern markup so the model does not copy them forward.
     *
     * The server overwrites every companion key regardless, so a key in an example is at best
     * noise and at worst a value the model trusts over the field list.
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
}
