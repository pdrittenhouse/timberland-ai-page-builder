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
    }

    public function register_settings(): void
    {
        register_setting('taipb_settings_group', 'taipb_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
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

    public function sanitize_settings(array $input): array
    {
        $defaults = self::get_default_settings();
        $sanitized = [];

        $sanitized['api_key'] = sanitize_text_field($input['api_key'] ?? $defaults['api_key']);
        $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? $defaults['openai_api_key']);
        $sanitized['model'] = sanitize_text_field($input['model'] ?? $defaults['model']);
        $sanitized['max_tokens'] = absint($input['max_tokens'] ?? $defaults['max_tokens']);
        $sanitized['rate_limit_per_hour'] = absint($input['rate_limit_per_hour'] ?? $defaults['rate_limit_per_hour']);
        $sanitized['rate_limit_per_day'] = absint($input['rate_limit_per_day'] ?? $defaults['rate_limit_per_day']);
        $sanitized['allowed_roles'] = array_map('sanitize_text_field', $input['allowed_roles'] ?? $defaults['allowed_roles']);
        $sanitized['include_genesis_layouts'] = !empty($input['include_genesis_layouts']);
        $sanitized['include_editor_patterns'] = !empty($input['include_editor_patterns']);
        $sanitized['custom_system_prompt'] = wp_kses_post($input['custom_system_prompt'] ?? $defaults['custom_system_prompt']);

        return $sanitized;
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
        ];
    }

    public static function get_settings(): array
    {
        $settings = get_option('taipb_settings', []);
        return wp_parse_args($settings, self::get_default_settings());
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

    private function regenerate_manifest(): void
    {
        $store = self::get_manifest_store();
        $store->regenerate();
    }
}
