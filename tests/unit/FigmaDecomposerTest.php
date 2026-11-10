<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaDecomposer;
use TimberlandAIPageBuilder\FigmaStructure;

/**
 * A Figma page becomes the same layout plan a prompt does, so the EXISTING review step covers it.
 *
 * `/structure`'s own docblock says it builds "from a confirmed layout plan", so the confirmation
 * seam already exists. These tests pin that the Figma path produces that shape rather than a
 * parallel one -- a Figma import with its own flow would be the single path where nobody checks
 * the plan, and it is the path where checking matters most, because the operator can see their
 * own file and the model cannot.
 */
class FigmaDecomposerTest extends TestCase
{
    /** @return array{page: string, width: int, height: int, bands: array<int, array<string, mixed>>} */
    private function homePage(): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json'),
            true
        );

        $home = null;

        foreach (FigmaStructure::pages($payload, 0)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $this->assertNotNull($home);
        $bands = FigmaStructure::bands($payload, $home['id']);
        $this->assertIsArray($bands);

        return $bands;
    }

    public function test_it_produces_the_shape_decompose_produces(): void
    {
        // /structure accepts `decomposition`; anything else would need a second pipeline.
        $d = FigmaDecomposer::from_page($this->homePage(), 'FILEKEY');

        $this->assertArrayHasKey('sections', $d);
        $this->assertArrayHasKey('overall_intent', $d);
        $this->assertArrayHasKey('suggested_pattern_ids', $d);
        $this->assertNotEmpty($d['sections']);

        foreach ($d['sections'] as $section) {
            $this->assertArrayHasKey('intent', $section);
            $this->assertArrayHasKey('pattern_hint', $section);
            $this->assertArrayHasKey('pattern_id', $section);
            $this->assertArrayHasKey('content', $section);
        }
    }

    public function test_every_section_carries_its_node_id(): void
    {
        // FG3: values are pulled by node id. A plan that cannot say which frame a section came
        // from makes a later provenance claim unverifiable -- and the first version emitted an
        // empty node for every section while claiming to carry one.
        $d = FigmaDecomposer::from_page($this->homePage(), 'FILEKEY');

        foreach ($d['sections'] as $section) {
            $this->assertMatchesRegularExpression('/^\d+:\d+$/', $section['figma']['node']);
            $this->assertSame('FILEKEY', $section['figma']['file']);
        }
    }

    public function test_it_does_not_guess_a_block(): void
    {
        // Matching is a judgement made with the catalogue in front of it. Guessing here would be
        // a second ranking heuristic to keep correct.
        $d = FigmaDecomposer::from_page($this->homePage(), 'FILEKEY');

        foreach ($d['sections'] as $section) {
            $this->assertSame('', $section['pattern_hint']);
            $this->assertSame('', $section['pattern_id']);
        }

        $this->assertSame([], $d['suggested_pattern_ids']);
    }

    public function test_content_is_ranked_by_font_size_not_document_order(): void
    {
        $d = FigmaDecomposer::from_page($this->homePage(), 'FILEKEY');

        $this->assertSame('Wellness, rooted in nature', $d['sections'][0]['content']['title']);
    }

    public function test_the_intent_describes_rather_than_interprets(): void
    {
        // An operator is reading this to CATCH mistakes. "a product showcase section" is a guess
        // presented as a finding; dimensions plus the largest text can be checked at a glance.
        $intent = FigmaDecomposer::from_page($this->homePage(), 'FILEKEY')['sections'][0]['intent'];

        $this->assertStringContainsString('Band 1', $intent);
        $this->assertStringContainsString('1728x971', $intent);
        $this->assertStringContainsString('Wellness, rooted in nature', $intent);
    }

    public function test_absent_content_keys_are_omitted_not_empty(): void
    {
        // Matches /decompose's own rule: an unspecified field is left out rather than sent empty.
        $page = ['page' => 'X', 'width' => 100, 'height' => 100, 'bands' => [
            ['id' => '1:1', 'name' => '', 'type' => 'FRAME', 'width' => 100, 'height' => 50, 'y' => 0, 'texts' => []],
        ]];

        $section = FigmaDecomposer::from_page($page)['sections'][0];

        $this->assertSame([], $section['content']);
        $this->assertStringContainsString('no text', $section['intent']);
    }
}
