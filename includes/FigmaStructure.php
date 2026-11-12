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

    /**
     * How long a single text node may be before it is cut.
     *
     * Was 90, which cut body copy mid-word -- "made with plants you can under" -- so the model was
     * matching blocks against a truncated sentence. 90 was cost control from when the payload was
     * 3.9 MB and the whole budget was 8,000 tokens with ~400 to spare; the payload is now projected
     * and the budget is settable, so the constraint that justified it is gone. 400 is long enough
     * for real body copy and still short enough that a pasted essay cannot run away.
     */
    private const MAX_TEXT_LENGTH = 400;

    /**
     * Grouping tolerance, as a fraction of the BAND's width -- see `groups()`.
     *
     * Proportional to the band, not to the element. A flat 12px failed on the real file: the six
     * category tiles measure 585, 570, 573, 585, 572 and 572 px wide because someone nudged them,
     * so they split into four groups and the plan said "2 repeated + 2 single" for what is plainly
     * a six-up grid. Scaling the tolerance by the ELEMENT's own size instead is the obvious fix and
     * is wrong: the divisor then grows with the value, so 1280 and 1728 collapse into one bucket
     * too. Anchoring to the band's width keeps 3% of 1728 = ~52px, which merges the six tiles and
     * still separates 1280 from 1728.
     */
    private const SIZE_TOLERANCE_RATIO = 0.03;

    /** Floor for the above, so a narrow band does not get a sub-pixel tolerance. */
    private const MIN_SIZE_TOLERANCE = 8;

    /** A band child smaller than this in either dimension is furniture, not structure. */
    private const MIN_CHILD_SIZE = 24;

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
     * @return array{page: string, width: int, height: int, y: int, bands: array<int, array<string, mixed>>}|\WP_Error
     */
    public static function bands(array $payload, string $page_id, int $max_texts = 24)
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
                // `Frame 19`. Still capped -- a product grid can carry fifty strings -- but at 24
                // rather than 6, because 6 meant a six-up grid arrived as one heading.
                'texts' => $texts,
                // FG4: what the band is MADE OF, which is the thing a block-matcher actually needs.
                //
                // Measured before this existed: the Home frame holds 65 text nodes, 61 nested
                // frames and 27 image fills, and the plan carried 16 strings and no structure at
                // all. A 1728x1115 band reached the model as two strings and a height, so it could
                // not tell a six-up card grid from a split hero from a carousel -- and the output
                // was about what that input supported.
                'children' => self::groups($child),
            ];
        }

        usort($bands, static fn(array $a, array $b): int => $a['y'] <=> $b['y']);

        $box = $page['absoluteBoundingBox'] ?? [];

        return [
            'page' => self::readable_name($page),
            'width' => (int) ($box['width'] ?? 0),
            'height' => (int) ($box['height'] ?? 0),
            // FG5 needs each band's offset from ITS OWN page top, and band `y` is absolute canvas
            // position -- page frames sit side by side on the canvas, so two identical headers in
            // two frames have completely different `y`. Comparing the raw values finds nothing, or
            // worse, finds a coincidence. The page's own origin is returned so the comparison can
            // subtract it; `bands` keeps absolute `y` because its own sort and every existing
            // consumer already read it that way.
            'y' => (int) ($box['y'] ?? 0),
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
    /**
     * A band's direct children, grouped so that repetition is visible.
     *
     * **The grouping is the point, not the listing.** "6x group 573x558, each with an image" tells a
     * block-matcher it is looking at a six-up grid; six separate lines of nearly-identical
     * dimensions make it infer that, and it should not have to. The prototype for this grouped on
     * exact dimensions and produced no groups at all on the real file -- the six category tiles
     * measure 585, 570, 573, 585, 572 and 572 px wide, because a designer nudged them. Hence
     * {@see SIZE_TOLERANCE}: sizes are rounded to a 12px bucket before comparison.
     *
     * Only DIRECT children are walked. Depth is where this would get expensive and stop being a
     * projection -- the Home frame has 61 nested frames, and describing all of them is the 3.9 MB
     * payload again. One level answers the question that matters ("what is this band made of") and
     * the text of each group carries the rest.
     *
     * @param array<string, mixed> $band
     * @return array<int, array{count: int, type: string, width: int, height: int, images: int, image_refs: array<int, string>, texts: array<int, string>}>
     */
    private static function groups(array $band): array
    {
        $buckets = [];
        $band_width = (int) ($band['absoluteBoundingBox']['width'] ?? 0);
        $tolerance = max(self::MIN_SIZE_TOLERANCE, $band_width * self::SIZE_TOLERANCE_RATIO);

        foreach ($band['children'] ?? [] as $child) {
            if (!is_array($child) || in_array((string) ($child['type'] ?? ''), self::DECORATIVE, true)) {
                continue;
            }

            $box = $child['absoluteBoundingBox'] ?? [];
            $w = (int) ($box['width'] ?? 0);
            $h = (int) ($box['height'] ?? 0);

            if ($w < self::MIN_CHILD_SIZE || $h < self::MIN_CHILD_SIZE) {
                continue;
            }

            // NOT keyed by node type. A designer builds one tile as a GROUP and its neighbour as a
            // FRAME without meaning anything by it, and including the type split the six category
            // tiles again after the tolerance was fixed. What a block-matcher needs is "six things
            // this size, each with an image", and the type contributes nothing to that.
            $key = implode('|', [
                (int) round($w / $tolerance),
                (int) round($h / $tolerance),
            ]);

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'count' => 0,
                    'type' => strtolower((string) ($child['type'] ?? '')),
                    'width' => $w,
                    'height' => $h,
                    'images' => 0,
                    'image_refs' => [],
                    'texts' => [],
                ];
            }

            ++$buckets[$key]['count'];

            // "This member paints an image" and "we can fetch that image" are DIFFERENT facts, and
            // counting only the fetchable ones hides the unimportable case. A test caught this:
            // tying the count to a resolvable ref reported six image tiles as zero. The count stays
            // the honest observation; the refs are what an import can act on, and a gap between
            // them is itself worth seeing.
            if (self::paints_image($child)) {
                ++$buckets[$key]['images'];
            }

            $refs = self::image_refs($child);

            if ($refs !== []) {
                // Union across the group's members, deduped. Six tiles of the same size usually
                // carry six DIFFERENT photographs, and collapsing them to one would import a sixth
                // of the design's imagery.
                $buckets[$key]['image_refs'] = array_values(
                    array_unique(array_merge($buckets[$key]['image_refs'], $refs))
                );
            }

            // Text from the FIRST member only. In a six-up grid the members differ by their copy,
            // and repeating all six sets is how a projection turns back into a payload -- the
            // band's own `texts` already carries the full set for whoever needs it.
            if ($buckets[$key]['texts'] === []) {
                $buckets[$key]['texts'] = array_column(self::texts($child, 4), 'text');
            }
        }

        return array_values($buckets);
    }

    /**
     * Does this subtree paint an image at all, ref or not?
     *
     * Separate from {@see image_refs} on purpose. An IMAGE fill normally carries an `imageRef`, but
     * one that does not is still an image the design has and the page is missing -- reporting it as
     * "no image here" would be the comfortable answer rather than the true one.
     *
     * @param array<string, mixed> $node
     */
    private static function paints_image(array $node, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }

        foreach ($node['fills'] ?? [] as $fill) {
            if (is_array($fill) && ($fill['type'] ?? '') === 'IMAGE') {
                return true;
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && self::paints_image($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The image fills in this subtree, as Figma's own `imageRef` values.
     *
     * An image fill is the difference between "a heading and some text" and "a card with a picture",
     * and 27 of them in the Home frame reached the model as zero.
     *
     * **Refs, not a boolean.** Knowing an image EXISTS is what the first version reported, and it
     * left the model nothing it could act on: the fidelity measure then showed 10 image slots
     * described and 0 set, which turned out to be unreachable rather than ignored -- the plan said
     * "image" and gave no way to reference one. `fills[].imageRef` is already in the payload
     * `fetch_node()` returns, so carrying the actual identity costs nothing and is what
     * `/v1/files/{key}/images` resolves to a downloadable asset.
     *
     * Recursive because the fill usually sits on a rectangle several levels below the thing a person
     * would call the card.
     *
     * @param array<string, mixed> $node
     * @return array<int, string> distinct refs, in document order
     */
    private static function image_refs(array $node, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $refs = [];

        foreach ($node['fills'] ?? [] as $fill) {
            if (is_array($fill) && ($fill['type'] ?? '') === 'IMAGE' && !empty($fill['imageRef'])) {
                $refs[] = (string) $fill['imageRef'];
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $refs = array_merge($refs, self::image_refs($child, $depth + 1));
            }
        }

        return array_values(array_unique($refs));
    }

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
                    'text' => mb_substr($text, 0, self::MAX_TEXT_LENGTH),
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
