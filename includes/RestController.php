<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API endpoints for the AI Page Builder.
 */
class RestController
{
    private const NAMESPACE = 'taipb/v1';

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_generate'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'prompt' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'post_type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'page',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'post_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'use_pattern' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'use_patterns' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'model' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/match', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_match'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'prompt' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/analyze', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_analyze'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'prompt' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'use_pattern' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'use_patterns' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_validate'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'markup' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/manifest', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_get_manifest'],
            'permission_callback' => [$this, 'can_edit'],
        ]);

        register_rest_route(self::NAMESPACE, '/manifest/regenerate', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_regenerate_manifest'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        register_rest_route(self::NAMESPACE, '/manifest/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_manifest_stats'],
            'permission_callback' => [$this, 'can_edit'],
        ]);

        register_rest_route(self::NAMESPACE, '/decompose', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_decompose'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'prompt' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/structure', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_structure'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'prompt' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'decomposition' => [
                    'required' => true,
                    'type' => 'object',
                ],
                'use_patterns' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'model' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/assemble', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_assemble'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'block_tree' => [
                    'required' => true,
                    'type' => 'object',
                ],
                'prompt' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'post_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'post_type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'page',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/generate-context', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_generate_context'],
            'permission_callback' => [$this, 'can_manage'],
            'args' => [
                'post_types' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/generate-pattern-notes', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_generate_pattern_notes'],
            'permission_callback' => [$this, 'can_manage'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/history', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_get_history'],
            'permission_callback' => [$this, 'can_edit'],
            'args' => [
                'post_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * POST /generate — Submit prompt, get markup.
     */
    public function handle_generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $prompt = $request->get_param('prompt');
        $post_type = $request->get_param('post_type') ?? 'page';
        $post_id = $request->get_param('post_id');

        // Normalize: accept use_patterns (array) or use_pattern (string)
        $use_patterns = $request->get_param('use_patterns');
        if (empty($use_patterns)) {
            $use_pattern = $request->get_param('use_pattern');
            $use_patterns = $use_pattern ? [$use_pattern] : [];
        }

        if (empty(trim($prompt))) {
            return new \WP_Error('taipb_empty_prompt', 'Prompt cannot be empty.', ['status' => 400]);
        }

        try {
            $store = Plugin::get_manifest_store();
            $rate_limiter = new RateLimiter();
            $generator = new MarkupGenerator($store, $rate_limiter);

            $model = $request->get_param('model');
            $result = $generator->generate($prompt, $post_type, $post_id, $use_patterns, $model);

            return new \WP_REST_Response($result, 200);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'rate limit') ? 429 : 500;
            $status = str_contains($e->getMessage(), 'permission') ? 403 : $status;
            $status = str_contains($e->getMessage(), 'API key') ? 401 : $status;

            return new \WP_Error('taipb_error', $e->getMessage(), ['status' => $status]);
        }
    }

    /**
     * POST /match — Find matching patterns/layouts for a prompt.
     */
    public function handle_match(\WP_REST_Request $request): \WP_REST_Response
    {
        $prompt = $request->get_param('prompt');
        $store = Plugin::get_manifest_store();
        $manifest = $store->get();

        $matches = [];
        $prompt_lower = strtolower($prompt);

        // Search patterns
        foreach ($manifest['patterns'] ?? [] as $pattern) {
            $score = $this->match_score($pattern['title'] ?? '', $prompt_lower);
            if ($score > 0) {
                $matches[] = [
                    'type' => 'pattern',
                    'id' => 'pattern_' . ($pattern['id'] ?? ''),
                    'title' => $pattern['title'],
                    'categories' => $pattern['categories'] ?? [],
                    'score' => $score,
                ];
            }
        }

        // Search layouts
        foreach ($manifest['layouts'] ?? [] as $index => $layout) {
            $score = $this->match_score($layout['name'] ?? '', $prompt_lower);
            if ($score > 0) {
                $matches[] = [
                    'type' => $layout['type'] ?? 'layout',
                    'id' => 'layout_' . $index,
                    'title' => $layout['name'],
                    'collection' => $layout['collection'] ?? '',
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending, take top 5
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        $matches = array_slice($matches, 0, 5);

        return new \WP_REST_Response([
            'matches' => $matches,
            'has_matches' => !empty($matches),
        ], 200);
    }

    /**
     * Score how well a title matches the user prompt.
     */
    private function match_score(string $title, string $prompt_lower): int
    {
        $title_lower = strtolower($title);

        // Exact substring match (highest score)
        if (str_contains($prompt_lower, $title_lower)) {
            return 10;
        }

        // Word-by-word matching
        $title_words = preg_split('/[\s\-_]+/', $title_lower, -1, PREG_SPLIT_NO_EMPTY);
        $prompt_words = preg_split('/[\s,.\-\/]+/', $prompt_lower, -1, PREG_SPLIT_NO_EMPTY);
        $stop_words = ['a', 'an', 'the', 'with', 'and', 'or', 'for', 'to', 'in', 'on', 'of'];

        $matched = 0;
        $meaningful_words = 0;

        foreach ($title_words as $word) {
            if (in_array($word, $stop_words, true) || strlen($word) < 3) {
                continue;
            }
            $meaningful_words++;
            if (in_array($word, $prompt_words, true)) {
                $matched++;
            }
        }

        if ($meaningful_words === 0) {
            return 0;
        }

        // Require at least half of meaningful title words to match
        $match_ratio = $matched / $meaningful_words;
        if ($match_ratio >= 0.5 && $matched >= 2) {
            return (int) ($match_ratio * 8);
        }

        return 0;
    }

    /**
     * POST /analyze — Analyze a pattern + prompt for potential ambiguities.
     * Returns clarification questions the user should answer before generation.
     */
    public function handle_analyze(\WP_REST_Request $request): \WP_REST_Response
    {
        $prompt = $request->get_param('prompt');

        // Normalize: accept use_patterns (array) or use_pattern (string)
        $use_patterns = $request->get_param('use_patterns');
        if (empty($use_patterns)) {
            $use_pattern = $request->get_param('use_pattern');
            $use_patterns = $use_pattern ? [$use_pattern] : [];
        }

        $store = Plugin::get_manifest_store();
        $manifest = $store->get();

        // Detect ambiguities across ALL selected patterns
        $questions = [];
        foreach ($use_patterns as $pattern_id) {
            $pattern_questions = $this->detect_ambiguities($prompt, $pattern_id, $manifest);
            foreach ($pattern_questions as $q) {
                // Prefix question IDs with pattern ID to avoid collisions
                // when multiple patterns produce the same question type
                if (count($use_patterns) > 1) {
                    $q['id'] = $pattern_id . '__' . $q['id'];
                    $q['pattern_id'] = $pattern_id;
                }
                $questions[] = $q;
            }
        }

        return new \WP_REST_Response([
            'questions' => $questions,
            'has_questions' => !empty($questions),
        ], 200);
    }

    /**
     * Detect ambiguities between a prompt and pattern that need user clarification.
     */
    private function detect_ambiguities(string $prompt, string $pattern_id, array $manifest): array
    {
        $questions = [];
        $prompt_lower = strtolower($prompt);

        // Resolve pattern content
        $content = $this->resolve_pattern_content($pattern_id, $manifest);
        if (!$content) {
            return $questions;
        }

        // Check for text content modifications
        $mentions_title = (bool) preg_match('/\b(title|heading|headline)\b/i', $prompt_lower);
        $mentions_description = (bool) preg_match('/\b(description|text|body|content|copy|paragraph)\b/i', $prompt_lower);
        $mentions_text_change = $mentions_title || $mentions_description;

        // Analyze pattern for container blocks with both InnerBlocks and data fields
        preg_match_all(
            '/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) -->/s',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $block_slug = $match[1][0];
            $block_name = 'acf/' . $block_slug;
            $json_str = $match[2][0];
            $open_end = $match[0][1] + strlen($match[0][0]);

            $close_tag = "<!-- /wp:acf/{$block_slug} -->";
            $close_pos = strpos($content, $close_tag, $open_end);
            if ($close_pos === false) {
                continue;
            }

            $inner_content = substr($content, $open_end, $close_pos - $open_end);
            $block_json = json_decode($json_str, true);
            $data = $block_json['data'] ?? [];

            // Hero-unit specific ambiguity detection
            if ($block_slug === 'hero-unit' && $mentions_text_change) {
                $hero_type = $data['hero_type'] ?? '';
                $has_inner_heading = (bool) preg_match('/<!-- wp:heading/', $inner_content);
                $has_data_title = !empty($data['title'] ?? '');

                // Ambiguous: feature/jumbotron with BOTH InnerBlocks headings and data field title
                if (in_array($hero_type, ['feature', 'jumbotron'], true) && $has_inner_heading && $has_data_title) {
                    $questions[] = [
                        'id' => 'hero_content_location',
                        'block' => $block_name,
                        'question' => 'This hero has content in both the block settings and InnerBlocks. Where should the title/text changes be applied?',
                        'options' => [
                            ['value' => 'data_fields', 'label' => 'Block settings (title, subtitle, text fields)'],
                            ['value' => 'innerblocks', 'label' => 'InnerBlocks (heading and paragraph blocks inside)'],
                            ['value' => 'both', 'label' => 'Update both locations'],
                        ],
                        'default' => 'data_fields',
                    ];
                }
            }
        }

        // Check for image-related ambiguity
        $mentions_image = (bool) preg_match('/\b(image|photo|picture|img|logo|icon|banner|thumbnail)\b/i', $prompt_lower);
        $has_url = (bool) preg_match('#https?://#i', $prompt);

        if ($mentions_image) {
            // Try to extract a filename from the prompt (quoted or after "named/called/file")
            $image_filename = null;
            if (preg_match("#['\"]([^'\"]+\.\w{2,5})['\"]#", $prompt, $fname_matches)) {
                $image_filename = $fname_matches[1];
            } elseif (preg_match('/(?:named?|called|file(?:name)?)\s+(\S+\.\w{2,5})/i', $prompt, $fname_matches)) {
                $image_filename = $fname_matches[1];
            }

            if ($image_filename) {
                // Try to resolve the filename in the media library
                $resolved_id = $this->resolve_media_attachment($image_filename);

                if ($resolved_id) {
                    // Image found in media library — no question needed.
                    // The post-processor's resolve_image_reference() will handle it.
                } else {
                    // Image NOT found in media library
                    $questions[] = [
                        'id' => 'image_handling',
                        'question' => "Could not find '{$image_filename}' in the media library. What would you like to do?",
                        'options' => [
                            ['value' => 'keep', 'label' => 'Keep the existing pattern image'],
                            ['value' => 'provide_name', 'label' => 'Try a different filename'],
                            ['value' => 'provide_url', 'label' => 'Provide an image URL instead'],
                            ['value' => 'clear', 'label' => 'Remove the image (set it manually later)'],
                        ],
                        'default' => 'keep',
                    ];
                }
            } elseif (!$has_url) {
                // User mentions image but no filename or URL provided
                $has_pattern_image = (bool) preg_match('/"[a-z_]*image[a-z_]*"\s*:\s*\d+/', $content);
                if ($has_pattern_image) {
                    $questions[] = [
                        'id' => 'image_handling',
                        'question' => 'This pattern has an existing image. You mentioned an image — what should we do?',
                        'options' => [
                            ['value' => 'keep', 'label' => 'Keep the existing pattern image'],
                            ['value' => 'provide_name', 'label' => 'Specify an image filename from the media library'],
                            ['value' => 'provide_url', 'label' => 'Provide an image URL'],
                            ['value' => 'clear', 'label' => 'Remove the image (set it manually later)'],
                        ],
                        'default' => 'keep',
                    ];
                }
            }
            // If user provided a URL, no question needed — post-processor handles it
        }

        return $questions;
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
     * Resolve an image filename or description to a WordPress attachment ID.
     */
    private function resolve_media_attachment(string $value): ?int
    {
        $value = trim($value);
        if (empty($value)) {
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

        // Search by post title
        $posts = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            's'           => $value,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        return !empty($posts) ? (int) $posts[0] : null;
    }

    /**
     * POST /validate — Validate a markup string.
     */
    public function handle_validate(\WP_REST_Request $request): \WP_REST_Response
    {
        $markup = $request->get_param('markup');

        $store = Plugin::get_manifest_store();
        $manifest = $store->get();
        $validator = new MarkupValidator($manifest);

        return new \WP_REST_Response($validator->validate($markup), 200);
    }

    /**
     * GET /manifest — Get the manifest (for debugging/inspection).
     */
    public function handle_get_manifest(\WP_REST_Request $request): \WP_REST_Response
    {
        $store = Plugin::get_manifest_store();
        return new \WP_REST_Response($store->get(), 200);
    }

    /**
     * POST /manifest/regenerate — Rebuild the manifest.
     */
    public function handle_regenerate_manifest(\WP_REST_Request $request): \WP_REST_Response
    {
        $store = Plugin::get_manifest_store();
        $manifest = $store->regenerate();
        $stats = $store->get_stats();

        return new \WP_REST_Response([
            'message' => 'Manifest regenerated successfully.',
            'stats' => $stats,
        ], 200);
    }

    /**
     * GET /manifest/stats — Get manifest statistics.
     */
    public function handle_manifest_stats(\WP_REST_Request $request): \WP_REST_Response
    {
        $store = Plugin::get_manifest_store();
        return new \WP_REST_Response($store->get_stats(), 200);
    }

    /**
     * GET /history — List past generations.
     */
    public function handle_get_history(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'taipb_history';

        $per_page = min($request->get_param('per_page') ?? 20, 100);
        $page = max($request->get_param('page') ?? 1, 1);
        $offset = ($page - 1) * $per_page;
        $post_id = $request->get_param('post_id');

        $where = 'WHERE 1=1';
        $params = [];

        // Non-admins can only see their own history
        if (!current_user_can('manage_options')) {
            $where .= ' AND user_id = %d';
            $params[] = get_current_user_id();
        }

        if ($post_id) {
            $where .= ' AND post_id = %d';
            $params[] = $post_id;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table_name} {$where}";
        $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : (int) $wpdb->get_var($count_sql);

        $params[] = $per_page;
        $params[] = $offset;
        $query = "SELECT id, user_id, prompt, post_id, post_type, model, input_tokens, output_tokens, created_at FROM {$table_name} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $results = $wpdb->get_results($wpdb->prepare($query, ...$params));

        return new \WP_REST_Response([
            'items' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ], 200);
    }

    /**
     * POST /decompose — Decompose a prompt into structured layout sections.
     */
    public function handle_decompose(\WP_REST_Request $request): \WP_REST_Response
    {
        $prompt = $request->get_param('prompt');
        $store = Plugin::get_manifest_store();
        $manifest = $store->get();

        try {
            $decomposer = new PromptDecomposer();
            $result = $decomposer->decompose($prompt, $manifest);

            return new \WP_REST_Response([
                'decomposition' => $result,
                'has_sections' => !empty($result['sections']),
            ], 200);
        } catch (\RuntimeException $e) {
            // Decomposition failed — return empty result so caller falls back to keyword matching
            return new \WP_REST_Response([
                'decomposition' => ['sections' => [], 'overall_intent' => '', 'suggested_pattern_ids' => []],
                'has_sections' => false,
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    /**
     * POST /structure — Generate a JSON block tree from a confirmed layout plan.
     * Step 2 of the multi-step pipeline: LLM outputs structured JSON, not markup.
     */
    public function handle_structure(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $prompt = $request->get_param('prompt');
        $decomposition = $request->get_param('decomposition');
        $use_patterns = $request->get_param('use_patterns') ?? [];
        $model = $request->get_param('model');

        if (empty(trim($prompt))) {
            return new \WP_Error('taipb_empty_prompt', 'Prompt cannot be empty.', ['status' => 400]);
        }

        if (empty($decomposition['sections'])) {
            return new \WP_Error('taipb_no_sections', 'Decomposition must contain sections.', ['status' => 400]);
        }

        try {
            $store = Plugin::get_manifest_store();
            $manifest = $store->get();

            // Build the simplified structure prompt
            $prompt_builder = new StructurePromptBuilder($manifest);
            $system_prompt = $prompt_builder->build($prompt, $decomposition, $use_patterns);

            // Call LLM
            $client = LLMClientFactory::create($model);
            $api_response = $client->generate($system_prompt, $prompt);

            // Parse the JSON block tree from the response
            $block_tree = $this->parse_block_tree($api_response['content']);

            if (empty($block_tree['blocks'])) {
                return new \WP_Error(
                    'taipb_invalid_structure',
                    'LLM returned an invalid block tree. Please try again.',
                    ['status' => 422]
                );
            }

            return new \WP_REST_Response([
                'block_tree' => $block_tree,
                'api_response' => [
                    'model' => $api_response['model'],
                    'input_tokens' => $api_response['input_tokens'],
                    'output_tokens' => $api_response['output_tokens'],
                ],
            ], 200);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'rate limit') ? 429 : 500;
            $status = str_contains($e->getMessage(), 'API key') ? 401 : $status;

            return new \WP_Error('taipb_error', $e->getMessage(), ['status' => $status]);
        }
    }

    /**
     * POST /assemble — Convert a JSON block tree into valid block markup.
     * Step 3 of the multi-step pipeline: pure PHP, no LLM call.
     */
    public function handle_assemble(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $block_tree = $request->get_param('block_tree');
        $prompt = $request->get_param('prompt') ?? '';
        $post_id = $request->get_param('post_id');
        $post_type = $request->get_param('post_type') ?? 'page';

        if (empty($block_tree['blocks'])) {
            return new \WP_Error('taipb_empty_tree', 'Block tree must contain blocks.', ['status' => 400]);
        }

        try {
            $store = Plugin::get_manifest_store();
            $manifest = $store->get();

            // Assemble markup from block tree
            $assembler = new MarkupAssembler($manifest);
            $markup = $assembler->assemble($block_tree);

            // Validate the assembled markup
            $validator = new MarkupValidator($manifest);
            $validation = $validator->validate($markup);

            // Save to history if prompt is provided
            if (!empty($prompt)) {
                $this->save_assembly_history(
                    get_current_user_id(),
                    $prompt,
                    $markup,
                    $post_id,
                    $post_type,
                    $validation
                );
            }

            return new \WP_REST_Response([
                'markup' => $markup,
                'validation' => $validation,
            ], 200);
        } catch (\Throwable $e) {
            return new \WP_Error('taipb_assemble_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Parse a JSON block tree from LLM output, stripping markdown fences.
     */
    private function parse_block_tree(string $raw): array
    {
        $raw = preg_replace('/^```(?:json)?\s*\n?/i', '', trim($raw));
        $raw = preg_replace('/\n?```\s*$/', '', $raw);

        $parsed = json_decode(trim($raw), true);

        if (!is_array($parsed)) {
            return ['blocks' => []];
        }

        // Support both {"blocks": [...]} and bare [...]
        if (isset($parsed['blocks'])) {
            return $parsed;
        }

        if (isset($parsed[0])) {
            return ['blocks' => $parsed];
        }

        return ['blocks' => []];
    }

    /**
     * Save an assembly result to the history table.
     */
    private function save_assembly_history(int $user_id, string $prompt, string $markup, ?int $post_id, string $post_type, array $validation): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'taipb_history';

        $wpdb->insert($table_name, [
            'user_id' => $user_id,
            'prompt' => $prompt,
            'generated_markup' => $markup,
            'post_id' => $post_id,
            'post_type' => $post_type,
            'model' => 'assembled',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'validation_result' => wp_json_encode($validation),
            'created_at' => current_time('mysql', true),
        ], [
            '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s',
        ]);
    }

    /**
     * POST /generate-context — Analyze site content and generate custom system prompt text.
     */
    public function handle_generate_context(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $post_types = $request->get_param('post_types');
        if (empty($post_types)) {
            return new \WP_Error('taipb_no_post_types', 'Select at least one post type.', ['status' => 400]);
        }

        // Sanitize post type names
        $post_types = array_map('sanitize_key', $post_types);

        // Fetch sample content from each post type
        $samples = [];
        foreach ($post_types as $pt) {
            $pt_object = get_post_type_object($pt);
            if (!$pt_object || !$pt_object->public) {
                continue;
            }

            $posts = get_posts([
                'post_type' => $pt,
                'post_status' => 'publish',
                'posts_per_page' => 5,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

            foreach ($posts as $post) {
                $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
                if (strlen($content) > 500) {
                    $content = substr($content, 0, 500) . '...';
                }

                $samples[] = [
                    'type' => $pt_object->labels->singular_name,
                    'title' => $post->post_title,
                    'content' => $content,
                ];
            }
        }

        if (empty($samples)) {
            return new \WP_Error(
                'taipb_no_content',
                'No published content found in the selected post types.',
                ['status' => 404]
            );
        }

        // Build the user prompt with content samples and manifest context
        $store = Plugin::get_manifest_store();
        $manifest = $store->get();

        $user_prompt = "## SITE CONTENT SAMPLES\n\n";
        foreach ($samples as $sample) {
            $user_prompt .= "### {$sample['type']}: {$sample['title']}\n{$sample['content']}\n\n";
        }

        // Add available blocks and patterns
        $user_prompt .= "## AVAILABLE BLOCKS\n";
        foreach ($manifest['blocks'] ?? [] as $name => $block) {
            $user_prompt .= "- {$block['title']} ({$name})\n";
        }

        $user_prompt .= "\n## AVAILABLE PATTERNS\n";
        foreach ($manifest['patterns'] ?? [] as $pattern) {
            $user_prompt .= "- {$pattern['title']}\n";
        }
        foreach ($manifest['layouts'] ?? [] as $layout) {
            $user_prompt .= "- {$layout['name']} ({$layout['type']})\n";
        }

        $system = <<<'PROMPT'
You are a site analyst. Given sample content from a WordPress site and its available blocks/patterns, generate concise site-specific instructions for an AI layout generator.

Your output should include:
- A brief description of the site's purpose and target audience (inferred from content)
- Brand/product names and their accurate descriptions (extracted from content — do not invent)
- Tone and style guidelines (inferred from content voice)
- When the user prompt mentions specific products or brands found in the content, instruct the generator to prefer blocks and patterns whose name or title contains those terms
- Content rules (heading hierarchy, CTA language, any conventions observed)

Output plain text instructions (not JSON, not markdown fences). Write in imperative form suitable for appending to an AI system prompt.
Keep it under 500 words. Be specific and factual — only reference products, names, and features that actually appear in the content samples.
PROMPT;

        // Use cheap model first, same pattern as PromptDecomposer
        $settings = Plugin::get_settings();
        $has_anthropic = defined('TAIPB_API_KEY') || !empty($settings['api_key']);
        $has_openai = defined('TAIPB_OPENAI_API_KEY') || !empty($settings['openai_api_key']);

        $clients = [];
        if ($has_anthropic) {
            $clients[] = new ClaudeClient('claude-haiku-4-5-20251001');
        }
        if ($has_openai) {
            $clients[] = new OpenAIClient('gpt-4o-mini');
        }
        $clients[] = LLMClientFactory::create();

        $last_error = null;
        foreach ($clients as $client) {
            try {
                $response = $client->generate($system, $user_prompt);
                return new \WP_REST_Response([
                    'context' => $response['content'],
                ], 200);
            } catch (\Throwable $e) {
                $last_error = $e;
                continue;
            }
        }

        $message = 'Failed to generate context: ' . ($last_error?->getMessage() ?? 'no LLM clients available');
        $status = 500;
        if ($last_error && str_contains($last_error->getMessage(), 'API key')) {
            $status = 401;
        } elseif ($last_error && str_contains($last_error->getMessage(), 'credit balance')) {
            $status = 402;
        }

        return new \WP_Error('taipb_context_error', $message, ['status' => $status]);
    }

    /**
     * POST /generate-pattern-notes — Analyze a pattern's content and generate usage notes.
     */
    public function handle_generate_pattern_notes(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $post_id = $request->get_param('post_id');
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'wp_block') {
            return new \WP_Error('taipb_invalid_pattern', 'Pattern not found.', ['status' => 404]);
        }

        $content = $post->post_content;
        if (empty(trim($content))) {
            return new \WP_Error('taipb_empty_pattern', 'This pattern has no content to analyze.', ['status' => 400]);
        }

        // Extract block names and meaningful data from pattern markup
        $block_summary = [];
        preg_match_all(
            '/<!-- wp:(acf\/[a-z0-9-]+|[a-z0-9-]+\/[a-z0-9-]+|[a-z0-9-]+) (?:\{(.*?)\} )?(?:\/)?-->/s',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $block_name = $match[1];
            $json_data = isset($match[2]) ? json_decode('{' . $match[2] . '}', true) : null;
            if (!$json_data) {
                $json_data = isset($match[2]) ? json_decode($match[2], true) : null;
            }

            $entry = $block_name;
            if ($json_data) {
                $data = $json_data['data'] ?? $json_data;
                $meaningful = [];
                foreach ($data as $key => $value) {
                    if (str_starts_with($key, '_') || $value === '' || $value === null) {
                        continue;
                    }
                    if (in_array($key, ['mode', 'alignText', 'alignContent', 'name'], true)) {
                        continue;
                    }
                    if (is_string($value) && strlen($value) > 80) {
                        $value = substr($value, 0, 77) . '...';
                    }
                    if (!is_array($value)) {
                        $meaningful[$key] = $value;
                    }
                }
                if (!empty($meaningful)) {
                    $pairs = [];
                    foreach (array_slice($meaningful, 0, 5) as $k => $v) {
                        $pairs[] = "{$k}: {$v}";
                    }
                    $entry .= ' (' . implode(', ', $pairs) . ')';
                }
            }
            $block_summary[] = $entry;
        }

        // Also extract any visible text content
        $text_content = wp_strip_all_tags(do_blocks($content));
        if (strlen($text_content) > 500) {
            $text_content = substr($text_content, 0, 500) . '...';
        }

        // Get categories
        $categories = [];
        $terms = get_the_terms($post_id, 'wp_pattern_category');
        if ($terms && !is_wp_error($terms)) {
            $categories = wp_list_pluck($terms, 'name');
        }

        $user_prompt = "Pattern: {$post->post_title}\n";
        if (!empty($categories)) {
            $user_prompt .= "Categories: " . implode(', ', $categories) . "\n";
        }
        $user_prompt .= "\nBlocks used:\n- " . implode("\n- ", $block_summary) . "\n";
        if (!empty(trim($text_content))) {
            $user_prompt .= "\nVisible text content:\n{$text_content}\n";
        }

        $system = <<<'PROMPT'
You are a WordPress pattern analyst. Given information about a Gutenberg block pattern (its name, categories, blocks used, and visible text content), generate concise usage notes for an AI layout generator.

Your output should describe:
- What this pattern is designed for (e.g., "Hero section for product landing pages")
- When to use it (e.g., "Use at the top of marketing pages when a full-width hero with CTA is needed")
- Any important structural notes (e.g., "Contains a 3-column card grid — best for exactly 3 items")
- Content expectations (e.g., "Expects a headline, subheadline, and call-to-action button text")

Output 2-4 sentences of plain text instructions. Be specific and practical. Write in imperative form.
Do not describe the blocks themselves — focus on when and how to use this pattern as a whole.
PROMPT;

        // Use cheap model first
        $settings = Plugin::get_settings();
        $has_anthropic = defined('TAIPB_API_KEY') || !empty($settings['api_key']);
        $has_openai = defined('TAIPB_OPENAI_API_KEY') || !empty($settings['openai_api_key']);

        $clients = [];
        if ($has_anthropic) {
            $clients[] = new ClaudeClient('claude-haiku-4-5-20251001');
        }
        if ($has_openai) {
            $clients[] = new OpenAIClient('gpt-4o-mini');
        }
        $clients[] = LLMClientFactory::create();

        $last_error = null;
        foreach ($clients as $client) {
            try {
                $response = $client->generate($system, $user_prompt);
                return new \WP_REST_Response([
                    'notes' => trim($response['content']),
                ], 200);
            } catch (\Throwable $e) {
                $last_error = $e;
                continue;
            }
        }

        return new \WP_Error(
            'taipb_generate_notes_error',
            'Failed to generate notes: ' . ($last_error?->getMessage() ?? 'no LLM clients available'),
            ['status' => 500]
        );
    }

    /**
     * Permission: can edit posts.
     */
    public function can_edit(\WP_REST_Request $request): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Permission: can manage options (admin).
     */
    public function can_manage(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }
}
