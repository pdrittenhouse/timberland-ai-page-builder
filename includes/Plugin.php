<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks(): void
    {
        // Activation/deactivation
        register_activation_hook(TAIPB_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(TAIPB_PLUGIN_FILE, [$this, 'deactivate']);

        // Admin hooks
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Editor assets
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);

        // Pattern meta (usage notes on wp_block post type)
        add_action('init', [$this, 'register_pattern_meta']);
    }

    public function activate(): void
    {
        // Create history table
        $this->create_history_table();

        // Generate initial manifest
        $this->regenerate_manifest();
    }

    public function deactivate(): void
    {
        delete_transient('taipb_manifest_cache');
    }

    public function register_admin_menu(): void
    {
        add_options_page(
            'Timberland AI Page Builder',
            'AI Page Builder',
            'manage_options',
            'taipb-settings',
            [$this, 'render_settings_page']
        );

        // UC3's own screen. Separate from the settings page because ~48 blocks x 3 fields would
        // bury every other setting, and `manage_options` because this changes what the model is
        // told site-wide -- it is configuration, not content.
        add_submenu_page(
            'options-general.php',
            'AI Block Curation',
            'AI Block Curation',
            'manage_options',
            'taipb-curated',
            [$this, 'render_curated_page']
        );
    }

    /**
     * UC3's screen: every registered block, its shipped guidance, and this site's override.
     *
     * The block LIST comes from `WP_Block_Type_Registry`, not from the curation artifact. The
     * registry is the authority on what will actually render -- already resolved across framework,
     * plugin, parent and child, and already filtered by plugin activation -- and listing from the
     * artifact instead would hide exactly the blocks most worth curating: the ones with no
     * harvested documentation.
     */
    public function render_curated_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        if (isset($_POST['taipb_curated_nonce'])
            && wp_verify_nonce(sanitize_key(wp_unslash($_POST['taipb_curated_nonce'])), 'taipb_save_curated')
        ) {
            // `wp_unslash` before anything else: WordPress slashes $_POST, so an apostrophe in
            // guidance arrives as `\'` and would otherwise accumulate a backslash on every save.
            //
            // `map_deep` with `wp_kses_post` rather than `sanitize_text_field`: `configuration` is
            // multi-line prose and newlines are meaningful in it. `CuratedOverrides::save()`
            // sanitises again on the way in -- deliberately, since the option can also be written
            // by WP-CLI or a migration, neither of which comes through here.
            $posted = isset($_POST['taipb_curated'])
                ? map_deep(wp_unslash($_POST['taipb_curated']), 'wp_kses_post')
                : [];

            CuratedOverrides::save(is_array($posted) ? $posted : []);

            add_settings_error('taipb_curated', 'saved', 'Curation saved.', 'success');
        }

        settings_errors('taipb_curated');

        $curated = new CuratedContext();
        $shipped = $curated->shipped_index();
        $overrides = CuratedOverrides::stored();
        $blocks = [];

        foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type) {
            if (!str_starts_with((string) $name, 'acf/')) {
                continue;
            }

            $slug = substr((string) $name, 4);

            $blocks[$slug] = [
                'title' => (string) ($type->title ?: $slug),
                // The shipped default, NOT the merged value: the screen's whole job is to show
                // what a field falls back to, and showing the override as its own default would
                // make an override look like something the block ships with.
                'default' => [
                    'best_for' => (string) ($shipped[$slug]['best_for'] ?? ''),
                    'avoid_when' => (string) ($shipped[$slug]['avoid_when'] ?? ''),
                    'configuration' => $curated->shipped_configuration_for($slug),
                ],
                'override' => $overrides[$slug] ?? [],
            ];
        }

        ksort($blocks);

        $template = TAIPB_PLUGIN_DIR . 'templates/admin-curated.php';

        if (file_exists($template)) {
            include $template;
        }
    }

    public function register_settings(): void
    {
        register_setting('taipb_settings_group', 'taipb_settings', [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_settings'],
            'default' => self::get_default_settings(),
        ]);
    }

    public function register_rest_routes(): void
    {
        $controller = new RestController();
        $controller->register_routes();
    }

    public function enqueue_editor_assets(): void
    {
        // Check access
        if (!current_user_can('edit_posts')) {
            return;
        }

        $asset_file = TAIPB_PLUGIN_DIR . 'build/index.asset.php';
        if (!file_exists($asset_file)) {
            return;
        }

        $asset = require $asset_file;

        wp_enqueue_script(
            'timberland-ai-page-builder-editor',
            TAIPB_PLUGIN_URL . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        $css_file = TAIPB_PLUGIN_DIR . 'build/index.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'timberland-ai-page-builder-editor',
                TAIPB_PLUGIN_URL . 'build/index.css',
                [],
                $asset['version']
            );
        }

        wp_localize_script('timberland-ai-page-builder-editor', 'taipbSettings', [
            'restUrl' => rest_url('taipb/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'postType' => get_post_type(),
            'canManage' => current_user_can('manage_options'),
            'models' => self::get_available_models(),
            'defaultModel' => self::get_settings()['model'] ?? 'claude-sonnet-4-5-20250929',
        ]);
    }

    public function render_settings_page(): void
    {
        $template = TAIPB_PLUGIN_DIR . 'templates/admin-settings.php';
        if (file_exists($template)) {
            include $template;
        }
    }

    /**
     * Static because it uses no instance state, and because that is what makes it testable: the
     * only other way in is `instance()`, which runs `init_hooks()` and therefore needs WordPress
     * loaded -- which the unit suite asserts is NOT the case.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize_settings(array $input): array
    {
        $defaults = self::get_default_settings();
        $sanitized = [];

        // Credentials are encrypted at rest and never rendered back into the form, so the two
        // key fields need handling that the other settings do not -- see store_api_key().
        $stored = get_option('taipb_settings', []);
        $sanitized['api_key'] = self::store_api_key($input, $stored, 'api_key');
        $sanitized['openai_api_key'] = self::store_api_key($input, $stored, 'openai_api_key');

        /**
         * The Figma token is routed to its OWN option and never kept here.
         *
         * `FigmaCredentials` owns `taipb_figma_token`, so returning the value into
         * `taipb_settings` would store the credential twice -- and the copy in the settings array
         * would be the one nothing encrypts on read. One form, one save, one home.
         *
         * Same three cases as an API key: blank means unchanged (the form never renders the
         * stored value, so an untouched field submits nothing), the sentinel clears it, anything
         * else is a new token.
         */
        if (array_key_exists('figma_token', $input)) {
            $submitted = trim(sanitize_text_field((string) $input['figma_token']));

            if ($submitted === self::CLEAR_SENTINEL) {
                FigmaCredentials::store('');
            } elseif ($submitted !== '') {
                $result = FigmaCredentials::store($submitted);

                if (is_wp_error($result)) {
                    add_settings_error(
                        'taipb_settings',
                        'figma_token',
                        $result->get_error_message(),
                        'error'
                    );
                }
            }
        }
        $sanitized['model'] = sanitize_text_field($input['model'] ?? $defaults['model']);
        $sanitized['max_tokens'] = absint($input['max_tokens'] ?? $defaults['max_tokens']);
        $sanitized['rate_limit_per_hour'] = absint($input['rate_limit_per_hour'] ?? $defaults['rate_limit_per_hour']);
        $sanitized['rate_limit_per_day'] = absint($input['rate_limit_per_day'] ?? $defaults['rate_limit_per_day']);

        // Both of these shipped with a default, a careful docblock describing them as operator
        // overrides, and a reader in PromptBudget / MarkupGenerator -- and no way to set either.
        // There was no field on the settings screen, and this method dropped the key even if one
        // had been posted, so the documented override silently did nothing.
        $sanitized['token_budget'] = absint($input['token_budget'] ?? $defaults['token_budget']);

        // Whitelisted rather than passed through: an unrecognised value would fall through
        // MarkupGenerator's `=== 'tools'` check into the inline path, which is the slower, worse
        // one. A typo must not silently downgrade generation quality.
        $mode = (string) ($input['context_mode'] ?? $defaults['context_mode']);
        $sanitized['context_mode'] = in_array($mode, ['tools', 'inline'], true) ? $mode : 'tools';
        $sanitized['allowed_roles'] = array_map('sanitize_text_field', $input['allowed_roles'] ?? $defaults['allowed_roles']);
        // Listed here deliberately. `token_budget` shipped with a default, a docblock and a
        // reader, and no way to set it -- there was no field AND this method dropped the key, so
        // the documented override did nothing. A checkbox absent from the POST is `false`, which
        // is why this reads `!empty()` rather than defaulting to the stored value.
        $sanitized['expect_post_publish_checks'] = !empty($input['expect_post_publish_checks']);
        $sanitized['include_genesis_layouts'] = !empty($input['include_genesis_layouts']);
        $sanitized['include_editor_patterns'] = !empty($input['include_editor_patterns']);
        $sanitized['custom_system_prompt'] = wp_kses_post($input['custom_system_prompt'] ?? $defaults['custom_system_prompt']);

        return $sanitized;
    }

    /**
     * Resolve one API-key field on save.
     *
     * Three cases, and getting the middle one wrong is the classic WordPress settings bug:
     *
     *  - **field absent or empty** -- the key is unchanged. The form no longer renders the
     *    existing value (it was going out in the HTML of every settings page), so an untouched
     *    field submits nothing. Treating that as "clear the key" would wipe a working credential
     *    every time an operator changed the model dropdown.
     *  - **field is the clear sentinel** -- the key is deliberately removed. Without an explicit
     *    way to clear, the first rule makes a key permanent.
     *  - **anything else** -- a new key, stored encrypted.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|mixed $stored
     */
    private static function store_api_key(array $input, $stored, string $field): string
    {
        $existing = is_array($stored) ? (string) ($stored[$field] ?? '') : '';
        $submitted = isset($input[$field]) ? trim(sanitize_text_field((string) $input[$field])) : '';

        if ($submitted === self::CLEAR_SENTINEL) {
            return '';
        }

        if ($submitted === '') {
            // MIGRATION. An untouched field preserves the stored key -- but if that key predates
            // encryption it must be encrypted NOW, not handed back verbatim.
            //
            // The first version returned `$existing` unchanged, so saving settings never migrated
            // anything and a plaintext key stayed plaintext for ever while the commit message
            // claimed otherwise. Found because the Figma token encrypted on save and the two LLM
            // keys did not, on an install whose salts are demonstrably fine.
            if ($existing !== '' && !SecretStore::is_encrypted($existing)) {
                return SecretStore::encrypt($existing) ?? $existing;
            }

            return $existing;
        }

        // A re-submitted ciphertext means the form posted back what it was given. It should not
        // be possible now, but double-encrypting would silently destroy the key.
        if (SecretStore::is_encrypted($submitted)) {
            return $existing;
        }

        $encrypted = SecretStore::encrypt($submitted);

        // No silent downgrade: storing the raw key while the UI says "encrypted" would be the
        // worst outcome. Kept in plaintext ONLY when this install cannot encrypt at all, and
        // settings_status() reports that so it is visible rather than assumed.
        return $encrypted ?? $submitted;
    }

    public static function get_default_settings(): array
    {
        return [
            'api_key' => '',
            'openai_api_key' => '',
            'model' => 'claude-sonnet-4-5-20250929',
            'max_tokens' => 8192,
            'rate_limit_per_hour' => 20,
            'rate_limit_per_day' => 100,
            'allowed_roles' => ['administrator', 'editor'],
            'include_genesis_layouts' => true,
            'include_editor_patterns' => true,
            'custom_system_prompt' => '',
            // 0 means "use PromptBudget::DEFAULT_CEILING". Raise it when a page legitimately
            // needs more component context than the cost model's 8,000-token target; the budget
            // degrades what the model is shown, it never refuses to generate.
            'token_budget' => 0,
            // VP4. Off by default because the checks need a browser this plugin never launches:
            // turning it on asserts that someone runs `gate:generated --queue` for this site. The
            // editor reads it to tell "no report yet, one is coming" apart from "nobody is
            // checking", which are the same absence of data and completely different situations.
            'expect_post_publish_checks' => false,
            // 'tools' lets the model fetch field detail for the blocks it chooses; 'inline'
            // is the previous behaviour, which guesses by keyword and inlines every field.
            // A toggle rather than a constant because the OpenAI tool path is not yet verified
            // against a live account -- falling back must not need a code change.
            'context_mode' => 'tools',
        ];
    }

    /** Typed into a key field to remove a stored credential. */
    public const CLEAR_SENTINEL = 'remove';

    public static function get_settings(): array
    {
        $settings = get_option('taipb_settings', []);
        $settings = wp_parse_args($settings, self::get_default_settings());

        // Decrypted on read so every existing caller -- ClaudeClient, OpenAIClient, the REST
        // controller's `!empty()` checks -- keeps working unchanged. `reveal()` passes an
        // unmarked value straight through, so an install that predates encryption keeps running
        // and is re-encrypted the next time settings are saved.
        foreach (['api_key', 'openai_api_key'] as $field) {
            $settings[$field] = SecretStore::reveal((string) ($settings[$field] ?? ''));
        }

        return $settings;
    }

    /**
     * Whether each credential is configured, WITHOUT returning it.
     *
     * What a settings screen calls. Rendering a key into a form field put it in the HTML of every
     * settings page response -- browser cache, the back button, and any screenshot of the screen.
     *
     * @return array<string, array{configured: bool, source: string, encrypted: bool}>
     */
    public static function credential_status(): array
    {
        $raw = get_option('taipb_settings', []);
        $raw = is_array($raw) ? $raw : [];

        $status = [];

        foreach (['api_key' => 'TAIPB_API_KEY', 'openai_api_key' => 'TAIPB_OPENAI_API_KEY'] as $field => $constant) {
            $stored = (string) ($raw[$field] ?? '');

            $status[$field] = [
                'configured' => defined($constant) || $stored !== '',
                'source' => defined($constant) ? 'constant' : 'option',
                // Reported, not assumed: an install with unusable salts stores plaintext, and an
                // operator is entitled to know which of the two they have.
                'encrypted' => $stored !== '' && SecretStore::is_encrypted($stored),
            ];
        }

        return $status;
    }

    /**
     * Get available LLM models based on configured API keys.
     *
     * @return array<array{value: string, label: string, provider: string}>
     */
    public static function get_available_models(): array
    {
        $settings = self::get_settings();
        $models = [];

        $has_anthropic = defined('TAIPB_API_KEY') || !empty($settings['api_key']);
        if ($has_anthropic) {
            $models[] = ['value' => 'claude-sonnet-4-5-20250929', 'label' => 'Claude Sonnet 4.5', 'provider' => 'anthropic'];
            $models[] = ['value' => 'claude-opus-4-6', 'label' => 'Claude Opus 4.6', 'provider' => 'anthropic'];
        }

        $has_openai = defined('TAIPB_OPENAI_API_KEY') || !empty($settings['openai_api_key']);
        if ($has_openai) {
            $models[] = ['value' => 'gpt-4o', 'label' => 'GPT-4o', 'provider' => 'openai'];
            $models[] = ['value' => 'gpt-4.1', 'label' => 'GPT-4.1', 'provider' => 'openai'];
        }

        return $models;
    }

    public function register_pattern_meta(): void
    {
        if (!post_type_supports('wp_block', 'custom-fields')) {
            add_post_type_support('wp_block', 'custom-fields');
        }

        register_post_meta('wp_block', 'taipb_usage_notes', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_textarea_field',
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);
    }

    private function create_history_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'taipb_history';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            prompt TEXT NOT NULL,
            generated_markup LONGTEXT NOT NULL,
            post_id BIGINT UNSIGNED DEFAULT NULL,
            post_type VARCHAR(50) DEFAULT NULL,
            model VARCHAR(100) NOT NULL,
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            validation_result TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Handle admin form actions (e.g., manifest regeneration).
     */
    public function handle_admin_actions(): void
    {
        if (
            isset($_POST['taipb_regenerate_manifest'])
            && current_user_can('manage_options')
            && check_admin_referer('taipb_regenerate_manifest')
        ) {
            $this->regenerate_manifest();
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>Manifest regenerated successfully.</p></div>';
            });
        }

    }

    /**
     * Get the ManifestStore instance.
     */
    public static function get_manifest_store(): ManifestStore
    {
        $field_key_map = new FieldKeyMap();
        $builder = new ManifestBuilder($field_key_map);
        return new ManifestStore($builder);
    }

    /**
     * The schema oracle used to validate generated output.
     *
     * Built here rather than inside {@see MarkupValidator} so that every construction site shares
     * one instance and therefore one schema cache -- the resolved contract is 1.7 MB and the
     * per-block baselines are larger still, so re-reading them per validation would be felt.
     *
     * This existing at all is the point: the oracle was written, tested and then constructed
     * NOWHERE, so schema validation never ran on a real request. All three
     * `new MarkupValidator(...)` sites now pass it.
     */
    public static function get_schema_oracle(): SchemaOracle
    {
        static $oracle = null;

        return $oracle ??= new SchemaOracle();
    }

    private function regenerate_manifest(): void
    {
        $store = self::get_manifest_store();
        $store->regenerate();
    }
}
