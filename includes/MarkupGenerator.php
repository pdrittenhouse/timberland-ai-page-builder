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

    public function __construct(ManifestStore $manifest_store, RateLimiter $rate_limiter)
    {
        $this->manifest_store = $manifest_store;
        $this->rate_limiter = $rate_limiter;
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
        $system_prompt = $prompt_builder->build($prompt, $post_type, $use_patterns);

        // Call LLM API (Claude or OpenAI based on model)
        $client = LLMClientFactory::create($model);
        $api_response = $client->generate($system_prompt, $prompt);

        // Record rate limit usage
        $this->rate_limiter->record($user_id);

        // Validate output
        $validator = new MarkupValidator($manifest);
        $validation = $validator->validate($api_response['content']);

        // If validation failed with errors, retry once with feedback
        if (!$validation['valid'] && !empty($validation['errors'])) {
            $retry_response = $client->generate_with_retry(
                $system_prompt,
                $prompt,
                $validation['errors']
            );

            // Re-validate the retry
            $retry_validation = $validator->validate($retry_response['content']);

            // Use retry if it's better (fewer errors or now valid)
            if ($retry_validation['valid'] || count($retry_validation['errors']) < count($validation['errors'])) {
                $api_response = $retry_response;
                $validation = $retry_validation;

                // Add retry tokens to totals
                $api_response['input_tokens'] += $retry_response['input_tokens'];
                $api_response['output_tokens'] += $retry_response['output_tokens'];
            }
        }

        // Strip markdown fences from final output
        $clean_markup = $this->strip_markdown_fences($api_response['content']);

        // Post-process: fix all field companion keys and image fields
        $clean_markup = $this->post_process_markup($clean_markup, $manifest, $pattern_image_data, $prompt);

        // Re-validate attributes after post-processing for accurate frontend results.
        // Uses lightweight regex-based validation (no parse_blocks) since
        // post-processing only fixes JSON attributes — block structure is unchanged.
        $final_validation = $validator->validate_attributes($clean_markup, $validation['block_count']);

        // Save to history
        $this->save_history($user_id, $prompt, $clean_markup, $post_id, $post_type, $api_response, $final_validation);

        return [
            'markup' => $clean_markup,
            'validation' => $final_validation,
            'api_response' => [
                'model' => $api_response['model'],
                'input_tokens' => $api_response['input_tokens'],
                'output_tokens' => $api_response['output_tokens'],
                'stop_reason' => $api_response['stop_reason'],
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

                // --- Step 3: UNCONDITIONALLY overwrite ALL companion keys ---
                // This is critical: Claude may output wrong field keys that look valid.
                // We always replace with the authoritative keys from the manifest.
                foreach ($key_map as $field_name => $field_key) {
                    if (array_key_exists($field_name, $data)) {
                        $data['_' . $field_name] = $field_key;
                    }
                }

                $new_json = wp_json_encode($block_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return "<!-- wp:acf/{$matches[1]} {$new_json} {$self_closing}-->";
            },
            $markup
        );
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
