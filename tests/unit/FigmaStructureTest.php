<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaStructure;

/**
 * Reducing a real Figma payload to something a matching model can read.
 *
 * The fixture is a TRIMMED SLICE OF THE ACTUAL Natural Rose file, not a hand-written shape: the
 * Home and Navigation SECTIONs, one large unnamed FRAME, a loose RECTANGLE and four 32x32 icon
 * FRAMEs. It keeps the two things that broke the plan's assumptions -- icon litter at canvas
 * level, and bands named `Frame 19` twice -- because a hand-written fixture would have had
 * neither, and those are exactly what the code exists to survive.
 *
 * Measured against the full payload: 3.9 MB / ~976,000 tokens reduced to ~496 tokens for one page.
 */
class FigmaStructureTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json');
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, 'the fixture must be readable');

        return $decoded;
    }

    public function test_sections_are_the_pages_not_the_canvas_frames(): void
    {
        // The plan assumed top-level frames are the page designs. On a real file the pages are
        // SECTIONs and 52 of 57 canvas frames are icons.
        $found = FigmaStructure::pages($this->payload());
        $names = array_column($found['pages'], 'name');

        $this->assertContains('Home', $names);
        $this->assertContains('Navigation', $names);
        $this->assertSame('CANVAS', $found['source']);
    }

    public function test_icon_litter_and_loose_shapes_are_skipped(): void
    {
        $found = FigmaStructure::pages($this->payload());
        $names = array_column($found['pages'], 'name');

        foreach ($names as $name) {
            $this->assertStringNotContainsString('noun_Ribbon', $name, '32x32 icons are not page designs');
        }

        $this->assertGreaterThan(0, $found['skipped'], 'what was skipped must be reported, not silently dropped');
    }

    public function test_a_large_unnamed_frame_is_still_a_candidate(): void
    {
        // Size is the filter, not naming. A real design with a default name must survive.
        $found = FigmaStructure::pages($this->payload());
        $wide = array_filter($found['pages'], static fn(array $p): bool => $p['width'] >= 320);

        $this->assertNotEmpty($wide);
    }

    public function test_pages_are_ordered_by_area_so_the_biggest_design_leads(): void
    {
        $pages = FigmaStructure::pages($this->payload())['pages'];
        $areas = array_map(static fn(array $p): int => $p['width'] * $p['height'], $pages);
        $sorted = $areas;
        rsort($sorted);

        $this->assertSame($sorted, $areas);
    }

    public function test_bands_come_back_in_reading_order_not_layer_order(): void
    {
        // The fixture's Home children are stored Frame 24, 19, 22, 18, 19, Group 33 -- which is
        // the layers panel, not the page. Sorting by y is what makes the sequence mean anything.
        $payload = $this->payload();
        $home = null;

        foreach (FigmaStructure::pages($payload)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $this->assertNotNull($home);

        $result = FigmaStructure::bands($payload, $home['id']);
        $ys = array_column($result['bands'], 'y');
        $sorted = $ys;
        sort($sorted);

        $this->assertSame($sorted, $ys, 'bands must be sorted top to bottom');
        $this->assertSame('Home', $result['page']);
    }

    public function test_text_is_extracted_because_names_carry_nothing(): void
    {
        // `Frame 19` appears TWICE in this page. The text is the only thing that distinguishes
        // the bands, and the plan does not mention extracting it.
        $payload = $this->payload();
        $home = null;

        foreach (FigmaStructure::pages($payload)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $bands = FigmaStructure::bands($payload, $home['id'])['bands'];
        $allText = implode(' ', array_merge(...array_column($bands, 'texts')));

        $this->assertStringContainsString('Wellness, rooted in nature', $allText);
        $this->assertStringContainsString('Our story', $allText);
    }

    public function test_a_default_figma_name_is_reported_as_empty(): void
    {
        $payload = $this->payload();
        $home = null;

        foreach (FigmaStructure::pages($payload)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $names = array_column(FigmaStructure::bands($payload, $home['id'])['bands'], 'name');

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(Frame|Group)\s*\d+$/',
                $name,
                'a default name must be reported as empty rather than passed off as a hint'
            );
        }
    }

    public function test_an_unknown_page_id_is_an_error_not_an_empty_page(): void
    {
        $result = FigmaStructure::bands($this->payload(), '999:999');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('figma_page_not_found', $result->get_error_code());
    }

    public function test_the_reduction_is_large_enough_to_matter(): void
    {
        $payload = $this->payload();
        $home = null;

        foreach (FigmaStructure::pages($payload)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $reduced = strlen((string) json_encode(FigmaStructure::bands($payload, $home['id'])));
        $rawHome = strlen((string) json_encode($payload));

        // The point of the class. On the full file this is ~2,000:1; even on the trimmed fixture
        // it must be a large fraction smaller, or the output cannot reach a prompt.
        $this->assertLessThan(
            $rawHome / 10,
            $reduced,
            'a reduction under 10x would not fit the token budget'
        );
    }
}
