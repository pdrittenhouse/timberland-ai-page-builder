<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The framework's curated build artifacts — the only things this plugin reads from the build.
 *
 * The division of labour, decided deliberately:
 *
 * **WordPress is the authority on what EXISTS.** `WP_Block_Type_Registry` returns exactly the
 * blocks that will render — 48 on natural-rose, 53 on updater-starter, matching the framework
 * build's own counts — already resolved across framework, plugin, parent and child, and already
 * filtered by plugin activation. ACF is the authority on field shapes, read through
 * `FieldKeyMap`. Neither needs a manifest, and re-deriving either from one is how this stack has
 * twice shipped a wrong block list: by globbing tier directories it had hardcoded.
 *
 * **The build is the authority on what is CURATED** — editorial judgement that cannot be
 * derived from a registration at runtime:
 *
 *   - `best_for` / `avoid_when` (UC1/UC2), harvested from each block's documentation. The
 *     registry carries `description`, which says what a block IS; these say when to reach for
 *     it and when to reach for something else, which is what a model actually needs.
 *   - The selection index's SIZE. It is measured at build time against a committed token
 *     ceiling, and `AiManifestPlugin` fails the build when it is exceeded.
 *
 * These artifacts are expected to exist. This is not a standalone plugin: it ships against the
 * Timberland framework and its themes, whose build emits them on every production build. A
 * missing artifact is therefore a broken install, not a supported configuration — so it is
 * reported through `missing()` and surfaced in the manifest rather than silently treated as
 * "no curation available", which would degrade every prompt with nothing to show for it.
 *
 * @package TimberlandAIPageBuilder
 */
class CuratedContext
{
    /**
     * Artifacts read, and what each supplies.
     *
     * Deliberately short. `ai-manifest.json`, `ai-block-schemas.json` and
     * `ai-nesting-graph.json` are NOT here: everything the plugin needs from them is available
     * from the registry and ACF at runtime, in activation-resolved form. The schemas become
     * relevant when output validation lands, which reads them directly rather than through the
     * manifest.
     */
    private const ARTIFACTS = [
        'selection_index' => 'ai-selection-index.json',
    ];

    private string $dir;

    /** @var array<string, array<string, string>>|null */
    private ?array $index = null;

    /** @var array<int, string> */
    private array $missing = [];

    /**
     * @param string|null $dir directory holding the built manifests; defaults to the PARENT
     *                         theme's `src/manifests/`, which is where the framework's own PHP
     *                         readers look and where the committed copies live. The child
     *                         theme's build reads the parent's rather than producing its own.
     */
    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? get_template_directory() . '/src/manifests', '/');
    }

    /**
     * Curated prose per block slug.
     *
     * Keyed by BARE slug (`accordion`), which is how the index stores it — not by the
     * registered block name (`acf/accordion`). `usage_notes_for()` does that translation so
     * callers never have to know.
     *
     * @return array<string, array{best_for: string, avoid_when: string}>
     */
    public function selection_index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];
        $decoded = $this->read(self::ARTIFACTS['selection_index']);

        foreach ($decoded['entries'] ?? [] as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            $this->index[$entry['slug']] = [
                'best_for' => (string) ($entry['best_for'] ?? ''),
                'avoid_when' => (string) ($entry['avoid_when'] ?? ''),
            ];
        }

        return $this->index;
    }

    /**
     * The curated prose for one registered block, as a single line for the prompt.
     *
     * Returns '' for a block the index does not cover. That is expected rather than an error:
     * the index carries the framework's 41 blocks, and a plugin- or theme-tier block has no
     * harvested documentation until someone writes a "When to Use This Block" section for it.
     * The registry still supplies its title and description, so it remains selectable — it just
     * competes without the editorial steer.
     */
    public function usage_notes_for(string $block_name): string
    {
        $slug = str_contains($block_name, '/')
            ? substr($block_name, strpos($block_name, '/') + 1)
            : $block_name;

        $entry = $this->selection_index()[$slug] ?? null;

        if ($entry === null) {
            return '';
        }

        $parts = [];

        if ($entry['best_for'] !== '') {
            $parts[] = 'Best for: ' . $entry['best_for'];
        }

        if ($entry['avoid_when'] !== '') {
            $parts[] = 'Avoid when: ' . $entry['avoid_when'];
        }

        return implode(' ', $parts);
    }

    /**
     * Artifacts that should have been present and were not.
     *
     * Read this after the index has been loaded. An empty array means the install is intact.
     *
     * @return array<int, string> absolute paths
     */
    public function missing(): array
    {
        $this->selection_index();

        return $this->missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        $path = $this->dir . '/' . $file;

        if (!is_readable($path)) {
            $this->missing[] = $path;

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            $this->missing[] = $path;

            return [];
        }

        return $decoded;
    }
}
