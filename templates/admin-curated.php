<?php
/**
 * UC3 — the per-site curation screen.
 *
 * Its own page rather than a section of the settings screen: with ~48 blocks and three fields
 * each, folding this into Theme General Options would bury every other setting.
 *
 * **The shipped default is shown next to every field.** Without it an operator cannot tell an
 * empty box that inherits good guidance from an empty box for a block nobody has documented, and
 * those call for opposite actions. The default is also what an empty field falls back to, so
 * showing it is showing what is in force.
 *
 * @var array<string, array{title: string, default: array<string, string>, override: array<string, string>}> $blocks
 */

if (!defined('ABSPATH')) {
    exit;
}

$fields = [
    'best_for' => ['label' => 'Best for', 'rows' => 2],
    'avoid_when' => ['label' => 'Avoid when', 'rows' => 2],
    'configuration' => ['label' => 'How to configure', 'rows' => 5],
];
?>
<div class="wrap">
    <h1>AI Block Curation</h1>

    <p class="description" style="max-width:48em">
        Guidance the model is given when choosing and configuring blocks on this site. Each block
        ships a default from its own documentation; anything written here <strong>overrides that
        one field</strong> and leaves the others alone. <strong>An empty field inherits</strong> —
        it does not blank the shipped default. To suppress guidance, replace it with your own
        rather than clearing it.
    </p>

    <form method="post" action="">
        <?php wp_nonce_field('taipb_save_curated', 'taipb_curated_nonce'); ?>

        <?php foreach ($blocks as $slug => $block) : ?>
            <?php
            $has_override = array_filter($block['override'], static fn($v): bool => '' !== trim((string) $v));
            ?>
            <div class="postbox" style="margin-top:1em">
                <div class="postbox-header">
                    <h2 class="hndle" style="padding:.75em 1em">
                        <?php echo esc_html($block['title']); ?>
                        <code style="font-weight:normal"><?php echo esc_html($slug); ?></code>
                        <?php if ($has_override) : ?>
                            <span class="dashicons dashicons-edit" title="This site overrides the shipped guidance"></span>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="inside">
                    <table class="form-table" role="presentation">
                        <?php foreach ($fields as $field => $meta) : ?>
                            <?php $default = trim((string) ($block['default'][$field] ?? '')); ?>
                            <tr>
                                <th scope="row">
                                    <label for="taipb-<?php echo esc_attr($slug . '-' . $field); ?>">
                                        <?php echo esc_html($meta['label']); ?>
                                    </label>
                                </th>
                                <td>
                                    <textarea
                                        id="taipb-<?php echo esc_attr($slug . '-' . $field); ?>"
                                        name="taipb_curated[<?php echo esc_attr($slug); ?>][<?php echo esc_attr($field); ?>]"
                                        rows="<?php echo esc_attr((string) $meta['rows']); ?>"
                                        class="large-text code"
                                        placeholder="<?php echo esc_attr($default !== '' ? 'Inheriting the shipped default below' : 'Nothing is shipped for this block'); ?>"
                                    ><?php echo esc_textarea((string) ($block['override'][$field] ?? '')); ?></textarea>

                                    <p class="description">
                                        <?php if ('' !== $default) : ?>
                                            <strong>Ships with:</strong> <?php echo esc_html($default); ?>
                                        <?php else : ?>
                                            <em>Nothing is shipped for this block &mdash; anything written here is all the
                                            model will be told.</em>
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <?php submit_button('Save curation'); ?>
    </form>
</div>
