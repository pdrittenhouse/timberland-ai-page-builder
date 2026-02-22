<?php

if (!defined('ABSPATH')) {
    exit;
}

$settings = TimberlandAIPageBuilder\Plugin::get_settings();
$has_api_key = defined('TAIPB_API_KEY') || !empty($settings['api_key']);
$has_openai_key = defined('TAIPB_OPENAI_API_KEY') || !empty($settings['openai_api_key'] ?? '');
$manifest_store = TimberlandAIPageBuilder\Plugin::get_manifest_store();
$stats = $manifest_store->get_stats();
?>

<div class="wrap">
    <h1>Timberland AI Page Builder</h1>

    <form method="post" action="options.php">
        <?php settings_fields('taipb_settings_group'); ?>

        <h2 class="nav-tab-wrapper">
            <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
            <a href="#diagnostics" class="nav-tab">Diagnostics</a>
        </h2>

        <div id="settings-tab">
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <?php if (defined('TAIPB_API_KEY')): ?>
                            <p class="description">API key is defined in <code>wp-config.php</code> via <code>TAIPB_API_KEY</code> constant.</p>
                        <?php else: ?>
                            <input type="password"
                                   name="taipb_settings[api_key]"
                                   value="<?php echo esc_attr($settings['api_key']); ?>"
                                   class="regular-text"
                                   autocomplete="off" />
                            <p class="description">Your Anthropic API key. Alternatively, define <code>TAIPB_API_KEY</code> in <code>wp-config.php</code>.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">OpenAI API Key</th>
                    <td>
                        <?php if (defined('TAIPB_OPENAI_API_KEY')): ?>
                            <p class="description">API key is defined in <code>wp-config.php</code> via <code>TAIPB_OPENAI_API_KEY</code> constant.</p>
                        <?php else: ?>
                            <input type="password"
                                   name="taipb_settings[openai_api_key]"
                                   value="<?php echo esc_attr($settings['openai_api_key'] ?? ''); ?>"
                                   class="regular-text"
                                   autocomplete="off" />
                            <p class="description">Your OpenAI API key (optional). Alternatively, define <code>TAIPB_OPENAI_API_KEY</code> in <code>wp-config.php</code>.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Default Model</th>
                    <td>
                        <select name="taipb_settings[model]">
                            <optgroup label="Anthropic">
                                <option value="claude-sonnet-4-5-20250929" <?php selected($settings['model'], 'claude-sonnet-4-5-20250929'); ?>>Claude Sonnet 4.5</option>
                                <option value="claude-opus-4-6" <?php selected($settings['model'], 'claude-opus-4-6'); ?>>Claude Opus 4.6</option>
                            </optgroup>
                            <?php if ($has_openai_key): ?>
                            <optgroup label="OpenAI">
                                <option value="gpt-4o" <?php selected($settings['model'], 'gpt-4o'); ?>>GPT-4o</option>
                                <option value="gpt-4.1" <?php selected($settings['model'], 'gpt-4.1'); ?>>GPT-4.1</option>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <p class="description">Default model for generation. Can be overridden per-generation from the editor sidebar.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Max Output Tokens</th>
                    <td>
                        <input type="number"
                               name="taipb_settings[max_tokens]"
                               value="<?php echo esc_attr($settings['max_tokens']); ?>"
                               min="1024"
                               max="32768"
                               step="1024" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Rate Limit (per hour)</th>
                    <td>
                        <input type="number"
                               name="taipb_settings[rate_limit_per_hour]"
                               value="<?php echo esc_attr($settings['rate_limit_per_hour']); ?>"
                               min="1"
                               max="100" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Rate Limit (per day)</th>
                    <td>
                        <input type="number"
                               name="taipb_settings[rate_limit_per_day]"
                               value="<?php echo esc_attr($settings['rate_limit_per_day']); ?>"
                               min="1"
                               max="1000" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Include Genesis Layouts</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="taipb_settings[include_genesis_layouts]"
                                   value="1"
                                   <?php checked($settings['include_genesis_layouts']); ?> />
                            Include Genesis Block layouts in manifest
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Include Editor Patterns</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="taipb_settings[include_editor_patterns]"
                                   value="1"
                                   <?php checked($settings['include_editor_patterns']); ?> />
                            Include saved editor patterns in manifest
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </div>
    </form>

    <div id="diagnostics-tab" style="display:none;">
            <h3>Manifest</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Status</th>
                    <td>
                        <?php if ($stats['block_count'] > 0): ?>
                            <span style="color: green;">&#10003; Generated</span>
                        <?php else: ?>
                            <span style="color: orange;">&#9888; Not yet generated</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Generated At</th>
                    <td><?php echo esc_html($stats['generated_at']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Blocks</th>
                    <td><?php echo esc_html($stats['block_count']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Layouts</th>
                    <td><?php echo esc_html($stats['layout_count']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Patterns</th>
                    <td><?php echo esc_html($stats['pattern_count']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Post Types</th>
                    <td><?php echo esc_html($stats['post_type_count']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Taxonomies</th>
                    <td><?php echo esc_html($stats['taxonomy_count']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Field Key Mappings</th>
                    <td><?php echo esc_html($stats['total_field_mappings']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Manifest Version</th>
                    <td><?php echo esc_html($stats['version']); ?></td>
                </tr>
            </table>

            <form method="post" style="margin-top: 10px;">
                <?php wp_nonce_field('taipb_regenerate_manifest'); ?>
                <button type="submit" name="taipb_regenerate_manifest" value="1" class="button button-secondary">
                    Regenerate Manifest
                </button>
                <p class="description">Rebuilds the manifest from the current site environment (blocks, field groups, layouts, patterns, post types).</p>
            </form>

            <h3 style="margin-top: 30px;">Environment</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Anthropic API Key</th>
                    <td>
                        <?php if ($has_api_key): ?>
                            <span style="color: green;">&#10003; Configured</span>
                        <?php else: ?>
                            <span style="color: red;">&#10007; Not configured</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">OpenAI API Key</th>
                    <td>
                        <?php if ($has_openai_key): ?>
                            <span style="color: green;">&#10003; Configured</span>
                        <?php else: ?>
                            <span style="color: #757575;">&#8212; Not configured (optional)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">PHP Version</th>
                    <td><?php echo PHP_VERSION; ?> <?php echo version_compare(PHP_VERSION, '8.1', '>=') ? '&#10003;' : '&#10007; Requires 8.1+'; ?></td>
                </tr>
                <tr>
                    <th scope="row">Composer Autoloader</th>
                    <td>
                        <?php echo file_exists(TAIPB_PLUGIN_DIR . 'vendor/autoload.php') ? '<span style="color: green;">&#10003; Loaded</span>' : '<span style="color: orange;">&#9888; Not installed — run <code>composer install</code></span>'; ?>
                    </td>
                </tr>
            </table>
    </div>
</div>

<script>
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
            this.classList.add('nav-tab-active');
            document.getElementById('settings-tab').style.display = this.getAttribute('href') === '#settings' ? '' : 'none';
            document.getElementById('diagnostics-tab').style.display = this.getAttribute('href') === '#diagnostics' ? '' : 'none';
        });
    });
</script>
