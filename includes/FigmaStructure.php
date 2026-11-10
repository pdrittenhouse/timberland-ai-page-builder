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
     * The text a node contains, in document order, deduplicated.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private static function texts(array $node, int $limit, int $depth = 0): array
    {
        if ($depth > 14) {
            return [];
        }

        $out = [];

        if (($node['type'] ?? '') === 'TEXT' && !empty($node['characters'])) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $node['characters']) ?? '');

            if ($text !== '') {
                $out[] = mb_substr($text, 0, 80);
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (count($out) >= $limit) {
                break;
            }

            if (is_array($child)) {
                $out = array_merge($out, self::texts($child, $limit - count($out), $depth + 1));
            }
        }

        return array_slice(array_values(array_unique($out)), 0, $limit);
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
