<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turn a chosen Figma page into the same layout plan a prompt produces.
 *
 * **The review step already exists, so this feeds it rather than adding one.** The pipeline is
 * `/decompose` -> the operator confirms -> `/structure` -> `/assemble`, and `/structure`'s own
 * docblock says it builds "from a CONFIRMED layout plan". A Figma URL therefore has nothing new to
 * confirm: it produces a `decomposition` in the identical shape, the operator reviews the bands the
 * way they review a decomposed prompt, and the two inputs converge instead of growing parallel
 * flows with parallel confirmation screens.
 *
 * That convergence is the point. A Figma import that bypassed confirmation would be the one path
 * where nobody checks the plan, and it is the path where checking matters MOST -- the operator can
 * see their own Figma file and the model cannot.
 *
 * **Every section carries its node id** (FG3). The discipline is "values are pulled by node id,
 * never eyeballed", and a plan that records which Figma frame each section came from is what makes
 * a later provenance claim checkable rather than asserted. It also survives the round trip: the
 * operator can reorder or delete sections and each surviving one still knows its origin.
 *
 * Nothing here chooses a block. `pattern_hint` and `pattern_id` are left empty deliberately -- that
 * is a matching judgement, made by the model with the catalogue in front of it, and guessing here
 * would be a second ranking heuristic to keep correct.
 */
class FigmaDecomposer
{
    /**
     * @param array{page: string, width: int, height: int, bands: array<int, array<string, mixed>>} $page
     * @return array{sections: array<int, array<string, mixed>>, overall_intent: string, suggested_pattern_ids: array<int, string>, figma: array<string, mixed>}
     */
    public static function from_page(array $page, string $file_key = ''): array
    {
        $sections = [];

        foreach ($page['bands'] ?? [] as $index => $band) {
            $texts = $band['texts'] ?? [];

            $sections[] = [
                'intent' => self::intent($band, $index),
                // Left to the matching step, with the catalogue in front of it.
                'pattern_hint' => '',
                'pattern_id' => '',
                'content' => self::content($texts),
                /**
                 * FG3 provenance, per section rather than per page: a band is what becomes a
                 * block, so the node id has to travel at band granularity or the trail breaks the
                 * moment the operator reorders anything.
                 */
                'figma' => [
                    'file' => $file_key,
                    'node' => (string) ($band['id'] ?? ''),
                    'width' => (int) ($band['width'] ?? 0),
                    'height' => (int) ($band['height'] ?? 0),
                ],
            ];
        }

        return [
            'sections' => $sections,
            'overall_intent' => self::overall($page),
            'suggested_pattern_ids' => [],
            'figma' => [
                'file' => $file_key,
                'page' => (string) ($page['page'] ?? ''),
                'width' => (int) ($page['width'] ?? 0),
                'height' => (int) ($page['height'] ?? 0),
            ],
        ];
    }

    /**
     * What this band appears to be, in words an operator can check at a glance.
     *
     * Deliberately descriptive rather than interpretive: "band 3 of 6, 1728x1035, largest text
     * 'Customer Favorites, Naturally'" is reviewable, where "a product showcase section" is a
     * guess presented as a finding. The operator is reading this to catch mistakes, and a
     * confident wrong label is harder to catch than a plain description.
     *
     * @param array<string, mixed> $band
     */
    private static function intent(array $band, int $index): string
    {
        $texts = $band['texts'] ?? [];
        $largest = '';
        $size = 0;

        foreach ($texts as $text) {
            if ((int) $text['size'] > $size) {
                $size = (int) $text['size'];
                $largest = (string) $text['text'];
            }
        }

        $parts = [sprintf('Band %d, %dx%d', $index + 1, $band['width'] ?? 0, $band['height'] ?? 0)];

        if (($band['name'] ?? '') !== '') {
            $parts[] = 'named "' . $band['name'] . '"';
        }

        if ($largest !== '') {
            $parts[] = sprintf('largest text %dpx "%s"', $size, $largest);
        } else {
            $parts[] = 'no text (likely an image or decorative band)';
        }

        return implode(', ', $parts);
    }

    /**
     * Title / subtitle / body from the band's text, by prominence.
     *
     * Font size is the only hierarchy signal available -- the names are `Frame 19` -- so the
     * largest text becomes the title, the next distinct size the subtitle, and the longest
     * remaining string the body. Keys are OMITTED when there is nothing for them, matching
     * `/decompose`'s own rule that an unspecified field is left out rather than sent empty.
     *
     * @param array<int, array{text: string, size: int}> $texts
     * @return array<string, string>
     */
    private static function content(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $sorted = $texts;
        usort($sorted, static fn(array $a, array $b): int => $b['size'] <=> $a['size']);

        $content = [];
        $content['title'] = (string) $sorted[0]['text'];

        foreach (array_slice($sorted, 1) as $text) {
            if ((int) $text['size'] !== (int) $sorted[0]['size']) {
                $content['subtitle'] = (string) $text['text'];
                break;
            }
        }

        // The longest string that is not already used -- body copy is long and small, so length
        // separates it from a label of the same size.
        $used = array_values($content);
        $body = '';

        foreach ($sorted as $text) {
            $candidate = (string) $text['text'];

            if (!in_array($candidate, $used, true) && mb_strlen($candidate) > mb_strlen($body)) {
                $body = $candidate;
            }
        }

        if ($body !== '' && mb_strlen($body) > 40) {
            $content['body'] = $body;
        }

        return $content;
    }

    /**
     * @param array{page?: string, bands?: array<int, mixed>, width?: int, height?: int} $page
     */
    private static function overall(array $page): string
    {
        $name = (string) ($page['page'] ?? '');

        return sprintf(
            'Figma page %s with %d band(s), %dx%d.',
            $name !== '' ? '"' . $name . '"' : '(unnamed)',
            count($page['bands'] ?? []),
            (int) ($page['width'] ?? 0),
            (int) ($page['height'] ?? 0)
        );
    }
}
