<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder;

/**
 * AM4 — reconcile the live block registry against the build artifact.
 *
 * Why two layers and not one
 * -------------------------
 * Build-time covers every first-party component, and the infrastructure confirms that is almost
 * everything: free and basic tiers are multisite subsites sharing one filesystem and one build,
 * paid tiers are provisioned from the platform, and customer-authored blocks appear only at
 * Enterprise. Customers do not build.
 *
 * Two cases escape it, and both are in the business plan: the marketplace ships third-party
 * blocks into sites that will never be rebuilt, and a plugin activated after a build registers
 * blocks the build never saw. Neither can be fixed by rebuilding, because nobody is going to.
 *
 * So the build artifact is the base and this is a thin reconciliation on top. It deliberately
 * does NOT scan directories — the build does that better, and re-deriving tier resolution in PHP
 * is a second implementation of something this stack has already got wrong three times.
 *
 * What it does not merge, and why
 * ------------------------------
 * `core/*` is excluded by default. A live install registers 207 block types: 116 core, 48 ACF
 * (exactly the artifact), 43 third-party. Merging core would add 116 entries a model already
 * knows and would spend the entire 3,000-8,000 token budget (L2) on blocks the plan does not
 * compose from. The exclusion is a filter, not a hard rule, so an install that genuinely wants
 * them can have them.
 *
 * Runtime-only blocks carry no `best_for` or `avoid_when` — nobody authored any — so they are
 * marked with their provenance and the caller decides whether a block with no use-case metadata
 * belongs in a prompt. Presenting them as equivalent to a documented block would be a lie about
 * how much is known.
 */
class RuntimeMerge
{
    /** Namespaces never merged, unless a filter says otherwise. */
    private const DEFAULT_EXCLUDED_NAMESPACES = ['core'];

    /** @var array<int, string> */
    private array $excluded;

    /**
     * @param array<int, string>|null $excludedNamespaces override the default exclusions
     */
    public function __construct(?array $excludedNamespaces = null)
    {
        $excluded = $excludedNamespaces ?? self::DEFAULT_EXCLUDED_NAMESPACES;

        /**
         * Filter the block namespaces the runtime merge ignores.
         *
         * @param array<int, string> $excluded namespace prefixes, without the slash
         */
        $this->excluded = function_exists('apply_filters')
            ? (array) apply_filters('taipb_runtime_merge_excluded_namespaces', $excluded)
            : $excluded;
    }

    /**
     * @param  string $blockName a registered block name, e.g. `acf/card`
     * @return bool   whether it is in an excluded namespace
     */
    public function isExcluded(string $blockName): bool
    {
        $namespace = explode('/', $blockName)[0] ?? '';

        return in_array($namespace, $this->excluded, true);
    }

    /**
     * Merge the registry into the artifact.
     *
     * @param  array<string, mixed>                  $artifact decoded `ai-manifest.json`
     * @param  array<string, array<string, mixed>>   $registry block name -> {description, parent, title}
     * @return array{
     *     selection_index: array<int, array<string, mixed>>,
     *     added: array<int, string>,
     *     missing_at_runtime: array<int, string>,
     *     excluded: int
     * }
     */
    public function merge(array $artifact, array $registry): array
    {
        $index = [];
        foreach (($artifact['selection_index'] ?? []) as $entry) {
            if (!empty($entry['slug'])) {
                $index[$entry['slug']] = $entry;
            }
        }

        $added = [];
        $excluded = 0;
        $seen = [];

        foreach ($registry as $name => $meta) {
            if ($this->isExcluded($name)) {
                ++$excluded;
                continue;
            }

            $slug = CssCoverage::normalise($name);
            $seen[$slug] = true;

            if (isset($index[$slug])) {
                continue;
            }

            // A runtime-only block. It has no authored use-case metadata, and saying so is the
            // point: a caller must be able to tell a documented block from an unknown one.
            $entry = [
                'slug' => $slug,
                'block_name' => $name,
                'best_for' => trim((string) ($meta['description'] ?? '')),
                'x-tl-source' => 'runtime',
                'x-tl-documented' => false,
            ];
            if (!empty($meta['parent'])) {
                $entry['parent_only'] = array_values((array) $meta['parent']);
            }
            $index[$slug] = $entry;
            $added[] = $slug;
        }

        // In the artifact but not registered: the block's plugin or theme is deactivated, so a
        // composition using it would render an empty comment. Reported rather than removed,
        // because a deactivation may be temporary and silently shrinking the catalogue hides it.
        $missing = [];
        foreach ($index as $slug => $entry) {
            if (($entry['x-tl-source'] ?? '') === 'runtime') {
                continue;
            }
            if (!isset($seen[$slug])) {
                $missing[] = $slug;
            }
        }

        ksort($index);
        sort($added);
        sort($missing);

        $merged = array_values($index);

        // L2/VP2 — the token cost is returned, not left for the caller to discover.
        //
        // Measured on a real install: the artifact's 41 documented entries are 2,843 tokens; the
        // merge adds 50 runtime blocks and the index becomes **5,158**, which is inside the
        // 3,000-8,000 budget but well past VP2's committed 3,000 ceiling. A caller that prompts
        // the merged index wholesale has spent most of its budget on blocks with no authored
        // use-case metadata, which is precisely what L2 says not to do.
        //
        // So the split is reported: a caller can prompt `documented` and fetch the rest on
        // demand, which is the two-phase shape AM2's artifact split exists to support.
        $documented = array_values(array_filter(
            $merged,
            static fn (array $e): bool => ($e['x-tl-source'] ?? '') !== 'runtime'
        ));

        return [
            'selection_index' => $merged,
            'documented' => $documented,
            'added' => $added,
            'missing_at_runtime' => $missing,
            'excluded' => $excluded,
            'tokens' => [
                'documented' => self::estimateTokens($documented),
                'merged' => self::estimateTokens($merged),
            ],
        ];
    }

    /**
     * Rough token estimate, matching the build-side estimator so the two figures are comparable.
     *
     * Deliberately crude and pessimistic at four characters per token: a budget check must never
     * under-report, and a real tokenizer would make this depend on a model vendor's package.
     *
     * @param  array<int, array<string, mixed>> $entries index entries
     * @return int estimated tokens
     */
    public static function estimateTokens(array $entries): int
    {
        return (int) ceil(strlen((string) json_encode($entries)) / 4);
    }

    /**
     * Read the live registry into the shape merge() expects.
     *
     * Separated so merge() is testable without WordPress.
     *
     * @return array<string, array<string, mixed>>
     */
    public function readRegistry(): array
    {
        if (!class_exists('\WP_Block_Type_Registry')) {
            return [];
        }

        $out = [];
        foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type) {
            $out[$name] = [
                // No `?? ''`: WP_Block_Type declares both `@var string` with a default of
                // '', so they are never null and the coalesce was dead. Removing it deleted
                // two phpstan baseline entries rather than carrying them.
                'description' => (string) $type->description,
                'title' => (string) $type->title,
                'parent' => $type->parent ?? null,
            ];
        }

        return $out;
    }
}
