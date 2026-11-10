<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates the full generation flow:
 * rate limit → access check → manifest → prompt → Claude → validate → (retry) → history → result.
 */
class MarkupGenerator
{
    private ManifestStore $manifest_store;
    private RateLimiter $rate_limiter;

    /**
     * How an LLM client is obtained, as `fn(?string $model): LLMClientInterface`.
     *
     * Injectable so the pipeline can be exercised without an API key, a network call or a
     * charge. The seam is the INTERFACE the codebase already defines, not the HTTP layer:
     * `ClaudeClient` builds `new Anthropic\Client(apiKey: ...)` internally and `OpenAIClient`
     * does the equivalent, so there is no transport to swap -- a mock HTTP client could not
     * reach either. `LLMClientInterface` is the real boundary, and both clients already
     * implement it.
     *
     * @var callable(?string): LLMClientInterface
     */
    private $client_factory;

    /**
     * @param callable(?string): LLMClientInterface|null $client_factory
     */
    public function __construct(
        ManifestStore $manifest_store,
        RateLimiter $rate_limiter,
        ?callable $client_factory = null
    ) {
        $this->manifest_store = $manifest_store;
        $this->rate_limiter = $rate_limiter;
        $this->client_factory = $client_factory
            ?? static fn(?string $model): LLMClientInterface => LLMClientFactory::create($model);
    }

    /**
     * Generate block markup from a user prompt.
     *
     * @return array{markup: string, validation: array, api_response: array}
     */
    public function generate(string $prompt, string $post_type = 'page', ?int $post_id = null, array $use_patterns = [], ?string $model = null): array
    {
        $user_id = get_current_user_id();

        // Rate limit check
        $rate_check = $this->rate_limiter->check($user_id);
        if (!$rate_check['allowed']) {
            throw new \RuntimeException($rate_check['message']);
        }

        // Access control check
        if (!$this->user_can_generate()) {
            throw new \RuntimeException('You do not have permission to generate content.');
        }

        // Load manifest — auto-regenerate if field_type_map is missing
        $manifest = $this->manifest_store->get();
        if (!$this->manifest_has_type_maps($manifest)) {
            $manifest = $this->manifest_store->regenerate();
        }

        // Extract original image data from ALL selected patterns before generation
        $pattern_image_data = [];
        foreach ($use_patterns as $pattern_id) {
            $pattern_content = $this->resolve_pattern_content($pattern_id, $manifest);
            if ($pattern_content) {
                $images = $this->extract_image_data($pattern_content, $manifest);
                foreach ($images as $block_name => $block_images) {
                    $pattern_image_data[$block_name] = array_merge(
                        $pattern_image_data[$block_name] ?? [],
                        $block_images
                    );
                }
            }
        }

        // Build prompts
        $prompt_builder = new PromptBuilder($manifest);
        $client = ($this->client_factory)($model);

        $settings = Plugin::get_settings();
        $use_tools = ($settings['context_mode'] ?? 'tools') === 'tools' && $use_patterns === [];

        $budget = null;
        $context_tool = null;
        $gap_tool = null;

        if ($use_tools) {
            /**
             * Tool mode: a catalogue plus on-demand field detail, instead of guessing which
             * blocks the request needs and inlining every field of each.
             *
             * Measured on "Add a hero with a headline": the inline path described 9 blocks for
             * 4,194 tokens, of which 2,471 described blocks the request could not use. Raw totals
             * come out similar (6,277 vs 6,083) -- the win is that the spend follows the model's
             * actual choices, nothing truncates silently, and the ~4,551-token prefix is
             * byte-identical across turns so it caches. Measured live: 4,952 tokens written to
             * cache on the first call and read back on the second, which bills at roughly a tenth.
             *
             * Pattern-seeded generations stay on the inline path. A selected pattern already
             * names its blocks, so there is nothing for the model to choose and the catalogue
             * would be pure overhead.
             */
            $budget = new PromptBudget([
                'post_type' => $post_type,
                'prompt' => $prompt,
            ]);

            $system_prompt = $prompt_builder->build_for_tools(
                $post_type,
                (new CuratedContext())->selection_index()
            );

            $budget->charge('system prompt', $system_prompt);

            $context_tool = new BlockContextTool($manifest, $budget, $prompt_builder);
            $gap_tool = new GapTool();

            // Routed by name so each tool owns its own contract. An unknown name still comes back
            // as text the model can act on rather than an exception -- see BlockContextTool.
            $dispatch = static function (string $name, array $input) use ($context_tool, $gap_tool): string {
                if ($name === GapTool::NAME) {
                    return $gap_tool->dispatch($input);
                }

                return $context_tool->dispatch($name, $input);
            };

            $api_response = $client->generate_with_tools(
                $system_prompt,
                $prompt,
                [$context_tool->definition(), $gap_tool->definition()],
                $dispatch
            );
        } else {
            $system_prompt = $prompt_builder->build($prompt, $post_type, $use_patterns);
            $api_response = $client->generate($system_prompt, $prompt);
        }

        // Record rate limit usage
        $this->rate_limiter->record($user_id);

        // Validate what will actually be STORED, which means post-processing FIRST.
        //
        // This used to validate the raw response and post-process afterwards, and the retry
        // decision was made on the pre-processing result. That was wrong in both directions
        // once the prompt stopped sending ACF field keys:
        //
        //  - post-processing UNCONDITIONALLY writes every `_fieldname` companion from the
        //    manifest, so a response correctly omitting them -- which is now what the prompt
        //    asks for -- failed validation on "missing companion" and burned a retry fixing
        //    something that was already going to be fixed;
        //  - anything post-processing itself broke was never a retry trigger, because the
        //    decision had already been taken.
        //
        // The order now matches what the words mean: generate, normalise, judge.
        $validator = new MarkupValidator($manifest, Plugin::get_schema_oracle());

        $normalise = fn(string $raw): string => $this->post_process_markup(
            $this->strip_markdown_fences($raw),
            $manifest,
            $pattern_image_data,
            $prompt
        );

        $clean_markup = $normalise($api_response['content']);
        $validation = $validator->validate($clean_markup);

        // If validation failed with errors, retry once with feedback
        if (!$validation['valid'] && !empty($validation['errors'])) {
            $retry_response = $client->generate_with_retry(
                $system_prompt,
                $prompt,
                $validation['errors']
            );

            $retry_markup = $normalise($retry_response['content']);
            $retry_validation = $validator->validate($retry_markup);

            // Use retry if it's better (fewer errors or now valid)
            if ($retry_validation['valid'] || count($retry_validation['errors']) < count($validation['errors'])) {
                $input_tokens = $api_response['input_tokens'] + $retry_response['input_tokens'];
                $output_tokens = $api_response['output_tokens'] + $retry_response['output_tokens'];

                $api_response = $retry_response;
                $validation = $retry_validation;
                $clean_markup = $retry_markup;

                // Both attempts were paid for, so both are reported.
                $api_response['input_tokens'] = $input_tokens;
                $api_response['output_tokens'] = $output_tokens;
            }
        }

        // Re-validate attributes after post-processing for accurate frontend results.
        // Uses lightweight regex-based validation (no parse_blocks) since
        // post-processing only fixes JSON attributes — block structure is unchanged.
        $final_validation = $validator->validate_attributes($clean_markup, $validation['block_count']);

        // Save to history
        $this->save_history($user_id, $prompt, $clean_markup, $post_id, $post_type, $api_response, $final_validation);

        // Recorded so the accumulated log can answer "what should we build next" with evidence.
        if ($gap_tool !== null && $gap_tool->has_gaps()) {
            GapLog::record($gap_tool->gaps(), $prompt, $user_id);
        }

        return [
            'markup' => $clean_markup,
            'validation' => $final_validation,
            'api_response' => [
                'model' => $api_response['model'],
                'input_tokens' => $api_response['input_tokens'],
                'output_tokens' => $api_response['output_tokens'],
                'stop_reason' => $api_response['stop_reason'],
                // Present only in tool mode. Reported separately from `input_tokens` on purpose:
                // caching is a COST saving and must not be mistaken for a smaller prompt, or the
                // budget starts tracking provider pricing instead of how much the model reads.
                'tool_turns' => $api_response['tool_turns'] ?? null,
                'cache_read_tokens' => $api_response['cache_read_tokens'] ?? null,
                'cache_write_tokens' => $api_response['cache_write_tokens'] ?? null,
            ],
            /**
             * What the budget did, and -- the half that matters -- what it refused.
             *
             * `withheld` names any block whose fields could not be afforded. Those blocks were
             * told to the model as UNAVAILABLE and kept out of its allowed set, so a thin page is
             * explainable rather than mysterious, and an operator can re-run with a raised
             * ceiling (`token_budget`, `TAIPB_TOKEN_BUDGET`, or the `taipb_token_budget` filter)
             * instead of wondering why a block it asked for is missing.
             *
             * `unknown` names blocks the model asked for that do not exist here -- the clearest
             * signal available that the request wanted something the library cannot express.
             */
            /**
             * Gaps: what the request asked for that no block can express (L3).
             *
             * A structured result ALONGSIDE the composition, never an error path -- the markup
             * for the expressible parts is still returned. Two sources, deliberately distinct:
             * `reported` is the model calling `report_gap`, and `unknown_blocks` is it asking for
             * a block that does not exist here. Budget-withheld blocks are NOT gaps and are kept
             * out of this entirely: that is a ceiling the operator can raise, not a missing
             * component, and conflating them would put phantom entries on a roadmap.
             */
            'gaps' => $gap_tool === null ? null : [
                'reported' => $gap_tool->gaps(),
                'unknown_blocks' => $context_tool->unknown(),
                'has_gaps' => $gap_tool->has_gaps() || $context_tool->unknown() !== [],
            ],
            'context' => $budget === null ? null : [
                'mode' => 'tools',
                'budget' => $budget->report(),
                // No null checks: the budget and the tool are created together in the branch
                // above, so reaching here with one and not the other is impossible.
                'served' => $context_tool->served(),
                'withheld' => $context_tool->withheld(),
                'unknown' => $context_tool->unknown(),
            ],
        ];
    }

    /**
     * Check if the current user has permission to generate.
     */
    private function user_can_generate(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $settings = Plugin::get_settings();
        $allowed_roles = $settings['allowed_roles'] ?? ['administrator', 'editor'];
        $user = wp_get_current_user();

        return !empty(array_intersect($allowed_roles, $user->roles));
    }

    /**
     * Save generation to the history table.
     */
    private function save_history(int $user_id, string $prompt, string $markup, ?int $post_id, string $post_type, array $api_response, array $validation): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'taipb_history';

        $wpdb->insert($table_name, [
            'user_id' => $user_id,
            'prompt' => $prompt,
            'generated_markup' => $markup,
            'post_id' => $post_id,
            'post_type' => $post_type,
            'model' => $api_response['model'],
            'input_tokens' => $api_response['input_tokens'],
            'output_tokens' => $api_response['output_tokens'],
            'validation_result' => wp_json_encode($validation),
            'created_at' => current_time('mysql', true),
        ], [
            '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s',
        ]);
    }

    /**
     * Check if the manifest is current (version matches plugin version).
     * Forces regeneration when the plugin is updated, ensuring FieldKeyMap
     * changes (like the clone field fix) are reflected in the manifest.
     */
    private function manifest_has_type_maps(array $manifest): bool
    {
        // Version mismatch means manifest is stale
        if (($manifest['version'] ?? '') !== TAIPB_VERSION) {
            return false;
        }

        // Also check that field_type_map data exists at all
        foreach ($manifest['blocks'] ?? [] as $block) {
            if (!empty($block['field_type_map'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve pattern/layout content from its ID.
     */
    private function resolve_pattern_content(string $pattern_id, array $manifest): ?string
    {
        if (str_starts_with($pattern_id, 'pattern_')) {
            $id = (int) substr($pattern_id, 8);
            foreach ($manifest['patterns'] ?? [] as $pattern) {
                if (($pattern['id'] ?? 0) === $id) {
                    return $pattern['content'] ?? null;
                }
            }
        } elseif (str_starts_with($pattern_id, 'layout_')) {
            $index = (int) substr($pattern_id, 7);
            $layouts = $manifest['layouts'] ?? [];
            if (isset($layouts[$index])) {
                return $layouts[$index]['content'] ?? null;
            }
        }
        return null;
    }

    /**
     * Extract image field data from pattern markup for later preservation.
     *
     * Returns: [block_name => [field_name => value, ...], ...]
     * Captures image_type, image (attachment ID), and image_url fields.
     */
    private function extract_image_data(string $markup, array $manifest): array
    {
        $image_data = [];

        preg_match_all(
            '/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) (?:\/)?-->/s',
            $markup,
            $all_matches,
            PREG_SET_ORDER
        );

        foreach ($all_matches as $match) {
            $block_name = 'acf/' . $match[1];
            $block_json = json_decode($match[2], true);
            if (!$block_json || !isset($block_json['data'])) {
                continue;
            }

            $type_map = $manifest['blocks'][$block_name]['field_type_map'] ?? [];
            $variants = $manifest['blocks'][$block_name]['field_variants'] ?? [];
            $image_groups = $this->detect_image_groups($type_map);

            if (empty($image_groups)) {
                continue;
            }

            $data = $block_json['data'];
            $block_images = [];

            foreach ($image_groups as $group) {
                $type_val = $data[$group['type_field']] ?? '';
                $file_val = $data[$group['file_field']] ?? '';
                $url_val = $data[$group['url_field']] ?? '';

                // Only save if there's actual image data
                if (!empty($file_val) || !empty($url_val)) {
                    $block_images[$group['type_field']] = $type_val;
                    $block_images[$group['file_field']] = $file_val;
                    $block_images[$group['url_field']] = $url_val;
                }
            }

            if (!empty($block_images)) {
                $image_data[$block_name] = $block_images;
            }
        }

        return $image_data;
    }

    /**
     * Post-process generated markup:
     * 1. UNCONDITIONALLY overwrite all _companion keys from the manifest field_key_map
     * 2. Fix image field data (URL/file detection, type switching, integer casting)
     * 3. Restore pattern images when Claude broke them
     */
    private function post_process_markup(string $markup, array $manifest, array $pattern_image_data, string $user_prompt): string
    {
        $prompt_lower = strtolower($user_prompt);
        $user_mentions_image = (bool) preg_match('/\b(image|photo|picture|img|logo|icon|banner|thumbnail)\b/i', $prompt_lower);

        return preg_replace_callback(
            '/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) (\/)?-->/s',
            function ($matches) use ($manifest, $pattern_image_data, $user_mentions_image) {
                $block_name = 'acf/' . $matches[1];
                $json_str = $matches[2];
                $self_closing = $matches[3] ?? '';

                $block_data = json_decode($json_str, true);
                if (!$block_data || !isset($block_data['data'])) {
                    return $matches[0];
                }

                $block_def = $manifest['blocks'][$block_name] ?? null;
                if (!$block_def) {
                    return $matches[0];
                }

                $type_map = $block_def['field_type_map'] ?? [];
                $variants = $block_def['field_variants'] ?? [];
                $key_map = $block_def['field_key_map'] ?? [];
                $data = &$block_data['data'];

                // --- Step 1: Fix image fields ---
                $image_groups = $this->detect_image_groups($type_map);
                $original_images = $pattern_image_data[$block_name] ?? [];

                foreach ($image_groups as $group) {
                    $type_field = $group['type_field'];
                    $file_field = $group['file_field'];
                    $url_field = $group['url_field'];

                    $current_type = $data[$type_field] ?? '';
                    $file_value = $data[$file_field] ?? '';
                    $url_value = $data[$url_field] ?? '';

                    // Fix: If the file field contains a URL string, try resolving to local attachment first
                    if (is_string($file_value) && preg_match('#^https?://#i', $file_value)) {
                        $resolved_id = $this->resolve_image_reference($file_value);
                        if ($resolved_id) {
                            $data[$file_field] = $resolved_id;
                            $data[$type_field] = 'file';
                        } else {
                            // External URL — keep as URL mode
                            $data[$type_field] = 'url';
                            $data[$url_field] = $file_value;
                            $data[$file_field] = '';
                        }
                    }
                    // Fix: If the url field has a URL but type isn't "url", correct it
                    elseif (!empty($url_value) && is_string($url_value) && preg_match('#^https?://#i', $url_value) && $current_type !== 'url') {
                        $data[$type_field] = 'url';
                    }
                    // Fix: If file field has a numeric value, cast to int and ensure type is "file"
                    elseif (is_numeric($file_value) && (int) $file_value > 0) {
                        $data[$file_field] = (int) $file_value;
                        if (empty($current_type) || $current_type !== 'file') {
                            $data[$type_field] = 'file';
                        }
                    }
                    // Fix: If file field has a non-numeric, non-URL string (filename/description),
                    // try resolving it to an attachment ID
                    elseif (is_string($file_value) && !empty($file_value) && !is_numeric($file_value)) {
                        $resolved_id = $this->resolve_image_reference($file_value);
                        if ($resolved_id) {
                            $data[$file_field] = $resolved_id;
                            $data[$type_field] = 'file';
                        } elseif (!empty($original_images[$file_field])) {
                            // Couldn't resolve — restore pattern image
                            $data[$type_field] = $original_images[$type_field] ?? 'file';
                            $data[$file_field] = $original_images[$file_field];
                            $data[$url_field] = $original_images[$url_field] ?? '';
                        } else {
                            $data[$file_field] = '';
                        }
                    }
                    // Fix: If type is "file" but file field is empty, try restoring from pattern
                    elseif (($current_type === 'file' || empty($current_type)) && empty($file_value) && empty($url_value)) {
                        if (!$user_mentions_image && !empty($original_images[$file_field])) {
                            $data[$type_field] = $original_images[$type_field] ?? 'file';
                            $data[$file_field] = $original_images[$file_field];
                            $data[$url_field] = $original_images[$url_field] ?? '';
                        }
                    }

                    // Ensure all three fields exist in data
                    if (!array_key_exists($type_field, $data)) {
                        $data[$type_field] = !empty($original_images[$type_field]) ? $original_images[$type_field] : 'file';
                    }
                    if (!array_key_exists($file_field, $data)) {
                        $data[$file_field] = $original_images[$file_field] ?? '';
                    }
                    if (!array_key_exists($url_field, $data)) {
                        $data[$url_field] = $original_images[$url_field] ?? '';
                    }
                }

                // --- Step 2: Ensure clone parent fields exist ---
                // ACF needs clone parent entries (e.g., "image": "", "_image": "field_xxx")
                // to reconstruct nested field structures. These are typically empty strings
                // with companion keys — without them, ACF can't group prefixed children
                // (e.g., image_image_type, image_image) into the nested fields.image structure.
                foreach ($type_map as $field_name => $field_type) {
                    if ($field_type === 'clone' && !array_key_exists($field_name, $data)) {
                        $data[$field_name] = '';
                    }
                }

                // --- Step 2b: repair an invented leading prefix ---
                //
                // Models prefix field names with a layout word the block's own vocabulary
                // suggests. `acf/hero-unit` renders a Bootstrap jumbotron and really does expose
                // `hero_layout_jumbotron_layout`, so a generation emitted `jumbotron_title`,
                // `jumbotron_subtitle`, `jumbotron_bg_image_bg_image` -- none of which exist,
                // while `title`, `subtitle` and `bg_image_bg_image` all do. Six such keys landed
                // in one generation, each one a schema error AND an unmapped-companion warning.
                //
                // The repair only fires when dropping the first `_`-delimited segment produces a
                // name the block actually has, and never overwrites a key already present. A
                // genuinely hallucinated field (`jumbotron_container`, `jumbotron_fluid` -- the
                // block has no `container` or `fluid` either) still fails validation, which is
                // what should happen: this corrects a naming slip, it does not invent fields.
                foreach (array_keys($data) as $data_key) {
                    $data_key = (string) $data_key;

                    if (str_starts_with($data_key, '_') || isset($key_map[$data_key])) {
                        continue;
                    }

                    $stripped = strstr($data_key, '_');

                    if ($stripped === false) {
                        continue;
                    }

                    $stripped = substr($stripped, 1);

                    if ($stripped === '' || !isset($key_map[$stripped])) {
                        continue;
                    }

                    // The target may already be present as an EMPTY placeholder that step 1 or
                    // step 2 inserted -- which is exactly what happened to
                    // `jumbotron_bg_image_bg_image`, where step 1 had already seeded
                    // `bg_image_bg_image` with ''. Refusing the rename there left the invented
                    // key in place and the block failed `additionalProperties: false` anyway, so
                    // the guard was converting a recoverable naming slip into a hard error.
                    //
                    // A placeholder is overwritten; a real value already set is not. Either way
                    // the invented key is removed, because it is not a field on this block and
                    // leaving it guarantees the whole block is rejected.
                    $existing = $data[$stripped] ?? null;
                    $is_placeholder = !array_key_exists($stripped, $data)
                        || $existing === '' || $existing === null || $existing === 0 || $existing === '0';

                    if ($is_placeholder) {
                        $data[$stripped] = $data[$data_key];
                    }

                    unset($data[$data_key], $data['_' . $data_key]);
                }

                // --- Step 2c: a repeater written as a JSON array becomes indexed rows ---
                //
                // Must precede step 3, because the row keys it creates are what step 3b then
                // writes companions for. Shared with MarkupAssembler, which had the same gap --
                // see RepeaterRows for why it is not inlined here.
                $data = RepeaterRows::flatten($data, $type_map);

                // --- Step 3: UNCONDITIONALLY overwrite ALL companion keys ---
                // This is critical: Claude may output wrong field keys that look valid.
                // We always replace with the authoritative keys from the manifest.
                foreach ($key_map as $field_name => $field_key) {
                    if (array_key_exists($field_name, $data)) {
                        $data['_' . $field_name] = $field_key;
                    }
                }

                // --- Step 3b: companions for REPEATER ROW values ---
                //
                // A repeater stores each row as `{field}_{index}_{subfield}`, and ACF writes a
                // companion for every one of them -- verified against a real editor-saved
                // `acf/column` on this install:
                //
                //     col_width_0_width   = '10'
                //     _col_width_0_width  = 'field_62394838a64ef'
                //
                // The key map holds sub-fields WITHOUT the row index (`col_width_width`), so
                // step 3 above never matched them and their companions were simply absent. The
                // validator reported that as a warning, so a generation looked successful while
                // producing rows ACF cannot resolve -- column widths that silently do not apply.
                //
                // Stripping the index is the whole fix: `col_width_0_width` -> look up
                // `col_width_width`.
                foreach (array_keys($data) as $data_key) {
                    if (str_starts_with((string) $data_key, '_')) {
                        continue;
                    }

                    if (!preg_match('/^(.+?)_(\d+)_(.+)$/', (string) $data_key, $parts)) {
                        continue;
                    }

                    [, $parent, , $sub] = $parts;

                    // Only for a parent that really is a repeater, so a field whose own name
                    // happens to contain `_0_` is not rewritten.
                    $parent_type = $type_map[$parent] ?? '';

                    if ($parent_type !== 'repeater' && $parent_type !== 'flexible_content') {
                        continue;
                    }

                    $sub_key = $key_map[$parent . '_' . $sub] ?? null;

                    if ($sub_key !== null) {
                        $data['_' . $data_key] = $sub_key;
                    }
                }

                // --- Step 3c: disambiguate the names ACF defines MORE THAN ONCE ---
                //
                // `acf/logo-slider.logo_count` is a `select` of words when `slider == flickity`
                // and a `number` when `slider == slick`; `responsive_settings_value` is one of
                // number/true_false/text depending on its own `_value_type` sibling. The key map
                // is keyed by name, so step 3 above wrote whichever definition was merged last --
                // wrong for every data object that meant the other one, with nothing to show it.
                //
                // The conditional rules are what separate the variants, so they are the only
                // non-guessing discriminator: pick the variant whose condition the data actually
                // satisfies. Where no condition matches, the key map's own choice stands.
                //
                // Runs LAST, after step 3b: `responsive_settings_value` is a repeater sub-field,
                // so 3b rewrote its row companions from the name-keyed map and silently undid the
                // disambiguation when this ran before it. Verified by the row cases below.
                foreach ($variants as $field_name => $candidates) {
                    foreach (array_keys($data) as $data_key) {
                        $data_key = (string) $data_key;

                        if (str_starts_with($data_key, '_')) {
                            continue;
                        }

                        if ($data_key !== $field_name && !$this->is_row_of($data_key, $field_name, $type_map)) {
                            continue;
                        }

                        $chosen = $this->choose_variant($candidates, $data, $data_key, $field_name);

                        if ($chosen !== null) {
                            $data['_' . $data_key] = $chosen;
                        }
                    }
                }

                $new_json = wp_json_encode($block_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return "<!-- wp:acf/{$matches[1]} {$new_json} {$self_closing}-->";
            },
            $markup
        );
    }

    /**
     * Is `$data_key` a repeater ROW of `$field_name`?
     *
     * `responsive_settings_value` is stored per row as `responsive_settings_0_value`, so a
     * variant declared on the flattened name has to match the indexed data key too.
     */
    private function is_row_of(string $data_key, string $field_name, array $type_map): bool
    {
        if (!preg_match('/^(.+?)_(\d+)_(.+)$/', $data_key, $parts)) {
            return false;
        }

        [, $parent, , $sub] = $parts;
        $parent_type = $type_map[$parent] ?? '';

        if ($parent_type !== 'repeater' && $parent_type !== 'flexible_content') {
            return false;
        }

        return $parent . '_' . $sub === $field_name;
    }

    /**
     * Pick the variant whose conditional rules the data satisfies, or null to leave step 3's
     * choice alone.
     *
     * Returns null rather than guessing when nothing matches. A wrong key written confidently is
     * worse than the key map's existing choice, which is at least a real field on this block.
     *
     * @param array<int, array{key: string, type: string, choices: array<int, string>, conditional: array}> $candidates
     * @param array<string, mixed> $data
     */
    private function choose_variant(array $candidates, array $data, string $data_key, string $field_name): ?string
    {
        foreach ($candidates as $candidate) {
            if (empty($candidate['conditional'])) {
                continue;
            }

            // ACF's structure is an OR of ANDs.
            foreach ($candidate['conditional'] as $group) {
                $all = true;

                foreach ($group as $rule) {
                    if (!$this->rule_holds($rule, $data, $data_key, $field_name)) {
                        $all = false;
                        break;
                    }
                }

                if ($all) {
                    return (string) $candidate['key'];
                }
            }
        }

        return null;
    }

    /**
     * @param array{field: string, operator: string, value: string} $rule
     * @param array<string, mixed> $data
     */
    private function rule_holds(array $rule, array $data, string $data_key, string $field_name): bool
    {
        $dep = (string) $rule['field'];

        if (!array_key_exists($dep, $data)) {
            // The controlling field may live in the SAME repeater row, where both names carry the
            // row index -- `responsive_settings_0_value` is governed by
            // `responsive_settings_0_value_type`, not by a top-level `responsive_settings_value_type`.
            $row = $this->same_row_name($dep, $data_key, $field_name);

            if ($row === null || !array_key_exists($row, $data)) {
                return false;
            }

            $dep = $row;
        }

        $actual = (string) $data[$dep];
        $wanted = (string) $rule['value'];

        return $rule['operator'] === '!=' ? $actual !== $wanted : $actual === $wanted;
    }

    /**
     * Rewrite a controlling field's flattened name into the same repeater row as `$data_key`.
     */
    private function same_row_name(string $dep, string $data_key, string $field_name): ?string
    {
        if (!preg_match('/^(.+?)_(\d+)_/', $data_key, $parts)) {
            return null;
        }

        [, $parent, $index] = $parts;

        if (!str_starts_with($dep, $parent . '_')) {
            return null;
        }

        return $parent . '_' . $index . '_' . substr($dep, strlen($parent) + 1);
    }

    /**
     * Detect image field groups from a block's field type map.
     *
     * The "Module: Image" clone creates three fields per image:
     * - {prefix}_image_type (select: "file" or "url")
     * - {prefix}_image (image: attachment ID)
     * - {prefix}_image_url (url: external URL)
     *
     * @return array[] Each entry: ['type_field' => ..., 'file_field' => ..., 'url_field' => ...]
     */
    private function detect_image_groups(array $type_map): array
    {
        $groups = [];
        $seen_prefixes = [];

        foreach ($type_map as $field_name => $field_type) {
            if ($field_type === 'image') {
                $type_field = $field_name . '_type';
                $url_field = $field_name . '_url';

                if (isset($type_map[$type_field]) || isset($type_map[$url_field])) {
                    if (!isset($seen_prefixes[$field_name])) {
                        $seen_prefixes[$field_name] = true;
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
     * Handles three cases:
     * 1. Full URL → attachment_url_to_postid() for local images
     * 2. Filename with extension → query _wp_attached_file meta
     * 3. Plain text description → search attachment post titles
     *
     * @return int|null Attachment ID if resolved, null otherwise
     */
    private function resolve_image_reference(string $value): ?int
    {
        $value = trim($value);
        if (empty($value)) {
            return null;
        }

        // Case 1: Full URL — try local attachment lookup
        if (preg_match('#^https?://#i', $value)) {
            $id = attachment_url_to_postid($value);
            if ($id > 0) {
                return $id;
            }
            // Try stripping size suffixes (-300x200) that WordPress adds to resized images
            $stripped = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $value);
            if ($stripped !== $value) {
                $id = attachment_url_to_postid($stripped);
                if ($id > 0) {
                    return $id;
                }
            }
            return null; // External URL — caller handles URL mode
        }

        // Case 2: Filename with extension — query _wp_attached_file meta
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

        // Case 3: Plain text — search attachment post titles
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

    /**
     * Strip markdown code fences and any non-block text from LLM output.
     *
     * LLMs sometimes wrap block markup in markdown fences or add explanatory
     * text before/after. This function extracts only the block comment content.
     */
    private function strip_markdown_fences(string $markup): string
    {
        // Remove markdown code fences (```html, ```xml, ``` etc.)
        $markup = preg_replace('/^```(?:html|xml|php|plaintext)?\s*\n?/im', '', $markup);
        $markup = preg_replace('/\n?```\s*$/m', '', $markup);

        // Remove any text before the first block comment
        $first_block = strpos($markup, '<!-- wp:');
        if ($first_block !== false && $first_block > 0) {
            $markup = substr($markup, $first_block);
        }

        // Remove any text after the last closing comment
        $last_close = strrpos($markup, '-->');
        if ($last_close !== false) {
            $markup = substr($markup, 0, $last_close + 3);
        }

        return trim($markup);
    }
}
