<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\ChromeHints;
use TimberlandAIPageBuilder\FigmaStructure;

/**
 * FG5 — label page chrome, never omit it.
 *
 * The asymmetry these pin: a wrong guess that DELETES is invisible, and a wrong guess that KEEPS
 * costs one click. So every assertion here is about what the label SAYS, and the one thing no test
 * can find is a filter, because there isn't one to find.
 */
final class ChromeHintsTest extends TestCase
{
    /**
     * @param array<int, array{y: int, height: int, id?: string}> $bands
     * @return array<string, mixed>
     */
    private function page(int $y, int $height, array $bands): array
    {
        return [
            'page' => 'Frame',
            'height' => $height,
            'y' => $y,
            'bands' => array_map(
                static fn(array $b, int $i): array => [
                    'id' => $b['id'] ?? ('b' . $y . '-' . $i),
                    'y' => $y + $b['y'],
                    'height' => $b['height'],
                ],
                $bands,
                array_keys($bands)
            ),
        ];
    }

    public function test_a_band_repeated_at_the_top_of_several_frames_is_likely_chrome(): void
    {
        // Frames sit side by side on the canvas, so the two pages here start at different absolute
        // y. Comparing raw `y` would find nothing; the offset from each page's own origin matches.
        $labels = ChromeHints::label([
            $this->page(0, 1115, [['id' => 'nav-a', 'y' => 0, 'height' => 112], ['y' => 112, 'height' => 900]]),
            $this->page(4000, 1115, [['id' => 'nav-b', 'y' => 0, 'height' => 112], ['y' => 112, 'height' => 700]]),
        ]);

        $this->assertTrue($labels['nav-a']['likely']);
        $this->assertTrue($labels['nav-b']['likely']);
        $this->assertSame(2, $labels['nav-a']['seen_in']);
        $this->assertStringContainsString('top of the page', $labels['nav-a']['reason']);
    }

    public function test_the_real_files_trap_a_tall_bottom_band_that_appears_once(): void
    {
        // Natural Rose's bottom band is 1728x550 and carries real category links a page might
        // legitimately want. A detector keying on "tall thing at the bottom" would call it a footer
        // and -- had it been allowed to act -- delete a navigation section somebody designed.
        $labels = ChromeHints::label([
            $this->page(0, 1115, [['y' => 0, 'height' => 112], ['id' => 'links', 'y' => 565, 'height' => 550]]),
            $this->page(4000, 900, [['y' => 0, 'height' => 112], ['y' => 112, 'height' => 788]]),
        ]);

        $this->assertFalse($labels['links']['likely'], 'position alone must not make it chrome');
        $this->assertSame(1, $labels['links']['seen_in']);
        $this->assertStringContainsString('only this frame', $labels['links']['reason']);
    }

    public function test_a_band_repeated_in_the_middle_is_reported_as_repeated_and_not_chrome(): void
    {
        // A shared content module, not furniture. Reported honestly either way -- the reviewer can
        // disagree with the reasoning, which is the point of words instead of a score.
        $labels = ChromeHints::label([
            $this->page(0, 2000, [['y' => 0, 'height' => 112], ['id' => 'cta-a', 'y' => 800, 'height' => 400]]),
            $this->page(4000, 2000, [['y' => 0, 'height' => 112], ['id' => 'cta-b', 'y' => 800, 'height' => 400]]),
        ]);

        $this->assertFalse($labels['cta-a']['likely']);
        $this->assertSame(2, $labels['cta-a']['seen_in']);
        $this->assertStringContainsString('shared content module', $labels['cta-a']['reason']);
    }

    public function test_a_footer_is_measured_by_its_bottom_edge_not_its_top(): void
    {
        // A 550px footer on a 1115px page STARTS at 565, which is the middle of the page by any
        // measure of where it begins. Keying on the top would never find a tall footer.
        $labels = ChromeHints::label([
            $this->page(0, 1115, [['id' => 'f-a', 'y' => 565, 'height' => 550]]),
            $this->page(4000, 1115, [['id' => 'f-b', 'y' => 565, 'height' => 550]]),
        ]);

        $this->assertTrue($labels['f-a']['likely']);
        $this->assertStringContainsString('bottom of the page', $labels['f-a']['reason']);
    }

    public function test_one_frame_means_unknown_rather_than_no_chrome(): void
    {
        // Saying "no chrome found" against a single frame would be a claim the data cannot support.
        // `comparable: false` is what lets the panel say detection did not run.
        $labels = ChromeHints::label([
            $this->page(0, 1115, [['id' => 'solo', 'y' => 0, 'height' => 112]]),
        ]);

        $this->assertFalse($labels['solo']['likely']);
        $this->assertFalse($labels['solo']['comparable'], 'one frame cannot show repetition');
        $this->assertStringContainsString('cannot be measured', $labels['solo']['reason']);
    }

    public function test_a_few_pixels_of_hand_placement_still_counts_as_the_same_band(): void
    {
        // Exact comparison finds nothing in a real file: frames are placed by hand.
        $labels = ChromeHints::label([
            $this->page(0, 1115, [['id' => 'nav-a', 'y' => 0, 'height' => 112]]),
            $this->page(4000, 1115, [['id' => 'nav-b', 'y' => 8, 'height' => 114]]),
        ]);

        $this->assertTrue($labels['nav-a']['likely']);
        $this->assertSame(2, $labels['nav-a']['seen_in']);
    }

    public function test_a_repetitive_frame_cannot_outvote_the_file(): void
    {
        // Counted per FRAME, not per matching band. A page stacking three identical 112px strips
        // would otherwise report `seen_in: 3` from one frame and read as file-wide repetition.
        $labels = ChromeHints::label([
            $this->page(0, 2000, [
                ['id' => 's1', 'y' => 0, 'height' => 112],
                ['id' => 's2', 'y' => 10, 'height' => 112],
                ['id' => 's3', 'y' => 20, 'height' => 112],
            ]),
            $this->page(4000, 2000, [['id' => 'other', 'y' => 900, 'height' => 500]]),
        ]);

        $this->assertSame(1, $labels['s1']['seen_in'], 'three bands in one frame is one frame');
        $this->assertFalse($labels['s1']['likely']);
    }

    public function test_the_output_carries_no_score_to_threshold_on(): void
    {
        // A number invites a threshold and a threshold starts omitting things, which is the exact
        // failure this item exists to prevent.
        $labels = ChromeHints::label([
            $this->page(0, 1115, [['id' => 'nav-a', 'y' => 0, 'height' => 112]]),
            $this->page(4000, 1115, [['id' => 'nav-b', 'y' => 0, 'height' => 112]]),
        ]);

        $this->assertSame(
            ['likely', 'reason', 'seen_in', 'of', 'comparable'],
            array_keys($labels['nav-a']),
            'no confidence, score or probability key may appear here'
        );
        $this->assertIsBool($labels['nav-a']['likely']);
    }

    public function test_against_the_real_file_it_flags_two_bands_of_twelve(): void
    {
        // The same untidied capture the rest of the Figma suite uses -- 3 page frames, assets loose
        // on the canvas, bands with no useful names. The value here is the RESTRAINT: 2 of 12
        // labelled, not a page full of amber badges, and the Home frame's tall bottom band is not
        // among them because it appears once.
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json'),
            true
        );

        $read = [];

        foreach (FigmaStructure::pages($payload, 0)['pages'] as $page) {
            $bands = FigmaStructure::bands($payload, $page['id']);

            if (is_array($bands)) {
                $read[] = $bands;
            }
        }

        $labels = ChromeHints::label($read);
        $flagged = array_filter($labels, static fn(array $l): bool => $l['likely']);

        $this->assertCount(12, $labels, 'every band in every frame gets a label');
        $this->assertCount(2, $flagged, 'a repeated top band in two of three frames, and nothing else');

        foreach ($flagged as $label) {
            $this->assertStringContainsString('2 of 3 page frames', $label['reason']);
            $this->assertStringContainsString('top of the page', $label['reason']);
        }
    }
}
