<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FG5 — say which bands look like page chrome. Never act on it.
 *
 * ## Why this is a label and not a filter
 *
 * A generated page includes the design's footer as page content, and the tempting fix is to detect
 * header and footer bands and drop them. That is the wrong trade, and the asymmetry is the whole
 * argument: a wrong guess that DELETES is invisible -- the operator never learns that a band
 * existed, and discovers the omission much later, if ever -- while a wrong guess that KEEPS costs
 * one click. So everything here produces a hint for a person who can see their own file, and
 * nothing downstream is permitted to filter on it.
 *
 * ## Repetition is the signal; position only corroborates
 *
 * A band at the same offset and height across several of a file's page frames is almost certainly
 * chrome. One that appears once is content. Position alone is not sufficient and the real file
 * shows why: Natural Rose's nav strip is 1728x112 at the top, which looks like chrome and is, but
 * its bottom band is 1728x550 and carries real category links a page might legitimately want. A
 * detector keying on "tall thing at the bottom" would label that footer and -- had it been allowed
 * to act -- delete a navigation section somebody designed on purpose.
 *
 * So `chrome` requires BOTH: repeated across frames, and sitting at an edge. Repetition in the
 * middle of a page is reported as repeated and explicitly not chrome, because a repeated band at
 * 40% down the page is more likely a shared content module than furniture.
 *
 * ## Deliberately not a confidence score
 *
 * A number invites a threshold, and a threshold starts omitting things -- which is the exact
 * failure this item exists to prevent. The output is a boolean plus the evidence in words, so a
 * reviewer can disagree with the reasoning rather than with a number they cannot inspect.
 *
 * ## One frame means "unknown", not "no chrome"
 *
 * Repetition cannot be measured against a single page frame. Saying "no chrome found" there would
 * be a claim the data does not support, so `comparable` is false and the reason says why. The panel
 * can then tell an operator that chrome detection did not run, rather than implying it ran and
 * found nothing.
 */
final class ChromeHints
{
    /** Two frames is the minimum at which "repeated" means anything at all. */
    public const MIN_PAGES = 2;

    /**
     * Height match tolerance, as a ratio.
     *
     * Matches `FigmaStructure::SIZE_TOLERANCE_RATIO`, and for the same reason: hand-placed frames
     * differ by a pixel or two and an exact comparison finds nothing in a real file.
     */
    public const HEIGHT_TOLERANCE_RATIO = 0.03;

    /** Offset match tolerance in px. A header nudged 8px in one frame is the same header. */
    public const OFFSET_TOLERANCE_PX = 24;

    /**
     * How close to an edge a band must sit to corroborate repetition.
     *
     * A ratio of the page height rather than a pixel count, because a 1115px landing page and a
     * 6000px long-scroll page do not share an absolute sense of "near the bottom".
     */
    public const EDGE_RATIO = 0.08;

    /**
     * Label every band in every page frame.
     *
     * @param array<int, array{page?: string, height?: int, y?: int, bands?: array<int, array<string, mixed>>}> $pages
     *        `FigmaStructure::bands()` results, one per page frame
     *
     * @return array<string, array{likely: bool, reason: string, seen_in: int, of: int, comparable: bool}>
     *         keyed by band id
     */
    public static function label(array $pages): array
    {
        $pages = array_values(array_filter($pages, static fn($p): bool => is_array($p) && !empty($p['bands'])));
        $total = count($pages);
        $out = [];

        if ($total < self::MIN_PAGES) {
            foreach ($pages as $page) {
                foreach ($page['bands'] as $band) {
                    $out[(string) ($band['id'] ?? '')] = [
                        'likely' => false,
                        'reason' => 'Only one page frame in this file, so repetition cannot be measured. '
                            . 'Chrome detection did not run.',
                        'seen_in' => 1,
                        'of' => $total,
                        'comparable' => false,
                    ];
                }
            }

            return $out;
        }

        foreach ($pages as $page) {
            $page_height = (int) ($page['height'] ?? 0);
            $page_y = (int) ($page['y'] ?? 0);

            foreach ($page['bands'] as $band) {
                $id = (string) ($band['id'] ?? '');

                if ('' === $id) {
                    continue;
                }

                $offset = (int) ($band['y'] ?? 0) - $page_y;
                $height = (int) ($band['height'] ?? 0);
                $seen = self::count_matches($pages, $page, $offset, $height);
                $edge = self::edge($offset, $height, $page_height);

                $out[$id] = self::describe($seen, $total, $edge);
            }
        }

        return $out;
    }

    /**
     * In how many page frames a band of this offset and height appears -- including its own.
     *
     * Counted per FRAME, not per matching band: a page with two bands of the same height is one
     * page that has it, and counting bands would let a single repetitive frame outvote the file.
     *
     * @param array<int, array<string, mixed>> $pages
     * @param array<string, mixed>             $self
     */
    private static function count_matches(array $pages, array $self, int $offset, int $height): int
    {
        $seen = 0;

        foreach ($pages as $page) {
            $page_y = (int) ($page['y'] ?? 0);
            $found = false;

            foreach ($page['bands'] as $band) {
                $their_offset = (int) ($band['y'] ?? 0) - $page_y;
                $their_height = (int) ($band['height'] ?? 0);

                if (self::near($offset, $their_offset, self::OFFSET_TOLERANCE_PX)
                    && self::near($height, $their_height, max(1, (int) round($height * self::HEIGHT_TOLERANCE_RATIO)))
                ) {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $seen++;
            }
        }

        return $seen;
    }

    private static function near(int $a, int $b, int $tolerance): bool
    {
        return abs($a - $b) <= $tolerance;
    }

    /** `header`, `footer`, or '' for a band that is not against an edge. */
    private static function edge(int $offset, int $height, int $page_height): string
    {
        if ($page_height <= 0) {
            return '';
        }

        $margin = (int) round($page_height * self::EDGE_RATIO);

        if ($offset <= $margin) {
            return 'header';
        }

        // The band's BOTTOM against the page bottom, not its top: a 550px footer on a 1115px page
        // starts at 565, which is the middle of the page by any measure of where it begins.
        if (($page_height - ($offset + $height)) <= $margin) {
            return 'footer';
        }

        return '';
    }

    /**
     * @return array{likely: bool, reason: string, seen_in: int, of: int, comparable: bool}
     */
    private static function describe(int $seen, int $total, string $edge): array
    {
        $repeated = $seen >= self::MIN_PAGES;

        if ($repeated && '' !== $edge) {
            return [
                'likely' => true,
                'reason' => sprintf(
                    'Appears at the same position and height in %d of %d page frames, at the %s of the page.',
                    $seen,
                    $total,
                    'header' === $edge ? 'top' : 'bottom'
                ),
                'seen_in' => $seen,
                'of' => $total,
                'comparable' => true,
            ];
        }

        if ($repeated) {
            return [
                'likely' => false,
                'reason' => sprintf(
                    'Appears in %d of %d page frames but sits in the middle of the page, so it reads as a shared '
                    . 'content module rather than page furniture.',
                    $seen,
                    $total
                ),
                'seen_in' => $seen,
                'of' => $total,
                'comparable' => true,
            ];
        }

        return [
            'likely' => false,
            'reason' => '' !== $edge
                ? sprintf(
                    'Sits at the %s of the page but appears in only this frame, so it reads as content.',
                    'header' === $edge ? 'top' : 'bottom'
                )
                : 'Appears in only this frame.',
            'seen_in' => $seen,
            'of' => $total,
            'comparable' => true,
        ];
    }
}
