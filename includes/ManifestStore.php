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
            return $cached;
        }

        // Try wp_options
        $stored = get_option(self::OPTION_KEY);
        if ($stored && is_array($stored) && !empty($stored['blocks'])) {
            set_transient(self::TRANSIENT_KEY, $stored, self::CACHE_TTL);
            return $stored;
        }

        // No manifest exists yet — generate one
        return $this->regenerate();
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
