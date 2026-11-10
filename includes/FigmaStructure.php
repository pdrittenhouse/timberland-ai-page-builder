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
     * Candidate page designs, largest first, with the evidence needed to choose between them.
     *
     * **Enumerate mechanically, choose semantically.** This does NOT decide what a page is. It
     * cannot: "is this a page design or a logo sheet?" is a semantic question, your file proves
     * names cannot answer it (`Frame 19` twice), and geometry alone cannot either -- `Product
     * Review Image Only` at 683x582 is a real design and a 683x582 mood board would not be. So
     * this surfaces candidates broadly with their evidence attached, and a model picks. The
     * budget caps what that costs, and `report_gap` means "I cannot tell which of these is a
     * page" is a reportable answer rather than a guess.
     *
     * **It descends, because a model cannot choose what it was never shown.** A file may nest its
     * pages inside a wrapper frame, and the earlier version only looked at canvas children --
     * which would surface the wrapper and nothing else. Every level up to `$max_depth` is
     * enumerated and each candidate reports the depth and parent it was found at, so a wrapper
     * and the pages inside it are both on the table and distinguishable.
     *
     * SECTION is no longer special-cased. It happened to be where this file keeps its pages, and
     * special-casing the one file we have seen is how the next file breaks.
     *
     * @param array<string, mixed> $payload a `/v1/files/{key}/nodes` response
     * @return array{pages: array<int, array<string, mixed>>, skipped: int, truncated: int, source: string}
     */
    public static function pages(array $payload, int $max_depth = 2, int $limit = 40): array
    {
        $found = [];
        $skipped = 0;
        $source = '';

        foreach ($payload['nodes'] ?? [] as $wrapper) {
            $document = $wrapper['document'] ?? null;

            if (!is_array($document)) {
                continue;
            }

            $source = (string) ($document['type'] ?? '');

            // A CANVAS is a container of candidates; any other requested node IS a candidate,
            // because the caller asked for that node specifically.
            if ($source === 'CANVAS') {
                foreach ($document['children'] ?? [] as $child) {
                    if (is_array($child)) {
                        self::gather($child, '', 0, $max_depth, $found, $skipped);
                    }
                }
            } else {
                self::gather($document, '', 0, $max_depth, $found, $skipped);
            }
        }

        usort($found, static fn(array $a, array $b): int => ($b['width'] * $b['height']) <=> ($a['width'] * $a['height']));

        $truncated = max(0, count($found) - $limit);

        return [
            'pages' => array_slice($found, 0, $limit),
            'skipped' => $skipped,
            // Reported, because a silently cut list is indistinguishable from a complete one.
            'truncated' => $truncated,
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed>              $node
     * @param array<int, array<string, mixed>>  $found
     */
    private static function gather(
        array $node,
        string $parent,
        int $depth,
        int $max_depth,
        array &$found,
        int &$skipped
    ): void {
        $type = (string) ($node['type'] ?? '');
        $box = $node['absoluteBoundingBox'] ?? [];
        $width = (float) ($box['width'] ?? 0);

        if (in_array($type, self::DECORATIVE, true) || $width < self::MIN_PAGE_WIDTH) {
            $skipped++;

            return;
        }

        $children = array_values(array_filter(
            $node['children'] ?? [],
            static fn($c): bool => is_array($c) && !in_array((string) ($c['type'] ?? ''), self::DECORATIVE, true)
        ));

        $found[] = [
            'id' => (string) ($node['id'] ?? ''),
            'name' => self::readable_name($node),
            'type' => $type,
            'width' => (int) $width,
            'height' => (int) ($box['height'] ?? 0),
            'bands' => count($children),
            'depth' => $depth,
            'parent' => $parent,
            // Sampled by PROMINENCE, not position.
            //
            // Sampling the topmost text gave every page the same two strings -- "Shop Herbs
            // Journal Our story" at 14px, which is the nav bar sitting at y=48 on every page in
            // the file. Identical evidence on every candidate is no evidence. The largest text is
            // what distinguishes them: "All Products", "Our Roots. Our Reason. Our Future.",
            // "What Our Customers are Saying".
            //
            // Bands keep reading order, because there sequence is the point. Different question,
            // different ordering.
            'sample' => self::prominent($node, 2),
        ];

        if ($depth >= $max_depth) {
            return;
        }

        $label = $node['name'] ?? '';
        $label = self::readable_name($node) !== '' ? (string) $label : ($type . ' ' . ($node['id'] ?? ''));

        foreach ($children as $child) {
            self::gather($child, $label, $depth + 1, $max_depth, $found, $skipped);
        }
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
                // The node id travels with the band, not just with the page. A band is what
                // becomes a block, so FG3's "values are pulled by node id" needs band
                // granularity -- and the trail has to survive the operator reordering or
                // deleting sections during review. Omitting it left every section's provenance
                // blank while the decomposition claimed to carry one.
                'id' => (string) ($child['id'] ?? ''),
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
     * The most prominent text in a node -- largest font first.
     *
     * For telling candidates APART, where the biggest type is the identifying one. Ties break on
     * position so the result is deterministic rather than dependent on tree order.
     *
     * @param array<string, mixed> $node
     * @return array<int, array{text: string, size: int}>
     */
    private static function prominent(array $node, int $limit): array
    {
        $collected = [];
        self::collect_text($node, $collected, 0);

        usort(
            $collected,
            static fn(array $a, array $b): int => [$b['size'], $a['y']] <=> [$a['size'], $b['y']]
        );

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
