<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaDecomposer;
use TimberlandAIPageBuilder\FigmaStructure;

/**
 * FG4 steps 1-2: a band says what it is MADE OF, and its copy is not cut mid-word.
 *
 * Measured before this existed, on the real Home frame: 65 text nodes and 3,686 characters became
 * 16 strings and 735 characters, 61 nested frames became nothing, and 27 image fills became
 * nothing. A 1728x1115 band reached the model as two strings and a height, so it could not tell a
 * six-up card grid from a split hero -- and the generated page was about what that input supported.
 */
class FigmaBandStructureTest extends TestCase
{
    /** @return array<string, mixed> */
    private function home(): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json'),
            true
        );

        $home = null;

        foreach (FigmaStructure::pages($payload)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $this->assertNotNull($home);

        return FigmaStructure::bands($payload, $home['id']);
    }

    public function test_a_band_carries_its_composition(): void
    {
        $bands = $this->home()['bands'];
        $this->assertNotEmpty($bands);

        foreach ($bands as $band) {
            $this->assertArrayHasKey('children', $band, 'every band must say what it is made of');
            $this->assertIsArray($band['children']);
        }

        // At least one band must actually describe something, or the projection is formally
        // present and materially empty -- which is the state this replaced.
        $described = array_filter($bands, static fn(array $b): bool => $b['children'] !== []);
        $this->assertNotEmpty($described);
    }

    public function test_repeated_elements_are_grouped_with_a_count(): void
    {
        // The whole point. Six similar tiles must arrive as "6x", not as six lines a matcher has
        // to infer a grid from.
        $counts = [];

        foreach ($this->home()['bands'] as $band) {
            foreach ($band['children'] as $group) {
                $counts[] = (int) $group['count'];
            }
        }

        $this->assertNotEmpty($counts);
        $this->assertGreaterThan(1, max($counts), 'no band reported any repetition at all');
    }

    public function test_a_designers_nudge_does_not_split_a_grid(): void
    {
        // The real file's six category tiles are 585, 570, 573, 585, 572 and 572 px wide. A flat
        // 12px tolerance split them into four groups and the plan said "2 repeated + 2 single" for
        // something plainly six-up. Scaling by the ELEMENT's own size is the obvious fix and is
        // also wrong -- the divisor then grows with the value and 1280 collapses into 1728 -- so
        // the tolerance is a fraction of the BAND's width.
        $band = [
            'id' => '1:1',
            'name' => 'Grid',
            'type' => 'FRAME',
            'absoluteBoundingBox' => ['width' => 1728, 'height' => 1115, 'y' => 0],
            'children' => [],
        ];

        foreach ([585, 570, 573, 585, 572, 572] as $i => $w) {
            $band['children'][] = [
                'id' => "2:{$i}",
                'type' => 0 === $i % 2 ? 'GROUP' : 'FRAME',
                'absoluteBoundingBox' => ['width' => $w, 'height' => 558, 'y' => 0],
                'fills' => [['type' => 'IMAGE']],
                'children' => [],
            ];
        }

        $payload = ['nodes' => ['0:1' => ['document' => [
            'id' => '0:1',
            'name' => 'Page',
            'type' => 'FRAME',
            'absoluteBoundingBox' => ['width' => 1728, 'height' => 1115, 'y' => 0],
            'children' => [$band],
        ]]]];

        $groups = FigmaStructure::bands($payload, '0:1')['bands'][0]['children'];

        $this->assertCount(1, $groups, 'six nudged tiles must be ONE group');
        $this->assertSame(6, $groups[0]['count']);
        $this->assertSame(6, $groups[0]['images'], 'each tile paints an image');
    }

    public function test_distinct_sizes_are_not_merged(): void
    {
        // The other half of the tolerance: a 1280-wide content frame and a 1728-wide nav bar are
        // not the same element, and a proportional-to-element tolerance would have merged them.
        $mk = static fn(string $id, int $w, int $h): array => [
            'id' => $id,
            'type' => 'FRAME',
            'absoluteBoundingBox' => ['width' => $w, 'height' => $h, 'y' => 0],
            'children' => [['type' => 'TEXT', 'characters' => "t{$id}", 'style' => ['fontSize' => 20],
                'absoluteBoundingBox' => ['y' => 0]]],
        ];

        $payload = ['nodes' => ['0:1' => ['document' => [
            'id' => '0:1',
            'name' => 'Page',
            'type' => 'FRAME',
            'absoluteBoundingBox' => ['width' => 1728, 'height' => 2000, 'y' => 0],
            'children' => [[
                'id' => '1:1',
                'type' => 'FRAME',
                'absoluteBoundingBox' => ['width' => 1728, 'height' => 971, 'y' => 0],
                'children' => [$mk('2:1', 1280, 859), $mk('2:2', 1728, 112)],
            ]],
        ]]]];

        $groups = FigmaStructure::bands($payload, '0:1')['bands'][0]['children'];

        $this->assertCount(2, $groups, '1280 and 1728 are different elements');
    }

    public function test_body_copy_is_no_longer_cut_mid_word(): void
    {
        // The 90-character clip produced "made with plants you can under" in a real plan.
        $sentence = 'Thoughtfully crafted herbal remedies for everyday wellness, made with plants '
            . 'you can understand and trust, in a sentence comfortably past the old ninety.';

        $payload = ['nodes' => ['0:1' => ['document' => [
            'id' => '0:1',
            'name' => 'Page',
            'type' => 'FRAME',
            'absoluteBoundingBox' => ['width' => 1728, 'height' => 900, 'y' => 0],
            'children' => [[
                'id' => '1:1',
                'type' => 'FRAME',
                'absoluteBoundingBox' => ['width' => 1728, 'height' => 900, 'y' => 0],
                'children' => [['type' => 'TEXT', 'characters' => $sentence,
                    'style' => ['fontSize' => 18], 'absoluteBoundingBox' => ['y' => 0]]],
            ]],
        ]]]];

        $text = FigmaStructure::bands($payload, '0:1')['bands'][0]['texts'][0]['text'];

        $this->assertSame($sentence, $text);
        $this->assertGreaterThan(90, mb_strlen($text));
    }

    public function test_the_section_intent_counts_rather_than_names_the_block(): void
    {
        // "6 repeated elements" is an observation an operator can check against their own file.
        // "a card grid" is a block choice, and making it here moves matching out of the only place
        // that can see the catalogue.
        $d = FigmaDecomposer::from_page($this->home(), 'FILE');
        $intents = implode(' | ', array_column($d['sections'], 'intent'));

        $this->assertStringContainsString('repeated element', $intents);
        $this->assertStringNotContainsString('card grid', strtolower($intents));
        $this->assertStringNotContainsString('carousel', strtolower($intents));
    }

    public function test_structure_reaches_the_decomposition(): void
    {
        // bands() carrying it is not enough -- the first version of this change stopped there and
        // the decomposer silently dropped it, so the plan was unchanged.
        $d = FigmaDecomposer::from_page($this->home(), 'FILE');
        $withStructure = array_filter(
            $d['sections'],
            static fn(array $s): bool => !empty($s['structure'])
        );

        $this->assertNotEmpty($withStructure, 'the decomposition dropped the band composition');
    }

    public function test_an_empty_group_is_not_described(): void
    {
        // A sized rectangle with no image and no text tells a matcher nothing and costs tokens.
        $payload = ['nodes' => ['0:1' => ['document' => [
            'id' => '0:1',
            'name' => 'Page',
            'type' => 'FRAME',
            'absoluteBoundingBox' => ['width' => 1728, 'height' => 400, 'y' => 0],
            'children' => [[
                'id' => '1:1',
                'type' => 'FRAME',
                'absoluteBoundingBox' => ['width' => 1728, 'height' => 400, 'y' => 0],
                'children' => [['id' => '2:1', 'type' => 'FRAME',
                    'absoluteBoundingBox' => ['width' => 600, 'height' => 300, 'y' => 0], 'children' => []]],
            ]],
        ]]]];

        $bands = FigmaStructure::bands($payload, '0:1');
        $section = FigmaDecomposer::from_page($bands, 'FILE')['sections'][0];

        $this->assertSame([], $section['structure']);
    }
}
