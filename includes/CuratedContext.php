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
        // UC6. Separate from the selection index because that one is loaded wholesale under a
        // token ceiling (3,519 of 4,200 measured) and this is read per block.
        'configuration' => 'ai-block-configuration.json',
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
    /** @var array<string, array{best_for: string, avoid_when: string}>|null */
    private ?array $shipped = null;

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
    /**
     * The build artifact's curation, with no site override applied.
     *
     * What the block itself ships. The admin screen shows this beside each override field, since
     * an empty field inherits it and "what is in force" is otherwise unknowable from the form.
     *
     * @return array<string, array{best_for: string, avoid_when: string}> keyed by bare slug
     */
    public function shipped_index(): array
    {
        if (null !== $this->shipped) {
            return $this->shipped;
        }

        $this->shipped = [];
        $decoded = $this->read(self::ARTIFACTS['selection_index']);

        foreach ($decoded['entries'] ?? [] as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            $this->shipped[(string) $entry['slug']] = [
                'best_for' => (string) ($entry['best_for'] ?? ''),
                'avoid_when' => (string) ($entry['avoid_when'] ?? ''),
            ];
        }

        return $this->shipped;
    }

    public function selection_index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];

        /*
         * UC3: the site override and the filter stack on top of the shipped default HERE, which is
         * the one place both curated readers pass through. Applying it per reader instead would be
         * another instance of the divergence this stack keeps paying for.
         *
         * `shipped_index()` is kept separate rather than inlined because the admin screen needs
         * the unmerged value: its whole job is to show what a field falls back to, and showing the
         * merged value as the default would make an override look like something the block ships
         * with. One read, two honest answers.
         */
        foreach ($this->shipped_index() as $slug => $entry) {
            $merged = CuratedOverrides::for_block($slug, $entry);

            $this->index[$slug] = [
                'best_for' => $merged['best_for'],
                'avoid_when' => $merged['avoid_when'],
            ];
        }

        /*
         * A site may curate a block the artifact does not cover, and that is the likeliest reason
         * to reach for the override screen at all: the index carries the framework's documented
         * blocks, so a plugin- or theme-tier block has no harvested guidance until someone writes
         * it. Iterating only the artifact's entries would accept such an override, store it, show
         * it in admin, and never once put it in a prompt.
         */
        foreach (CuratedOverrides::stored() as $slug => $_fields) {
            if (isset($this->index[$slug])) {
                continue;
            }

            $merged = CuratedOverrides::for_block($slug, []);

            if ('' === $merged['best_for'] && '' === $merged['avoid_when']) {
                continue;
            }

            $this->index[$slug] = [
                'best_for' => $merged['best_for'],
                'avoid_when' => $merged['avoid_when'],
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
    /**
     * UC6's configuration guidance for one block, or '' when nobody has written any.
     *
     * Deliberately NOT merged into `usage_notes_for()`, which concatenates `best_for` and
     * `avoid_when` into the catalogue line every block pays for. This is served beside the FIELD
     * LIST instead, so it is paid only for the handful of blocks a generation actually picks --
     * the same split that keeps 14,252 utility classes behind a lookup.
     *
     * An absent entry means undocumented, not "needs no advice". The artifact omits blocks with
     * nothing written rather than carrying empty strings, so the two stay distinguishable and the
     * first remains something to go and fix.
     */
    public function configuration_for(string $block_name): string
    {
        $slug = str_contains($block_name, '/')
            ? substr($block_name, strpos($block_name, '/') + 1)
            : $block_name;

        // Read through the override layer even when the artifact carries nothing for this block: a
        // site is entitled to write configuration guidance for a block whose `.md` has none, which
        // is in fact the likeliest reason to reach for the curation screen.
        return CuratedOverrides::for_block(
            $slug,
            ['configuration' => $this->shipped_configuration_for($slug)]
        )['configuration'];
    }

    /**
     * UC6's shipped configuration guidance for one block, with no site override applied.
     */
    public function shipped_configuration_for(string $block_name): string
    {
        $slug = str_contains($block_name, '/')
            ? substr($block_name, strpos($block_name, '/') + 1)
            : $block_name;

        $decoded = $this->read(self::ARTIFACTS['configuration']);
        $entries = $decoded['entries'] ?? null;

        return is_array($entries) ? trim((string) ($entries[$slug] ?? '')) : '';
    }

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
