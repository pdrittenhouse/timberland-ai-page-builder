<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which blocks a generation prompt gets to see.
 *
 * **One copy, because two copies produced the same bug twice.** `StructurePromptBuilder` and
 * `PromptBuilder` each carried a near-identical `filter_blocks_for_prompt()` and `score_block()`,
 * differing only in whether the description was scored. Both therefore carried every defect below.
 * This is the third duplicated-logic failure in this subsystem (the others: a fence-stripper in two
 * parsers, and a per-code error map in the editor shadowing the server's), so the logic lives here
 * and the callers keep only the one thing that genuinely differs.
 *
 * Three measured defects are fixed here, all of which starved the model of blocks:
 *
 * 1. **Keywords matched as bare substrings.** `acf/table-cell`'s keyword is `th`, which appears in
 *    *"the"*; `acf/table-row`'s is `tr`, inside *"Treat"*. Those two blocks therefore scored above
 *    zero against essentially any English prompt.
 * 2. **The fallback was gated on a count**, `count($selected) <= count(CORE_BLOCKS)`. Combined with
 *    defect 1 that made it unreachable: `th` and `tr` alone pushed the count past the threshold, so
 *    `COMMON_BLOCKS` -- the entire set of content blocks -- was effectively dead code in both
 *    builders. Measured on the real manifest: a Figma-derived prompt got **7 blocks out of 48**,
 *    none of them content blocks, and the model then invented fields on the structural ones it had.
 * 3. **Only the operator's prose was scored.** A Figma-derived prompt describes provenance ("from
 *    the Figma frame", "treat the text as the real copy") rather than content, so it scores content
 *    blocks at zero. The content signal was sitting unused in the confirmed plan itself -- section
 *    titles like "Customer Favorites, Naturally" and "Sleep & Rest".
 */
class BlockRelevance
{
    /** Always present: the layout primitives every page is built out of. */
    public const CORE_BLOCKS = [
        'acf/section',
        'acf/row',
        'acf/column',
    ];

    /** The safety net, when nothing specific was matched. */
    public const COMMON_BLOCKS = [
        'acf/card',
        'acf/card-grid',
        'acf/button',
        'acf/jumbotron',
        'acf/hero-unit',
        'acf/feature',
        'acf/promo',
        'acf/text-block',
    ];

    /**
     * @param array<string, array<string, mixed>> $all_blocks the manifest's blocks
     * @param array<string, mixed>                $decomposition a confirmed layout plan, when there is one
     * @param bool                                $score_description preserved per caller, see below
     *
     * @return array<string, array<string, mixed>>
     */
    public static function select(
        array $all_blocks,
        string $user_prompt,
        array $decomposition = [],
        bool $score_description = false
    ): array {
        // Defect 3: the plan's own content is scored alongside the prose. Appended rather than
        // substituted, because an operator's wording still carries intent the design cannot
        // ("make the testimonials a carousel").
        $corpus = strtolower(trim($user_prompt . ' ' . self::plan_text($decomposition)));
        $words = preg_split('/[\s,.\-\/]+/', $corpus, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $selected = [];

        foreach (self::CORE_BLOCKS as $name) {
            if (isset($all_blocks[$name])) {
                $selected[$name] = $all_blocks[$name];
            }
        }

        foreach ($decomposition['sections'] ?? [] as $section) {
            $hint = strtolower((string) ($section['pattern_hint'] ?? ''));

            if ('' === $hint) {
                continue;
            }

            foreach ($all_blocks as $name => $block) {
                if (isset($selected[$name])) {
                    continue;
                }

                $short = str_replace('acf/', '', $name);
                $title = strtolower((string) ($block['title'] ?? ''));

                if (str_contains($hint, $short) || ('' !== $title && str_contains($hint, $title))) {
                    $selected[$name] = $block;
                }
            }
        }

        foreach ($all_blocks as $name => $block) {
            if (isset($selected[$name])) {
                continue;
            }

            if (self::score($block + ['name' => $name], $words, $corpus, $score_description) > 0) {
                $selected[$name] = $block;
            }
        }

        // Defect 2: the question is whether a block that can actually HOLD CONTENT was found, not
        // how many entries exist. Counting let one accidental keyword hit suppress the entire net.
        if (!self::has_content_block($selected)) {
            foreach (self::COMMON_BLOCKS as $name) {
                if (isset($all_blocks[$name]) && !isset($selected[$name])) {
                    $selected[$name] = $all_blocks[$name];
                }
            }
        }

        return $selected;
    }

    /**
     * Has anything been selected that could actually carry a section's content?
     *
     * **Read from the manifest, not from a hand-kept list.** The first version of this asked
     * "anything outside CORE_BLOCKS?", which is a guess dressed as a rule -- and its own regression
     * test caught it: `acf/table-cell` is not core, so one stray keyword hit on it still counted as
     * "we found content" and still killed the fallback.
     *
     * A block declaring `parent` cannot be placed freely; it is a part of a composite, not a
     * candidate for a band. Measured on the real manifest: 11 of 48 are parent-restricted, and the
     * three that accidentally matched a Figma-derived prompt -- `table-cell`, `table-row`,
     * `button-text` -- are all among them. So the discriminator the data already carries is exactly
     * the one needed, and it needs no maintenance as blocks are added.
     *
     * @param array<string, array<string, mixed>> $selected
     */
    private static function has_content_block(array $selected): bool
    {
        foreach ($selected as $name => $block) {
            if (in_array($name, self::CORE_BLOCKS, true)) {
                continue;
            }

            if (empty($block['parent'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything in a confirmed plan that describes what the page SAYS.
     *
     * @param array<string, mixed> $decomposition
     */
    private static function plan_text(array $decomposition): string
    {
        $parts = [(string) ($decomposition['overall_intent'] ?? '')];

        foreach ($decomposition['sections'] ?? [] as $section) {
            $parts[] = (string) ($section['intent'] ?? '');
            $parts[] = (string) ($section['pattern_hint'] ?? '');

            // `content` is a string in at least one shape the decomposer emits, so this cannot
            // assume an array -- an existing characterisation test covers exactly that case.
            $content = $section['content'] ?? [];

            if (is_string($content)) {
                $parts[] = $content;
            } elseif (is_array($content)) {
                foreach ($content as $value) {
                    if (is_scalar($value)) {
                        $parts[] = (string) $value;
                    }
                }
            }
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string>   $words
     */
    private static function score(array $block, array $words, string $corpus, bool $score_description): int
    {
        $score = 0;

        $short_name = str_replace('acf/', '', (string) ($block['name'] ?? ''));

        foreach (preg_split('/[\-_]+/', $short_name) ?: [] as $part) {
            if (in_array($part, $words, true)) {
                $score += 3;
            }
        }

        foreach (preg_split('/[\s\-_]+/', strtolower((string) ($block['title'] ?? ''))) ?: [] as $word) {
            if (in_array($word, $words, true)) {
                $score += 2;
            }
        }

        foreach ($block['keywords'] ?? [] as $keyword) {
            if (self::keyword_matches($corpus, (string) $keyword)) {
                $score += 2;
            }
        }

        // Only PromptBuilder scored descriptions. Kept behind a flag rather than unified, because
        // turning it on for /structure or off for /generate would be an unmeasured change to
        // generation quality smuggled in behind a bug fix.
        if ($score_description && '' !== (string) ($block['description'] ?? '')) {
            if (self::text_overlaps($corpus, strtolower((string) $block['description']))) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * Defect 1: a keyword has to match a WORD, not a run of letters inside one.
     *
     * A trailing `s`/`es` is allowed so `card` still matches "cards" -- the plural is the common
     * case and losing it would trade one starvation bug for another. What is deliberately not
     * allowed is an open-ended prefix match: that is what let `th` match "the" and turn
     * `acf/table-cell` into a block that matches all English.
     */
    private static function keyword_matches(string $corpus, string $keyword): bool
    {
        $keyword = trim(strtolower($keyword));

        if ('' === $keyword) {
            return false;
        }

        return 1 === preg_match('/\b' . preg_quote($keyword, '/') . '(?:e?s)?\b/', $corpus);
    }

    /**
     * Ported verbatim from `PromptBuilder`, direction and stop-list included.
     *
     * Note it walks the PROMPT's words and looks for each inside the block text, not the reverse --
     * I had it backwards on the first pass, which silently changes which blocks score.
     */
    private static function text_overlaps(string $corpus, string $text): bool
    {
        $stop_words = [
            'a', 'an', 'the', 'is', 'are', 'was', 'were', 'with', 'and', 'or', 'for', 'to', 'in',
            'on', 'at', 'of', 'that', 'this', 'it', 'me', 'my', 'i', 'we', 'our', 'make', 'create',
            'add', 'build', 'generate', 'page', 'post',
        ];

        foreach (preg_split('/[\s,.\-\/]+/', $corpus, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) > 2 && !in_array($word, $stop_words, true) && str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
