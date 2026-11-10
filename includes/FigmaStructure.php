<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reduce a Figma node payload to the few hundred tokens a matching model can use.
 *
 * **Why reduction is the whole job.** One canvas of the Natural Rose file is 4,474 nodes, 13
 * levels deep, 3.9 MB of JSON. Nothing downstream can read that, and almost none of it is
 * relevant: 1,156 VECTOR and 527 RECTANGLE nodes describe how a thing is drawn, not what it is.
 * The useful signal is a page's ordered BANDS and the text inside them, which comes to a few
 * hundred tokens.
 *
 * **Two assumptions from the plan that a real file contradicted, measured 2026-11-10:**
 *
 * 1. *"Top-level frames are the page designs."* On this file the canvas has 86 children --
 *    11 SECTION, 57 FRAME, 7 GROUP, 11 RECTANGLE -- and **52 of the 57 frames are 32x32 icon
 *    litter** (`caret-down 1`, `check-square 1`, `noun_Ribbon_5537025 3`). The pages live in
 *    SECTIONs named Home, About, Shop, Navigation, Product. A reader that treats canvas frames
 *    as pages gets icons.
 *
 * 2. *"Frame names are semantic hints."* Inside SECTION "Home" the bands are named `Frame 19`,
 *    `Frame 18`, `Frame 22`, `Frame 19` -- two of them identically. The names carry nothing. What
 *    identifies a band is its TEXT ("Wellness, rooted in nature", "Explore all products",
 *    "Our story") and its proportions, and the plan does not mention text extraction at all.
 *
 * So names are used when they look meaningful and ignored when they are `Frame \d+`, text is
 * always extracted, and geometry carries order and proportion. A file whose frames ARE named well
 * loses nothing by this -- the name is still reported.
 */
class FigmaStructure
{
    /**
     * Below this width a canvas-level node is scenery, not a page.
     *
     * 320 separates cleanly on the measured file: 5 real designs at 1728px-ish against 52 icons at
     * 32px. It is a threshold, not a certainty, so {@see self::pages()} reports what it skipped.
     */
    private const MIN_PAGE_WIDTH = 320;

    /** Nodes that describe how something is drawn rather than what it is. */
    private const DECORATIVE = ['VECTOR', 'LINE', 'ELLIPSE', 'RECTANGLE', 'STAR', 'POLYGON', 'BOOLEAN_OPERATION'];

    /** U+2028 / U+2029. Figma uses these inside a single TEXT node to separate list items. */
    private const LINE_SEPARATOR = "\u{2028}";
    private const PARAGRAPH_SEPARATOR = "\u{2029}";

    /** A name like `Frame 19` or `Group 33` is Figma's default and says nothing. */
    private const MEANINGLESS_NAME = '/^(frame|group|rectangle|ellipse|vector|section|component)\s*\d*$/i';

    /**
     * Candidate page designs in a node payload, largest first.
     *
     * @param array<string, mixed> $payload a `/v1/files/{key}/nodes` response
     * @return array{pages: array<int, array<string, mixed>>, skipped: int, source: string}
     */
    public static function pages(array $payload): array
    {
        $pages = [];
        $skipped = 0;
        $source = '';

        foreach ($payload['nodes'] ?? [] as $node_id => $wrapper) {
            $document = $wrapper['document'] ?? null;

            if (!is_array($document)) {
                continue;
            }

            $type = (string) ($document['type'] ?? '');
            $source = $type;

            // A CANVAS is a container of candidates; anything else IS the candidate, because the
            // caller asked for that node specifically.
            $candidates = $type === 'CANVAS' ? ($document['children'] ?? []) : [$document];

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $box = $candidate['absoluteBoundingBox'] ?? [];
                $width = (float) ($box['width'] ?? 0);

                if (in_array((string) ($candidate['type'] ?? ''), self::DECORATIVE, true)
                    || ($type === 'CANVAS' && $width < self::MIN_PAGE_WIDTH)
                ) {
                    $skipped++;
                    continue;
                }

                $pages[] = [
                    'id' => (string) ($candidate['id'] ?? ''),
                    'name' => self::readable_name($candidate),
                    'type' => (string) ($candidate['type'] ?? ''),
                    'width' => (int) $width,
                    'height' => (int) ($box['height'] ?? 0),
                    'bands' => count($candidate['children'] ?? []),
                ];
            }
        }

        usort($pages, static fn(array $a, array $b): int => ($b['width'] * $b['height']) <=> ($a['width'] * $a['height']));

        return ['pages' => $pages, 'skipped' => $skipped, 'source' => $source];
    }

    /**
     * One page reduced to its ordered bands.
     *
     * Bands are sorted by y, because document order is not reading order -- Figma keeps whatever
     * order the layers panel has, and the measured file relies on position.
     *
     * @param array<string, mixed> $payload
     * @return array{page: string, width: int, height: int, bands: array<int, array<string, mixed>>}|\WP_Error
     */
    public static function bands(array $payload, string $page_id, int $max_texts = 6)
    {
        $page = self::find($payload, $page_id);

        if ($page === null) {
            return new \WP_Error('figma_page_not_found', 'That frame is not in the payload.');
        }

        $bands = [];

        foreach ($page['children'] ?? [] as $child) {
            if (!is_array($child) || in_array((string) ($child['type'] ?? ''), self::DECORATIVE, true)) {
                continue;
            }

            $box = $child['absoluteBoundingBox'] ?? [];
            $texts = self::texts($child, $max_texts);

            $bands[] = [
                'name' => self::readable_name($child),
                'type' => (string) ($child['type'] ?? ''),
                'width' => (int) ($box['width'] ?? 0),
                'height' => (int) ($box['height'] ?? 0),
                'y' => (int) ($box['y'] ?? 0),
                // The band's own text, which is what actually identifies it when the name is
                // `Frame 19`. Capped, because a product grid can carry fifty strings and the
                // first few are the heading and the first item -- which is the identifying part.
                'texts' => $texts,
            ];
        }

        usort($bands, static fn(array $a, array $b): int => $a['y'] <=> $b['y']);

        $box = $page['absoluteBoundingBox'] ?? [];

        return [
            'page' => self::readable_name($page),
            'width' => (int) ($box['width'] ?? 0),
            'height' => (int) ($box['height'] ?? 0),
            'bands' => $bands,
        ];
    }

    /**
     * The text a node contains, with the two things that actually carry meaning.
     *
     * **Font size is the heading/body hierarchy** and the first version threw it away. The
     * measured hero band carries 68px "Wellness, rooted in nature", 20px body copy, 18px "Watch
     * video" and 14px nav links -- without the size those are four indistinguishable strings, and
     * `style.fontSize` is already in the payload. A matching model needs to know which string is
     * the headline.
     *
     * **LINE SEPARATOR is a list separator, not whitespace.** The footer's link columns are single
     * TEXT nodes using U+2028 between items. A `\s+`-to-space collapse -- which is what the first
     * version did -- turns a ten-item link list into one prose phrase, and a model reading prose
     * where there are links matches the wrong block. U+2028/U+2029 become ` / `, which reads as a
     * list and survives the cap.
     *
     * Sorted by y, not document order, for the same reason the bands are: the hero's 14px nav sits
     * at y=48 while its 68px headline is at y=426, so document order puts the headline second.
     *
     * @param array<string, mixed> $node
     * @return array<int, array{text: string, size: int}>
     */
    private static function texts(array $node, int $limit, int $depth = 0): array
    {
        $collected = [];
        self::collect_text($node, $collected, $depth);

        usort($collected, static fn(array $a, array $b): int => $a['y'] <=> $b['y']);

        $seen = [];
        $out = [];

        foreach ($collected as $item) {
            if (isset($seen[$item['text']])) {
                continue;
            }

            $seen[$item['text']] = true;
            $out[] = ['text' => $item['text'], 'size' => $item['size']];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                                 $node
     * @param array<int, array{text: string, size: int, y: float}>  $out
     */
    private static function collect_text(array $node, array &$out, int $depth): void
    {
        if ($depth > 14) {
            return;
        }

        if (($node['type'] ?? '') === 'TEXT' && !empty($node['characters'])) {
            // Separators first, so the whitespace collapse below cannot eat them.
            $raw = str_replace([self::LINE_SEPARATOR, self::PARAGRAPH_SEPARATOR], ' / ', (string) $node['characters']);
            $text = trim((string) preg_replace('/\s+/', ' ', $raw));
            $text = trim($text, " /\t\n\r");

            if ($text !== '') {
                $out[] = [
                    'text' => mb_substr($text, 0, 90),
                    'size' => (int) round((float) ($node['style']['fontSize'] ?? 0)),
                    'y' => (float) ($node['absoluteBoundingBox']['y'] ?? 0),
                ];
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                self::collect_text($child, $out, $depth + 1);
            }
        }
    }

    /**
     * A name worth showing, or '' when Figma's default tells us nothing.
     *
     * @param array<string, mixed> $node
     */
    private static function readable_name(array $node): string
    {
        $name = trim((string) ($node['name'] ?? ''));

        return preg_match(self::MEANINGLESS_NAME, $name) ? '' : $name;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function find(array $payload, string $id, int $depth = 0): ?array
    {
        foreach ($payload['nodes'] ?? [] as $wrapper) {
            $found = self::search($wrapper['document'] ?? [], $id, 0);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private static function search(array $node, string $id, int $depth): ?array
    {
        if ($depth > 14) {
            return null;
        }

        if ((string) ($node['id'] ?? '') === $id) {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (!is_array($child)) {
                continue;
            }

            $found = self::search($child, $id, $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
