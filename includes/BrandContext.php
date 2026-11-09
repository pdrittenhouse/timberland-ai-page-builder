<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder;

/**
 * AM5 — the brand and style context a build cannot see.
 *
 * Why this cannot come from the build
 * ----------------------------------
 * Design tokens are build-time; a site's *configured values* are not. They live in `wp_options`
 * and theme mods, so a webpack plugin cannot see them at all. The manifest says which components
 * exist; this says what this particular site looks like.
 *
 * Why there is an allow-list and not a dump
 * -----------------------------------------
 * Measured on a real install: **425** `options_*` rows across **50** field roots — `header` 68,
 * `footer` 64, `traveling` 49, `page` 45, `sidebar` 30. Almost all of it is layout and feature
 * configuration, not brand, and it competes with the component catalogue for the same
 * 3,000-8,000 tokens (L2). Dumping it would spend the budget describing sidebar widths.
 *
 * So the roots are an explicit, filterable allow-list rather than a guess at which of the 50 are
 * "brand", and the token cost is returned so the split against the catalogue can be decided with
 * numbers instead of asserted. **That split is still a decision** — AM5 says to settle it once
 * AM2 is designed, and AM2 is now designed, but the call is not mine to make.
 *
 * The palette is a reference, not a colour
 * ---------------------------------------
 * `editor-color-palette` on this install returns `var(--primary)`, not `#RRGGBB`. The configured
 * value IS a CSS custom property, so the literal colour lives in the stylesheet and not in
 * WordPress. That is reported as-is rather than resolved: pretending to know the hex would be
 * inventing it, and a model asked to match a brand needs to know the indirection exists.
 */
class BrandContext
{
    /**
     * Option field roots considered brand context by default.
     *
     * Deliberately short. Everything else is layout or feature configuration.
     */
    private const DEFAULT_ROOTS = ['bg', 'custom', 'content'];

    /** @var array<int, string> */
    private array $roots;

    /**
     * @param array<int, string>|null $roots override the default allow-list
     */
    public function __construct(?array $roots = null)
    {
        $allowed = $roots ?? self::DEFAULT_ROOTS;

        /**
         * Filter which ACF option field roots count as brand context.
         *
         * @param array<int, string> $allowed field-name roots, e.g. `bg` for `options_bg_*`
         */
        $this->roots = function_exists('apply_filters')
            ? (array) apply_filters('taipb_brand_context_option_roots', $allowed)
            : $allowed;
    }

    /**
     * The editor palette as configured.
     *
     * @return array<int, array{slug: string, name: string, value: string, is_reference: bool}>
     */
    public function palette(): array
    {
        if (!function_exists('get_theme_support')) {
            return [];
        }
        $support = get_theme_support('editor-color-palette');
        $entries = is_array($support) && isset($support[0]) && is_array($support[0]) ? $support[0] : [];

        $out = [];
        foreach ($entries as $entry) {
            $value = (string) ($entry['color'] ?? '');
            $out[] = [
                'slug' => (string) ($entry['slug'] ?? ''),
                'name' => (string) ($entry['name'] ?? ''),
                'value' => $value,
                // `var(--x)` means the literal colour is in the stylesheet, not in WordPress.
                'is_reference' => str_starts_with($value, 'var('),
            ];
        }

        return $out;
    }

    /**
     * Site identity, which is always relevant and always small.
     *
     * @return array<string, string>
     */
    public function identity(): array
    {
        return [
            'name' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
            'description' => function_exists('get_bloginfo') ? (string) get_bloginfo('description') : '',
        ];
    }

    /**
     * ACF option values whose field root is in the allow-list.
     *
     * Reads `wp_options` directly rather than calling `get_field()` 425 times. Note the escaped
     * underscore: `_` is a LIKE wildcard, so an unescaped `options_%` matches far more than
     * intended — and `options__%` matches every row, which is how this was first measured wrong.
     *
     * @return array<string, mixed> field name -> value
     */
    public function options(): array
    {
        global $wpdb;
        if (!isset($wpdb) || !$this->roots) {
            return [];
        }

        $out = [];
        foreach ($this->roots as $root) {
            $like = 'options_' . $wpdb->esc_like((string) $root) . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like),
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $name = preg_replace('/^options_/', '', (string) $row['option_name']);
                $value = $row['option_value'];
                if ($value === '' || $value === null) {
                    continue;
                }
                $out[(string) $name] = maybe_unserialize($value);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The whole runtime context, with its token cost.
     *
     * @return array{identity: array<string, string>, palette: array<int, array<string, mixed>>, theme_mods: array<string, mixed>, options: array<string, mixed>, tokens: int}
     */
    public function build(): array
    {
        $mods = function_exists('get_theme_mods') ? (array) get_theme_mods() : [];
        // Theme mods carry internal bookkeeping a model has no use for.
        unset($mods['0'], $mods['nav_menu_locations'], $mods['custom_css_post_id']);

        $context = [
            'identity' => $this->identity(),
            'palette' => $this->palette(),
            'theme_mods' => $mods,
            'options' => $this->options(),
        ];

        $context['tokens'] = (int) ceil(strlen((string) wp_json_encode($context)) / 4);

        return $context;
    }
}
