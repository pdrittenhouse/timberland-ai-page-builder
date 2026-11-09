<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder;

/**
 * VP3 — assert that every block a composition uses will actually have its CSS delivered.
 *
 * The failure this prevents
 * ------------------------
 * A page can render a block whose theme CSS the loader never delivers, and nothing fails. Found
 * on 2026-11-08: `/tl-fixture-bootstrap-components/` rendered six accordion class attributes
 * while the served 1.63 MB of CSS contained zero of the theme's four accordion declarations,
 * because the page's resolved pattern list (18 entries, captured from the live request) did not
 * include accordion. It took eight days and a gate run to notice, and only because a baseline
 * happened to disagree.
 *
 * For AI-composed pages the risk is strictly higher: a model can compose block combinations no
 * human has, so no baseline exists to disagree with.
 *
 * Why it is a manifest lookup and nothing more
 * -------------------------------------------
 * `pattern-manifest.json` already carries the 31 block -> pattern edges and, per pattern, whether
 * it has CSS and what it depends on. So this is set arithmetic over data already on disk —
 * microseconds — which is what lets it run INSIDE the generation request and refuse a
 * composition rather than ship it.
 *
 * What a missing edge means, and does not
 * --------------------------------------
 * 17 of the 48 blocks have no pattern edge at all: `row`, `column`, `section`, the five
 * `table-*` parts, `tab`, `slide`, `button-text` and friends. That is not a defect — they emit
 * Bootstrap grid and table classes directly, from CSS that always ships. Treating "no edge" as a
 * violation would fail a third of every composition and train the caller to ignore this check.
 * Only a block that DECLARES a pattern whose CSS is then not delivered is a violation.
 */
class CssCoverage
{
    /** @var array<string, array<int, string>> block name (no `acf/`) -> pattern ids */
    private array $blockPatterns = [];

    /** @var array<string, array<string, mixed>> pattern id -> its manifest entry */
    private array $patterns = [];

    /**
     * @param array<string, mixed> $patternManifest decoded `pattern-manifest.json`
     */
    public function __construct(array $patternManifest)
    {
        // The two manifests disagree on how a block is keyed: `block-manifest.json` uses the
        // bare slug, `pattern-manifest.json` uses `acf/<slug>`. Normalising here rather than at
        // every call site, because comparing the two unnormalised silently reports all 48 blocks
        // as absent — which it did, the first time this was measured.
        foreach (($patternManifest['blocks'] ?? []) as $block => $patterns) {
            $this->blockPatterns[self::normalise($block)] = array_values((array) $patterns);
        }
        $this->patterns = (array) ($patternManifest['patterns'] ?? []);
    }

    /**
     * @param  string $block a block name, with or without the `acf/` prefix
     * @return string the bare slug
     */
    public static function normalise(string $block): string
    {
        return preg_replace('#^acf/#', '', $block) ?? $block;
    }

    /**
     * Every pattern a set of blocks needs, following `dependencies` transitively.
     *
     * @param  array<int, string> $blocks block names
     * @return array<int, string> pattern ids, sorted and unique
     */
    public function requiredPatterns(array $blocks): array
    {
        $needed = [];
        $queue = [];

        foreach ($blocks as $block) {
            foreach ($this->blockPatterns[self::normalise($block)] ?? [] as $pattern) {
                $queue[] = $pattern;
            }
        }

        // A pattern's own dependencies ship with it, so a composition needs them too. Guarded
        // against a cycle rather than trusted not to have one.
        while ($queue) {
            $pattern = array_shift($queue);
            if (isset($needed[$pattern])) {
                continue;
            }
            $needed[$pattern] = true;
            foreach ((array) ($this->patterns[$pattern]['dependencies'] ?? []) as $dep) {
                if (!isset($needed[$dep])) {
                    $queue[] = $dep;
                }
            }
        }

        $out = array_keys($needed);
        sort($out);

        return $out;
    }

    /**
     * Check a composition against the patterns a request will actually resolve.
     *
     * @param  array<int, string> $blocks           blocks the composition uses
     * @param  array<int, string> $resolvedPatterns patterns the loader will deliver CSS for
     * @return array<int, array{block: string, pattern: string, reason: string}> one entry per violation; empty means ship it
     */
    public function check(array $blocks, array $resolvedPatterns): array
    {
        $resolved = array_flip(array_map([self::class, 'normalise'], $resolvedPatterns));
        $violations = [];

        foreach ($blocks as $block) {
            $slug = self::normalise($block);
            foreach ($this->requiredPatterns([$slug]) as $pattern) {
                $entry = $this->patterns[$pattern] ?? null;

                if ($entry === null) {
                    $violations[] = [
                        'block' => $slug,
                        'pattern' => $pattern,
                        'reason' => 'the block declares this pattern and the manifest does not describe it',
                    ];
                    continue;
                }

                // A pattern with no stylesheet cannot have undelivered CSS.
                if (empty($entry['css'])) {
                    continue;
                }

                if (!isset($resolved[$pattern])) {
                    $violations[] = [
                        'block' => $slug,
                        'pattern' => $pattern,
                        'reason' => 'the pattern has CSS and this request will not deliver it',
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Blocks that declare no pattern at all.
     *
     * Informational, never a violation: these render from CSS that always ships. Exposed so a
     * caller can tell "nothing to check" apart from "checked and clean".
     *
     * @param  array<int, string> $blocks block names
     * @return array<int, string> those with no declared pattern
     */
    public function blocksWithoutPatterns(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $slug = self::normalise($block);
            if (empty($this->blockPatterns[$slug])) {
                $out[] = $slug;
            }
        }

        return $out;
    }
}
