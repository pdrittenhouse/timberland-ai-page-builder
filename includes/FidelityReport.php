<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How much of a confirmed plan actually survived into the generated block tree.
 *
 * **Why this exists.** The pipeline reported `0 validation errors` while the generated page did not
 * look like the design, and those were never in tension: a clean validation means every field NAME
 * is real. It says nothing about whether the right blocks were chosen, in the right order, carrying
 * the right copy. Nothing measured that, so "it looks better" was the only signal available -- which
 * is how prompt-tuning becomes unfalsifiable, and is why FG4 puts this BEFORE any tuning.
 *
 * **Deliberately not a score.** There is no single number here, because a single number invites
 * tuning the number. Four independent signals are reported, each one checkable by hand against the
 * design, and each failing differently:
 *
 *   sections   did every band become something? a 6-band design that produced 4 top-level blocks
 *              dropped two sections, and nothing else in the pipeline notices
 *   text       what fraction of the plan's copy reached the output? the measure that catches
 *              a page which looks structurally right and says nothing the design said
 *   images     the design's image slots against the output's. 27 image fills reaching zero image
 *              fields is invisible to every other check
 *   order      are the sections in the plan's order? a reordered page validates perfectly
 *
 * **It compares the plan to the tree, not the design to the page.** The plan is what the operator
 * confirmed, so a low score is a generation problem; the plan's own faithfulness to the Figma file
 * is FG4's projection work, measured separately. Keeping those apart is what makes either number
 * mean anything.
 *
 * No browser and no model: this is VP4's millisecond tier, a comparison of two arrays.
 */
class FidelityReport
{
    /**
     * Text shorter than this is skipped when measuring coverage.
     *
     * A one-word string matches too easily -- "Shop" appears in half the pages on a commerce site --
     * so counting it rewards a page that happens to contain common words. Long strings are the ones
     * that only match if the copy actually travelled.
     */
    private const MIN_TEXT_LENGTH = 12;

    /**
     * @param array<string, mixed> $decomposition the plan the operator confirmed
     * @param array<string, mixed> $block_tree    what /structure produced from it
     *
     * @return array{
     *     sections: array{planned: int, produced: int, covered: bool},
     *     text: array{planned: int, found: int, coverage: float, missing: array<int, string>},
     *     images: array{planned: int, produced: int},
     *     order: array{preserved: bool, planned: array<int, string>, produced: array<int, string>, checked: int},
     *     notes: array<int, string>
     * }
     */
    public static function compare(array $decomposition, array $block_tree): array
    {
        $sections = array_values(array_filter((array) ($decomposition['sections'] ?? []), 'is_array'));
        $blocks = array_values(array_filter((array) ($block_tree['blocks'] ?? []), 'is_array'));

        $flat = [];
        self::flatten($blocks, $flat);

        $text = self::text_coverage($sections, $flat);
        $images = self::image_counts($sections, $flat);
        $order = self::order($sections, $flat);

        $notes = [];

        if (count($blocks) < count($sections)) {
            $notes[] = sprintf(
                '%d of %d sections produced no top-level block.',
                count($sections) - count($blocks),
                count($sections)
            );
        }

        if ($text['planned'] > 0 && $text['coverage'] < 0.5) {
            $notes[] = sprintf(
                'Only %d%% of the plan\'s copy reached the output.',
                (int) round($text['coverage'] * 100)
            );
        }

        if ($images['planned'] > 0 && 0 === $images['produced']) {
            $notes[] = sprintf(
                'The plan describes %d image slot(s); the output sets none.',
                $images['planned']
            );
        }

        if (!$order['preserved']) {
            $notes[] = 'Sections appear in a different order from the plan.';
        }

        return [
            'sections' => [
                'planned' => count($sections),
                'produced' => count($blocks),
                'covered' => count($blocks) >= count($sections),
            ],
            'text' => $text,
            'images' => $images,
            'order' => $order,
            'notes' => $notes,
        ];
    }

    /**
     * Every block in the tree, depth-first, in document order.
     *
     * Order matters for the order check, so this must not be a set.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $out
     */
    private static function flatten(array $nodes, array &$out, int $depth = 0): void
    {
        if ($depth > 24) {
            return;
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $out[] = $node;

            $children = $node['children'] ?? [];

            if (is_array($children)) {
                self::flatten($children, $out, $depth + 1);
            }
        }
    }

    /**
     * Which of the plan's strings appear anywhere in the output.
     *
     * Compared on normalised text, not identity: a generator may legitimately change the case of a
     * heading or trim a trailing period, and counting that as lost copy would make the measure
     * report failures that are not failures.
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array<string, mixed>> $flat
     * @return array{planned: int, found: int, coverage: float, missing: array<int, string>}
     */
    private static function text_coverage(array $sections, array $flat): array
    {
        $planned = [];

        foreach ($sections as $section) {
            foreach (self::section_strings($section) as $string) {
                if (mb_strlen($string) >= self::MIN_TEXT_LENGTH) {
                    $planned[self::normalise($string)] = $string;
                }
            }
        }

        $haystack = self::normalise(self::tree_text($flat));
        $missing = [];
        $found = 0;

        foreach ($planned as $key => $original) {
            if ('' !== $key && str_contains($haystack, $key)) {
                ++$found;
                continue;
            }

            $missing[] = $original;
        }

        return [
            'planned' => count($planned),
            'found' => $found,
            'coverage' => count($planned) > 0 ? round($found / count($planned), 3) : 1.0,
            // Capped: the point is to show WHICH copy went missing, not to reproduce the plan.
            'missing' => array_slice($missing, 0, 10),
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array<int, string>
     */
    private static function section_strings(array $section): array
    {
        $out = [];
        $content = $section['content'] ?? [];

        if (is_string($content)) {
            $out[] = $content;
        } elseif (is_array($content)) {
            foreach ($content as $value) {
                if (is_string($value)) {
                    $out[] = $value;
                }
            }
        }

        // The band's composition carries the per-element copy -- a grid's item titles live here and
        // nowhere else, and they are exactly the copy a page most often drops.
        foreach ((array) ($section['structure'] ?? []) as $group) {
            if (is_array($group)) {
                foreach ((array) ($group['texts'] ?? []) as $text) {
                    if (is_string($text)) {
                        $out[] = $text;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $flat
     */
    private static function tree_text(array $flat): string
    {
        $parts = [];

        foreach ($flat as $node) {
            $data = $node['data'] ?? [];

            if (is_array($data)) {
                array_walk_recursive($data, static function ($value) use (&$parts): void {
                    if (is_string($value)) {
                        $parts[] = $value;
                    }
                });
            }

            foreach (['content', 'text', 'html'] as $key) {
                if (isset($node[$key]) && is_string($node[$key])) {
                    $parts[] = $node[$key];
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Image slots the plan describes, against image fields the output actually sets.
     *
     * The output side counts fields whose NAME marks them as an image, since the tree carries no
     * types. Blunt, and it is the honest blunt: an undercount here reads as lost imagery, which is
     * the direction that prompts a look rather than the direction that hides a problem.
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array<string, mixed>> $flat
     * @return array{planned: int, produced: int}
     */
    private static function image_counts(array $sections, array $flat): array
    {
        $planned = 0;

        foreach ($sections as $section) {
            foreach ((array) ($section['structure'] ?? []) as $group) {
                if (is_array($group)) {
                    $planned += (int) ($group['images'] ?? 0);
                }
            }
        }

        $produced = 0;

        foreach ($flat as $node) {
            foreach ((array) ($node['data'] ?? []) as $field => $value) {
                if (!is_string($field) || !str_contains($field, 'image')) {
                    continue;
                }

                if ('' !== $value && null !== $value && false !== $value && 'null' !== $value) {
                    ++$produced;
                }
            }
        }

        return ['planned' => $planned, 'produced' => $produced];
    }

    /**
     * Do the plan's section titles appear in the plan's order?
     *
     * A reordered page validates perfectly and is wrong, so order is its own signal. Sections whose
     * title never appears are skipped rather than counted as out of order -- that is the text
     * measure's job, and conflating them would report one fault twice.
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array<string, mixed>> $flat
     * @return array{preserved: bool, planned: array<int, string>, produced: array<int, string>, checked: int}
     */
    private static function order(array $sections, array $flat): array
    {
        $haystack = self::normalise(self::tree_text($flat));
        $planned = [];
        $positions = [];

        foreach ($sections as $section) {
            $title = '';

            if (is_array($section['content'] ?? null)) {
                $title = (string) ($section['content']['title'] ?? '');
            }

            // Short titles are excluded, for the same reason the coverage measure excludes short
            // strings -- and this one was measured, not anticipated. On the real Home page "Herbs"
            // matched at offset 160, inside the nav strip "Shop Herbs Journal Our story", which
            // placed section 4 ahead of sections 2 and 3 and reported the order as broken when it
            // was not. Measuring four titles reliably beats measuring six wrongly.
            // Subsumes the empty-title case: nothing shorter than the minimum gets this far.
            if (mb_strlen($title) < self::MIN_TEXT_LENGTH) {
                continue;
            }

            $planned[] = $title;
            $needle = self::normalise($title);
            $at = '' !== $needle ? strpos($haystack, $needle) : false;

            if (false !== $at) {
                $positions[$title] = $at;
            }
        }

        $produced = array_keys($positions);
        asort($positions);

        return [
            'preserved' => $produced === array_keys($positions),
            'planned' => $planned,
            'produced' => $produced,
            // How many titles the verdict rests on. Without it, `preserved: true` reads as "all
            // sections are in order" when it may mean "the three long enough to check are".
            'checked' => count($positions),
        ];
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
