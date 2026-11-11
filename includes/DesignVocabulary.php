<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The values a generated page is allowed to style itself with.
 *
 * **It was all already generated, and nothing read it.** Measured 2026-11-11: `scssVariables.json`
 * carries 33 colours and 41 spacers, `icon-manifest.json` four icon families, and
 * `dist/utility-index.json` **14,934 rules across 14,252 class names** -- and the plugin referenced
 * none of the three. So the gap was never a missing document; it was a missing projection.
 *
 * **Read the authority, not a copy.** Colours, gradients, font sizes and spacing come from
 * `WP_Theme_JSON_Resolver::get_merged_data()`, which already merges framework -> parent -> child and
 * reports 15 palette entries on this install. That matters twice over: it is the same principle that
 * made the block manifest read `WP_Block_Type_Registry` instead of scanning directories, and it
 * avoids `scssVariables.json` entirely -- a per-tier BUILD artifact holding 33 colours in the child,
 * 28 in the parent and 53 in the framework source, which is exactly the assign-versus-union trap
 * this codebase has fallen into three times.
 *
 * Only what WordPress genuinely does not know comes from files: icons, utility classes, and
 * breakpoints.
 *
 * **Summary inline, detail on demand.** 15 colours and 7 spacers are a few dozen tokens and belong
 * in every prompt. 14,252 utility class names are ~43,000 tokens and belong behind a lookup, the
 * same split as the 1.4 MB block manifest becoming a 2.4 KB index plus `get_block_fields`.
 */
class DesignVocabulary
{
    /** Utility families worth naming in a prompt, as prefix => what it does. */
    private const UTILITY_FAMILIES = [
        'm' => 'margin (m, mt, mb, ms, me, mx, my)',
        'p' => 'padding (p, pt, pb, ps, pe, px, py)',
        'text' => 'text alignment, colour, transform, wrapping',
        'bg' => 'background colour',
        'd' => 'display (d-none, d-flex, d-block …)',
        'flex' => 'flex direction, wrap, grow',
        'justify' => 'main-axis alignment',
        'align' => 'cross-axis alignment',
        'gap' => 'grid and flex gap',
        'border' => 'border width, colour, radius',
        'rounded' => 'border radius',
        'shadow' => 'box shadow',
        'w' => 'width',
        'h' => 'height',
        'position' => 'static, relative, absolute, fixed, sticky',
        'overflow' => 'overflow behaviour',
        'fs' => 'font size',
        'fw' => 'font weight',
        'lh' => 'line height',
        'opacity' => 'opacity',
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * The compact vocabulary, small enough to inline in every prompt.
     *
     * @return array{
     *     colors: array<int, string>,
     *     gradients: array<int, string>,
     *     font_sizes: array<int, string>,
     *     spacing: array<int, string>,
     *     breakpoints: array<int, string>,
     *     icon_families: array<string, int>,
     *     icons: array<int, string>,
     *     utility_families: array<string, string>,
     *     utility_count: int
     * }
     */
    public function summary(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $settings = $this->theme_settings();

        return $this->cache = [
            'colors' => self::slugs($settings['color']['palette'] ?? null),
            'gradients' => self::slugs($settings['color']['gradients'] ?? null),
            'font_sizes' => self::slugs($settings['typography']['fontSizes'] ?? null),
            'spacing' => self::slugs($settings['spacing']['spacingSizes'] ?? null),
            'breakpoints' => $this->breakpoints(),
            // Named in full, not counted. Measured: 33 icons across four families, roughly 100
            // tokens -- and a model cannot choose an icon from a count. This is the icon set this
            // install actually registers, which is content-driven and therefore small; if it ever
            // grows past a few hundred it belongs behind the same lookup as the utilities.
            'icon_families' => $this->icon_families(),
            'icons' => $this->all_icons(),
            'utility_families' => self::UTILITY_FAMILIES,
            'utility_count' => $this->utility_count(),
        ];
    }

    /**
     * Utility class names matching a prefix or substring.
     *
     * The on-demand half. 14,252 names is ~43,000 tokens; a model asking "what margin utilities
     * exist" wants a few dozen.
     *
     * @return array{matched: int, total: int, classes: array<int, string>}
     */
    public function utilities(string $query, int $limit = 60): array
    {
        $query = strtolower(trim($query));
        $all = array_keys($this->utility_index());

        if ('' === $query) {
            return ['matched' => 0, 'total' => count($all), 'classes' => []];
        }

        $matched = self::rank_matches(array_map('strval', $all), $query);

        return [
            'matched' => count($matched),
            'total' => count($all),
            'classes' => array_slice($matched, 0, $limit),
        ];
    }

    /**
     * Class names matching a query, prefix matches before substring ones.
     *
     * A pure function on purpose. It was inline, and the only way to test it was an anonymous
     * subclass that re-implemented the ranking -- which tests the test. Pulled out so the ordering
     * can be asserted against a known list without a filesystem.
     *
     * The ordering is the useful part: a model asking for `m` means margin utilities, not every
     * class containing an m. Putting the cheap reading first is what makes a one-letter query
     * answerable rather than noise.
     *
     * @param array<int, string> $all
     * @return array<int, string>
     */
    public static function rank_matches(array $all, string $query): array
    {
        $query = strtolower(trim($query));

        if ('' === $query) {
            return [];
        }

        $prefixed = [];
        $contained = [];

        foreach ($all as $class) {
            $lower = strtolower($class);

            if ($lower === $query || str_starts_with($lower, $query . '-')) {
                $prefixed[] = $class;
            } elseif (str_contains($lower, $query)) {
                $contained[] = $class;
            }
        }

        return array_merge($prefixed, $contained);
    }

    /**
     * Icon names in one family.
     *
     * @return array{family: string, total: int, icons: array<int, string>}
     */
    public function icons(string $family, int $limit = 80): array
    {
        $families = $this->icon_manifest()['icons'] ?? [];
        $icons = is_array($families[$family] ?? null) ? array_keys($families[$family]) : [];

        return [
            'family' => $family,
            'total' => count($icons),
            'icons' => array_slice(array_map('strval', $icons), 0, $limit),
        ];
    }

    /**
     * @param mixed $preset theme.json presets, which may be flat or keyed by origin
     * @return array<int, string>
     */
    private static function slugs($preset): array
    {
        if (!is_array($preset)) {
            return [];
        }

        // theme.json settings arrive flat OR grouped by origin (`default`, `theme`, `custom`)
        // depending on how they are read. Handling only one shape returns nothing for the other,
        // silently -- and "no colours" is indistinguishable from "no palette configured".
        //
        // When grouped, the THEME origin wins and core's defaults are not merged in. Measured:
        // merging every origin reported 18 gradients where the theme defines 6, the extra twelve
        // being WordPress's own `vivid-cyan-blue-to-vivid-purple` and friends. Those exist, but
        // they are not this design system's vocabulary, and offering them invites an off-brand
        // choice that looks sanctioned. `custom` is included because a site that adds one means it.
        if (isset($preset[0]['slug'])) {
            $entries = $preset;
        } else {
            $preferred = array_filter([
                $preset['theme'] ?? null,
                $preset['custom'] ?? null,
            ], 'is_array');

            $entries = $preferred !== []
                ? array_merge(...array_values($preferred))
                : array_merge(...array_values(array_filter($preset, 'is_array')));
        }

        $slugs = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['slug'])) {
                $slugs[] = (string) $entry['slug'];
            }
        }

        return array_values(array_unique($slugs));
    }

    /** @return array<string, mixed> */
    private function theme_settings(): array
    {
        if (!class_exists('\WP_Theme_JSON_Resolver')) {
            return [];
        }

        $settings = \WP_Theme_JSON_Resolver::get_merged_data()->get_settings();

        return is_array($settings) ? $settings : [];
    }

    /**
     * Breakpoints, which theme.json does not model.
     *
     * @return array<int, string>
     */
    private function breakpoints(): array
    {
        $vars = $this->scss_variables();
        $bp = $vars['scssBreakpoints'] ?? null;

        if (!is_array($bp)) {
            return [];
        }

        $out = [];

        foreach ($bp as $name => $value) {
            $out[] = is_scalar($value) ? sprintf('%s (%s)', $name, $value) : (string) $name;
        }

        return $out;
    }

    /**
     * Every registered icon, as `family:name`.
     *
     * @return array<int, string>
     */
    private function all_icons(): array
    {
        $families = $this->icon_manifest()['icons'] ?? [];
        $out = [];

        foreach (is_array($families) ? $families : [] as $family => $icons) {
            foreach (is_array($icons) ? array_keys($icons) : [] as $name) {
                $out[] = sprintf('%s:%s', $family, $name);
            }
        }

        return $out;
    }

    /** @return array<string, int> family => icon count */
    private function icon_families(): array
    {
        $families = $this->icon_manifest()['icons'] ?? [];
        $out = [];

        foreach (is_array($families) ? $families : [] as $name => $icons) {
            $out[(string) $name] = is_array($icons) ? count($icons) : 0;
        }

        return $out;
    }

    private function utility_count(): int
    {
        return count($this->utility_index());
    }

    /** @return array<string, mixed> */
    private function utility_index(): array
    {
        $decoded = $this->read_tiered('dist/utility-index.json');
        $classes = $decoded['classes'] ?? null;

        return is_array($classes) ? $classes : [];
    }

    /** @return array<string, mixed> */
    private function icon_manifest(): array
    {
        return $this->read_tiered('src/generated/icons/icon-manifest.json');
    }

    /** @return array<string, mixed> */
    private function scss_variables(): array
    {
        return $this->read_tiered('dist/wp/data/_data/scssVariables.json');
    }

    /**
     * Read a build artifact from the most specific tier that has it.
     *
     * Child first, then parent. Not a union: these are each a tier's COMPILED output, so the
     * child's is the resolved answer rather than a layer to merge -- measured, the child's
     * `scssVariables.json` has 33 colours where the parent's has 28, and they are not nested sets.
     * Where a true authority exists, this is not used at all: colours come from
     * `WP_Theme_JSON_Resolver`, which does its own merging.
     *
     * @return array<string, mixed>
     */
    private function read_tiered(string $relative): array
    {
        $roots = [];

        if (function_exists('get_stylesheet_directory')) {
            $roots[] = get_stylesheet_directory();
        }

        if (function_exists('get_template_directory')) {
            $roots[] = get_template_directory();
        }

        foreach (array_unique($roots) as $root) {
            $path = rtrim((string) $root, '/') . '/' . ltrim($relative, '/');

            if (!is_readable($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
