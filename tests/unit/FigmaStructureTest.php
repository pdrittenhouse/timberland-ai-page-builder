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

    public function test_each_band_carries_its_own_node_id(): void
    {
        // The page's id is not enough: a band is what becomes a block, so provenance has to be
        // resolvable per section. The first version emitted none, and the failure was invisible
        // -- the decomposition still had a `node` key, holding an empty string.
        $payload = $this->payload();
        $home = null;

        foreach (FigmaStructure::pages($payload)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $this->assertNotNull($home);

        $ids = array_column(FigmaStructure::bands($payload, $home['id'])['bands'], 'id');

        $this->assertNotEmpty($ids);
        $this->assertSame($ids, array_filter($ids), 'no band may carry an empty id');

        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^\d+:\d+$/', $id);
        }

        // Distinct ids are the point -- they are how a later fetch addresses one section.
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    /** @return array<int, array{text: string, size: int}> */
    private function homeTexts(): array
    {
        $payload = $this->payload();
        $home = null;

        foreach (FigmaStructure::pages($payload)['pages'] as $p) {
            if ($p['name'] === 'Home') {
                $home = $p;
            }
        }

        $this->assertNotNull($home);
        $bands = FigmaStructure::bands($payload, $home['id'])['bands'];

        return array_merge(...array_column($bands, 'texts'));
    }

    public function test_text_is_extracted_because_names_carry_nothing(): void
    {
        // `Frame 19` appears TWICE in this page. The text is the only thing that distinguishes
        // the bands, and the plan does not mention extracting it.
        $all = implode(' ', array_column($this->homeTexts(), 'text'));

        $this->assertStringContainsString('Wellness, rooted in nature', $all);
        $this->assertStringContainsString('Our Roots. Our Reason. Our Future.', $all);
    }

    public function test_font_size_is_kept_because_it_is_the_heading_hierarchy(): void
    {
        // Without the size, a 68px headline and a 14px nav link are indistinguishable strings.
        $texts = $this->homeTexts();
        $headline = null;

        foreach ($texts as $t) {
            if (str_contains($t['text'], 'Wellness, rooted in nature')) {
                $headline = $t;
            }
        }

        $this->assertNotNull($headline);
        $this->assertGreaterThan(40, $headline['size'], 'the hero headline is 68px and must be reported as large');
        $this->assertGreaterThan(1, count(array_unique(array_column($texts, 'size'))), 'a flat size list carries no hierarchy');
    }

    public function test_line_separators_become_a_readable_list_not_one_phrase(): void
    {
        // The footer's link columns are SINGLE text nodes using U+2028 between items. Collapsing
        // that to spaces turns ten links into one prose phrase, and prose matches a different
        // block than a link list does.
        $all = implode(' ', array_column($this->homeTexts(), 'text'));

        $this->assertStringContainsString('Herbs / Teas / Tinctures', $all);
        $this->assertStringNotContainsString("\u{2028}", $all, 'the raw separator must not reach a consumer');
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

        // Asserted as an ABSOLUTE size, not as a ratio to the fixture.
        //
        // This was `$reduced < $rawHome / 10`, and FG4 broke it legitimately: carrying each band's
        // composition and un-clipped copy took the projection from ~4.5 KB to ~7.4 KB, so the ratio
        // fell under 10x and the test failed for the right reason with the wrong message.
        //
        // The ratio was never the real property anyway. It is an artifact of how much the fixture
        // was trimmed -- the full file reduces ~2,000:1 and the trimmed one cannot, no matter how
        // good the projection is. What has to hold is that ONE PAGE FITS A PROMPT, which is an
        // absolute number in tokens, and it does not drift when the fixture is re-cut.
        //
        // Measured 2026-11-11 on the real file: 719 tokens for the Home page's decomposition
        // against a settable budget whose default is 16,000. 3,000 is ~4x the measured figure --
        // room for a denser page, tight enough to catch a projection that has stopped projecting.
        $this->assertLessThan(
            3000,
            (int) ceil($reduced / 4),
            'one page of projection must fit a prompt; this looks like a payload, not a projection'
        );

        // And it must still be a real reduction, just not a ratio pinned to the fixture's trim.
        $this->assertLessThan($rawHome, $reduced);
    }
}
