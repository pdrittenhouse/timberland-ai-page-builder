<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores, caches, and retrieves the generated manifest.
 */
class ManifestStore
{
    private const OPTION_KEY = 'taipb_manifest';
    private const TRANSIENT_KEY = 'taipb_manifest_cache';
    private const CACHE_TTL = DAY_IN_SECONDS;

    private ManifestBuilder $builder;

    public function __construct(ManifestBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Get the manifest, using cache if available.
     */
    public function get(): array
    {
        // Try transient cache first
        $cached = get_transient(self::TRANSIENT_KEY);
        if ($cached !== false && is_array($cached)) {
            // Checked here as well as on the option below, and that is not belt-and-braces: the
            // first version of this guard sat only on the option branch, and a live probe showed it
            // never ran. The transient is the COMMON path -- it lasts a day -- so a check that
            // returns before reaching it is a check that almost never happens.
            if ($this->is_missing_registered_blocks($cached)) {
                return $this->regenerate();
            }

            return $cached;
        }

        // Try wp_options
        $stored = get_option(self::OPTION_KEY);
        if ($stored && is_array($stored) && !empty($stored['blocks'])) {
            if ($this->is_missing_registered_blocks($stored)) {
                return $this->regenerate();
            }

            set_transient(self::TRANSIENT_KEY, $stored, self::CACHE_TTL);
            return $stored;
        }

        // No manifest exists yet — generate one
        return $this->regenerate();
    }

    /**
     * Does the live block registry know a block this manifest has never heard of?
     *
     * Both the manifest's authorities are live -- blocks from `WP_Block_Type_Registry`, fields from
     * ACF's own `load_json` paths -- so a new block IS picked up automatically, but only when the
     * manifest is actually rebuilt. Nothing hooked block registration, so a block added today was
     * invisible until a day-long transient lapsed AND something called `regenerate()`; with the
     * option already populated, "something" was an admin action or the REST endpoint, which nobody
     * necessarily runs. "Automatic" has to mean the next generation, not the next time a human
     * remembers.
     *
     * **Deliberately asymmetric: extra blocks trigger a rebuild, missing ones never do.** This runs
     * wherever `get()` is called, and that is not guaranteed to be after every block has registered
     * -- a partially-populated registry would otherwise look like blocks were removed and rebuild
     * the manifest on every request, each rebuild re-reading ~106 ACF JSON files. A block that
     * genuinely disappears simply lingers until the next explicit regeneration, which costs a
     * catalogue line for something the model will not successfully use. Far cheaper than the
     * alternative failure.
     *
     * @param array<string, mixed> $stored
     */
    private function is_missing_registered_blocks(array $stored): bool
    {
        if (!class_exists('\WP_Block_Type_Registry')) {
            return false;
        }

        $known = is_array($stored['blocks'] ?? null) ? $stored['blocks'] : [];

        foreach (array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered()) as $name) {
            // Same filter `ManifestBuilder::collect_blocks()` applies, or every core block would
            // read as missing and rebuild forever.
            if (!str_starts_with((string) $name, 'acf/')) {
                continue;
            }

            if (!isset($known[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Force regenerate the manifest.
     */
    public function regenerate(): array
    {
        $manifest = $this->builder->build();

        update_option(self::OPTION_KEY, $manifest, false);
        set_transient(self::TRANSIENT_KEY, $manifest, self::CACHE_TTL);

        return $manifest;
    }

    /**
     * Clear the cached manifest.
     */
    public function clear_cache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Get manifest stats for diagnostics.
     */
    public function get_stats(): array
    {
        $manifest = $this->get();

        return [
            'version' => $manifest['version'] ?? 'unknown',
            'generated_at' => $manifest['generated_at'] ?? 'never',
            'block_count' => count($manifest['blocks'] ?? []),
            'layout_count' => count($manifest['layouts'] ?? []),
            'pattern_count' => count($manifest['patterns'] ?? []),
            'post_type_count' => count($manifest['post_types'] ?? []),
            'taxonomy_count' => count($manifest['taxonomies'] ?? []),
            'total_field_mappings' => $this->count_field_mappings($manifest),
        ];
    }

    /**
     * Count total field mappings across all blocks.
     */
    private function count_field_mappings(array $manifest): int
    {
        $count = 0;
        foreach ($manifest['blocks'] ?? [] as $block) {
            $count += count($block['field_key_map'] ?? []);
        }
        return $count;
    }
}
