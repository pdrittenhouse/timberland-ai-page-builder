<?php

if (!defined('ABSPATH')) {
    exit;
}

$settings = TimberlandAIPageBuilder\Plugin::get_settings();

// Configured-or-not, WITHOUT the credential. `get_settings()` decrypts, so the keys would
// otherwise sit in this template's scope for the whole render -- one stray `print_r($settings)`
// in a debug session away from the page that renders them. They are not needed here at all.
$credential_status = TimberlandAIPageBuilder\Plugin::credential_status();
$figma_status = TimberlandAIPageBuilder\FigmaCredentials::status();
$has_api_key = $credential_status['api_key']['configured'];
$has_openai_key = $credential_status['openai_api_key']['configured'];
unset($settings['api_key'], $settings['openai_api_key']);
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
                            <?php $st = $credential_status['api_key']; ?>
                            <?php if ($st['configured']): ?>
                                <p class="description">
                                    <strong>A key is saved.</strong>
                                    <?php if (!$st['encrypted']): ?>
                                        <?php
                                        /*
                                         * `encrypted: false` has TWO causes and the first version
                                         * asserted the alarming one without checking: it told
                                         * every operator their salts were unusable, including on
                                         * installs where encryption demonstrably worked. Say which
                                         * one it actually is.
                                         */
                                        ?>
                                        <?php if (\TimberlandAIPageBuilder\SecretStore::is_available()): ?>
                                            <span style="color:#996800;">Not yet encrypted &mdash; saved before
                                            encryption was added. Saving this page once will encrypt it.</span>
                                        <?php else: ?>
                                            <span style="color:#b32d2e;">Cannot be encrypted &mdash; this site has no
                                            usable WordPress salts. Generate them in <code>wp-config.php</code>, then
                                            re-save.</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <input type="password"
                                   name="taipb_settings[api_key]"
                                   value=""
                                   class="regular-text"
                                   autocomplete="off"
                                   placeholder="<?php echo $st['configured'] ? esc_attr__('Leave blank to keep the saved key', 'taipb') : ''; ?>" />
                            <p class="description">Your Anthropic API key. Alternatively, define <code>TAIPB_API_KEY</code> in <code>wp-config.php</code>.
                                <?php if ($st['configured']): ?>
                                    Leave blank to keep the saved key, or type
                                    <code><?php echo esc_html(\TimberlandAIPageBuilder\Plugin::CLEAR_SENTINEL); ?></code>
                                    to remove it.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">OpenAI API Key</th>
                    <td>
                        <?php if (defined('TAIPB_OPENAI_API_KEY')): ?>
                            <p class="description">API key is defined in <code>wp-config.php</code> via <code>TAIPB_OPENAI_API_KEY</code> constant.</p>
                        <?php else: ?>
                            <?php $st = $credential_status['openai_api_key']; ?>
                            <?php if ($st['configured']): ?>
                                <p class="description">
                                    <strong>A key is saved.</strong>
                                    <?php if (!$st['encrypted']): ?>
                                        <?php
                                        /*
                                         * `encrypted: false` has TWO causes and the first version
                                         * asserted the alarming one without checking: it told
                                         * every operator their salts were unusable, including on
                                         * installs where encryption demonstrably worked. Say which
                                         * one it actually is.
                                         */
                                        ?>
                                        <?php if (\TimberlandAIPageBuilder\SecretStore::is_available()): ?>
                                            <span style="color:#996800;">Not yet encrypted &mdash; saved before
                                            encryption was added. Saving this page once will encrypt it.</span>
                                        <?php else: ?>
                                            <span style="color:#b32d2e;">Cannot be encrypted &mdash; this site has no
                                            usable WordPress salts. Generate them in <code>wp-config.php</code>, then
                                            re-save.</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <input type="password"
                                   name="taipb_settings[openai_api_key]"
                                   value=""
                                   class="regular-text"
                                   autocomplete="off"
                                   placeholder="<?php echo $st['configured'] ? esc_attr__('Leave blank to keep the saved key', 'taipb') : ''; ?>" />
                            <p class="description">Your OpenAI API key (optional). Alternatively, define <code>TAIPB_OPENAI_API_KEY</code> in <code>wp-config.php</code>.
                                <?php if ($st['configured']): ?>
                                    Leave blank to keep the saved key, or type
                                    <code><?php echo esc_html(\TimberlandAIPageBuilder\Plugin::CLEAR_SENTINEL); ?></code>
                                    to remove it.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Figma Access Token</th>
                    <td>
                        <?php if ('constant' === $figma_status['source']): ?>
                            <p class="description">Token is defined in <code>wp-config.php</code> via <code>TAIPB_FIGMA_TOKEN</code> constant.</p>
                        <?php else: ?>
                            <?php if ($figma_status['configured']): ?>
                                <p class="description"><strong>A token is saved.</strong></p>
                            <?php endif; ?>
                            <input type="password"
                                   name="taipb_settings[figma_token]"
                                   value=""
                                   class="regular-text"
                                   autocomplete="off"
                                   placeholder="<?php echo $figma_status['configured'] ? esc_attr__('Leave blank to keep the saved token', 'taipb') : ''; ?>" />
                            <?php
                            /*
                             * FG2: the consent notice is shown HERE, where the token is entered,
                             * not buried in documentation. A Figma PAT is account-wide -- it
                             * reads every file the issuing account can see -- and that is the
                             * one thing someone pasting it needs to know before they do.
                             */
                            ?>
                            <p class="description" style="max-width:46em;border-left:3px solid #d63638;padding-left:.75em;">
                                <?php echo esc_html(\TimberlandAIPageBuilder\FigmaCredentials::CONSENT_NOTICE); ?>
                            </p>
                            <p class="description">Stored encrypted. Alternatively, define <code>TAIPB_FIGMA_TOKEN</code> in <code>wp-config.php</code>.
                                <?php if ($figma_status['configured']): ?>
                                    Leave blank to keep the saved token, or type
                                    <code><?php echo esc_html(\TimberlandAIPageBuilder\Plugin::CLEAR_SENTINEL); ?></code>
                                    to remove it.
                                <?php endif; ?>
                            </p>
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
                    <th scope="row"><label for="taipb-max-tokens">Max Output Tokens</label></th>
                    <td>
                        <input type="number"
                               id="taipb-max-tokens"
                               name="taipb_settings[max_tokens]"
                               value="<?php echo esc_attr((string) $settings['max_tokens']); ?>"
                               min="1024"
                               max="32768"
                               step="1024" />
                        <p class="description">
                            The cap on what the model <strong>writes</strong> &mdash; sent to the
                            provider as <code>max_tokens</code>. Not related to the Component
                            Context Budget below, which governs how much it is <em>shown</em>.
                            <br>
                            Running out here <strong>truncates the block tree mid-JSON</strong>, and
                            the generation fails with &ldquo;ran out of output budget&rdquo;.
                            Retrying will not help: the same page produces the same length. Raise
                            this instead.
                            <br>
                            Measured on this install: a six-section page design writes about
                            <strong>1,300</strong> tokens, so the default 8,192 leaves roughly 85%
                            headroom. Long pages and deeply nested layouts are what consume it.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="taipb-token-budget">Component Context Budget</label></th>
                    <td>
                        <input type="number"
                               id="taipb-token-budget"
                               name="taipb_settings[token_budget]"
                               value="<?php echo esc_attr((string) ($settings['token_budget'] ?? 0)); ?>"
                               min="0"
                               max="200000"
                               step="500" />
                        <p class="description">
                            Tokens allowed for block/component context in one generation.
                            <strong>0 uses the built-in default of
                            <?php echo esc_html((string) \TimberlandAIPageBuilder\PromptBudget::DEFAULT_CEILING); ?>.</strong>
                            This is not a cap on the whole request &mdash; it governs how much field
                            detail the model is shown, and going over degrades that detail rather
                            than refusing to generate.
                            <br>
                            <?php
                            // Measured, because "8,000" means nothing without knowing what a page
                            // actually costs: the five Natural Rose page designs each spend
                            // 7,517-7,685 of it, which is under one block's worth of headroom.
                            ?>
                            Measured on this install: a six-section page spends roughly
                            7,500&ndash;7,700 &mdash; about 4,100 for the block catalogue plus ~440
                            per block of field detail. Raise this if pages come out using fewer
                            block types than the design calls for.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Block Context Mode</th>
                    <td>
                        <?php $mode = $settings['context_mode'] ?? 'tools'; ?>
                        <label>
                            <input type="radio" name="taipb_settings[context_mode]" value="tools"
                                   <?php checked('tools', $mode); ?> />
                            <strong>Tools</strong> &mdash; the model sees every block by name and
                            fetches field detail only for the ones it picks.
                        </label>
                        <br>
                        <label>
                            <input type="radio" name="taipb_settings[context_mode]" value="inline"
                                   <?php checked('inline', $mode); ?> />
                            <strong>Inline</strong> &mdash; guess which blocks are relevant by
                            keyword and inline all of their fields.
                        </label>
                        <p class="description">
                            Leave on <strong>Tools</strong>. Inline is kept only as a fallback:
                            measured against this manifest it showed the model 6 blocks of 48 with
                            no content block among them, and the resulting page failed validation on
                            40 invented field names.
                        </p>
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
                <tr>
                    <th scope="row">Post-Publish Checks</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="taipb_settings[expect_post_publish_checks]"
                                   value="1"
                                   <?php checked($settings['expect_post_publish_checks'] ?? false); ?> />
                            Expect post-publish checks on this site
                        </label>
                        <p class="description">
                            The post-publish checks (render-blocking CSS, deferred-CSS coverage,
                            accessibility, stylesheet load order, CSS budget) need a real browser,
                            which this plugin never launches. Instead it lists the published pages
                            that owe a check and accepts the results, and the test suite runs them:
                            <code>npm run gate:generated -- --queue</code>.
                            <br><br>
                            Tick this if someone actually runs that for this site. It changes
                            nothing about generation &mdash; it only lets the editor tell
                            <em>&ldquo;no report yet, one is coming&rdquo;</em> apart from
                            <em>&ldquo;nobody is checking&rdquo;</em>, which look identical
                            otherwise. The suite authenticates with an application password held
                            wherever it runs; none is stored here.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Custom System Prompt</th>
                    <td>
                        <textarea name="taipb_settings[custom_system_prompt]"
                                  id="taipb-custom-system-prompt"
                                  rows="6"
                                  class="large-text"><?php echo esc_textarea($settings['custom_system_prompt'] ?? ''); ?></textarea>
                        <p class="description">Additional instructions appended to all LLM prompts. Use this to provide site-specific context like brand guidelines, preferred blocks, tone, or content rules.</p>
                        <div id="taipb-context-generator" style="margin-top: 12px;">
                            <p><strong>Auto-generate from site content:</strong></p>
                            <fieldset id="taipb-context-post-types" style="margin: 8px 0;">
                                <?php
                                $manifest = $manifest_store->get();
                                foreach ($manifest['post_types'] ?? [] as $pt):
                                ?>
                                    <label style="display: inline-block; margin-right: 12px;">
                                        <input type="checkbox" class="taipb-context-pt"
                                               value="<?php echo esc_attr($pt['name']); ?>"
                                               <?php echo in_array($pt['name'], ['page', 'post'], true) ? 'checked' : ''; ?> />
                                        <?php echo esc_html($pt['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <button type="button" id="taipb-generate-context-btn" class="button button-secondary">
                                Generate Context
                            </button>
                            <span id="taipb-context-status" style="margin-left: 8px; font-style: italic;"></span>
                        </div>
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

    // Auto-generate context from site content
    (function() {
        var btn = document.getElementById('taipb-generate-context-btn');
        var status = document.getElementById('taipb-context-status');
        var textarea = document.getElementById('taipb-custom-system-prompt');
        var restUrl = <?php echo wp_json_encode(rest_url('taipb/v1/')); ?>;
        var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

        btn.addEventListener('click', function() {
            var checked = document.querySelectorAll('.taipb-context-pt:checked');
            var postTypes = Array.from(checked).map(function(cb) { return cb.value; });

            if (postTypes.length === 0) {
                status.textContent = 'Select at least one post type.';
                status.style.color = '#cc1818';
                return;
            }

            btn.disabled = true;
            status.textContent = 'Analyzing site content...';
            status.style.color = '#757575';

            fetch(restUrl + 'generate-context', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({ post_types: postTypes })
            })
            .then(function(response) {
                return response.json().then(function(data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function(result) {
                btn.disabled = false;
                if (result.ok && result.data.context) {
                    textarea.value = result.data.context;
                    status.textContent = 'Done — review and save settings.';
                    status.style.color = 'green';
                } else {
                    var msg = (result.data && result.data.message) || 'An error occurred.';
                    status.textContent = msg;
                    status.style.color = '#cc1818';
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                status.textContent = 'Network error: ' + err.message;
                status.style.color = '#cc1818';
            });
        });
    })();
</script>
