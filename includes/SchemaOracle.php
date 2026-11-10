<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves a block's JSON Schema, for VALIDATING generated output.
 *
 * Schemas are never prompted and this class exists to make sure they are not. Measured across
 * the framework's 41 block schemas: median 13,522 tokens, mean 16,060, largest 40,016
 * (`posts-loop`, 574 properties after ACF clone expansion). The cost model allots 3,000-8,000
 * tokens for ALL component context, so one median schema is twice the entire budget and four of
 * them is seven times it. Even the cheapest useful projection — field name to type, plus enums —
 * is a median 2,602 tokens per block, so four blocks still exceeds the allowance.
 *
 * Compressing does not help and makes it worse: base64 is 133% of the raw bytes AND, being
 * high-entropy, tokenises at roughly one token per two or three characters instead of one per
 * four, for a net cost of about 2.5-4x the plain JSON that the model then cannot read.
 *
 * The economics are decisive the other way. Prompting `card`'s contract costs 33,632 tokens on
 * every request whether the model needed it or not; validating the output against it costs
 * nothing and the resulting error message is a few dozen tokens.
 * {@see MarkupGenerator} already retries once with validation errors as feedback — it simply had
 * nothing authoritative to validate against.
 *
 * ## Where a schema comes from
 *
 * Two sources, in order:
 *
 * 1. **`ai-block-schemas.json`** in the parent theme's `src/manifests/`. This is the
 *    INSTALL-RESOLVED contract: the blocks a later tier overrode or introduced, so the schema
 *    reflects what this install actually renders rather than the framework baseline. 15 of 48 on
 *    natural-rose. No path resolution needed — it sits in a known location.
 * 2. **`schema_index` in `ai-manifest.json`**, for the remaining blocks, whose committed schema
 *    sits beside the component and is identical to the baseline. Each entry records a path
 *    RELATIVE TO ITS TIER ROOT plus the tier's package directory name.
 *
 * Resolving (2) means finding where a named tier lives. That is deliberately done by looking up
 * the name the BUILD recorded rather than by hardcoding tier paths — which is the mistake this
 * stack has shipped twice, most recently a hardcoded `vendor/pdrittenhouse/timberland` that made
 * all 41 framework blocks invisible on updater-starter. Verified on both installs: 41 of 41
 * schemas resolve, through the vendor path rather than a symlink target.
 *
 * A schema that cannot be resolved is reported, never silently skipped: validation that quietly
 * stops validating is worse than no validation, because it still reports "valid".
 *
 * @package TimberlandAIPageBuilder
 */
class SchemaOracle
{
    private string $manifest_dir;

    /** @var array<string, array<string, mixed>|null> block name => schema, or null when absent */
    private array $cache = [];

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $index = null;

    /** @var array<int, string> */
    private array $unresolved = [];

    /** @var array<int, string> */
    private array $stale = [];

    public function __construct(?string $manifest_dir = null)
    {
        $this->manifest_dir = rtrim($manifest_dir ?? get_template_directory() . '/src/manifests', '/');
    }

    /**
     * The schema describing one block's STORAGE shape, or null when none is available.
     *
     * @return array<string, mixed>|null
     */
    public function schema_for(string $block_name): ?array
    {
        if (array_key_exists($block_name, $this->cache)) {
            return $this->cache[$block_name];
        }

        $slug = $this->slug($block_name);

        // 1. The install-resolved contract wins: it describes what this install renders, which
        //    is the whole point of emitting it per install.
        $resolved = $this->resolved_schemas();

        if (isset($resolved[$slug]) && is_array($resolved[$slug])) {
            return $this->cache[$block_name] = $resolved[$slug];
        }

        // 2. Otherwise the committed baseline beside the component.
        $entry = $this->schema_index()[$slug] ?? null;

        if ($entry === null) {
            return $this->cache[$block_name] = null;
        }

        $file = $this->locate($entry);

        if ($file === null) {
            $this->unresolved[] = $slug;

            return $this->cache[$block_name] = null;
        }

        // A LOCAL schema file on disk. `wp_remote_get()`, which the sniff suggests, is for
        // HTTP and is categorically the wrong tool; WP_Filesystem would add a dependency for no
        // safety gain on a read. (The ignore must sit on the line immediately above the code --
        // it applies to the NEXT line only, so a comment in between silences the comment.)
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $schema = json_decode((string) file_get_contents($file), true);

        if (!is_array($schema)) {
            $this->unresolved[] = $slug;

            return $this->cache[$block_name] = null;
        }

        // The index records the hash the build saw. A mismatch means the schema on disk is not
        // the one the manifest describes -- a partial deploy, or a schema edited without
        // regenerating. Validation still runs against what is on disk, because that is what the
        // site has, but the disagreement is reported rather than absorbed.
        $recorded = $entry['content_hash'] ?? null;
        $actual = $schema['x-tl-content-hash'] ?? null;

        if ($recorded !== null && $actual !== null && !hash_equals((string) $recorded, (string) $actual)) {
            $this->stale[] = $slug;
        }

        return $this->cache[$block_name] = $schema;
    }

    /**
     * Blocks whose schema the manifest promised and that could not be read.
     *
     * @return array<int, string>
     */
    public function unresolved(): array
    {
        return array_values(array_unique($this->unresolved));
    }

    /**
     * Blocks whose on-disk schema does not match the hash the manifest recorded.
     *
     * @return array<int, string>
     */
    public function stale(): array
    {
        return array_values(array_unique($this->stale));
    }

    /**
     * Find the tier root a `schema_index` entry's relative path hangs off.
     *
     * Candidates are derived from the tier NAME the build recorded, checked against the places a
     * tier can actually live on this install. The vendor glob matches `vendor/<org>/<tier>`, so
     * it finds the framework through the Composer path rather than the symlink target the build
     * saw -- which is exactly the portability problem the recorded path used to have.
     *
     * @param array<string, mixed> $entry
     */
    private function locate(array $entry): ?string
    {
        $tier = (string) ($entry['tier'] ?? '');
        $rel = (string) ($entry['path'] ?? '');

        if ($tier === '' || $rel === '') {
            return null;
        }

        $roots = glob(get_template_directory() . "/vendor/*/{$tier}/src/templates/blocks") ?: [];

        if (basename(get_template_directory()) === $tier) {
            $roots[] = get_template_directory() . '/src/templates/blocks';
        }

        if (basename(get_stylesheet_directory()) === $tier) {
            $roots[] = get_stylesheet_directory() . '/src/templates/blocks';
        }

        if (defined('WP_PLUGIN_DIR')) {
            $roots = array_merge($roots, glob(WP_PLUGIN_DIR . "/{$tier}/src/templates/blocks") ?: []);
        }

        foreach ($roots as $root) {
            $candidate = $root . '/' . $rel;

            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolved_schemas(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $decoded = $this->read('ai-block-schemas.json');

        return $this->resolved = is_array($decoded['schemas'] ?? null) ? $decoded['schemas'] : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schema_index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $decoded = $this->read('ai-manifest.json');
        $block = $decoded['schema_index']['block'] ?? null;

        return $this->index = is_array($block) ? $block : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        $path = $this->manifest_dir . '/' . $file;

        if (!is_readable($path)) {
            return [];
        }

        // A local manifest file, not a remote fetch -- see schema_for() above.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function slug(string $block_name): string
    {
        return str_contains($block_name, '/')
            ? substr($block_name, strpos($block_name, '/') + 1)
            : $block_name;
    }
}
