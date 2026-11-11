<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FidelityReport;

/**
 * FG4 step 3 -- the measure that makes "did it get better" answerable.
 *
 * The pipeline reported `0 validation errors` while the page did not look like the design, and
 * those were never in tension: a clean validation means every field NAME is real. These signals
 * measure the thing it does not.
 *
 * On the real Home page the measure immediately found what validation could not: the plan describes
 * 10 image slots and the output sets none.
 */
class FidelityReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private function plan(): array
    {
        return ['sections' => [
            ['content' => ['title' => 'Wellness, rooted in nature', 'subtitle' => 'Thoughtfully crafted remedies'],
                'structure' => [['count' => 1, 'images' => 1, 'texts' => ['Watch the video now']]]],
            ['content' => ['title' => 'Customer Favorites, Naturally'],
                'structure' => [['count' => 6, 'images' => 6, 'texts' => ['Sleep and Rest comfortably']]]],
        ]];
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function tree(array $blocks): array
    {
        return ['blocks' => $blocks];
    }

    public function test_a_faithful_tree_reports_full_coverage(): void
    {
        $tree = $this->tree([
            ['block' => 'acf/section', 'data' => ['title' => 'Wellness, rooted in nature',
                'subtitle' => 'Thoughtfully crafted remedies', 'image' => 12],
                'children' => [['block' => 'acf/card', 'data' => ['body' => 'Watch the video now']]]],
            ['block' => 'acf/section', 'data' => ['title' => 'Customer Favorites, Naturally',
                'card_image' => 44], 'children' => [['block' => 'acf/card',
                'data' => ['t' => 'Sleep and Rest comfortably']]]],
        ]);

        $r = FidelityReport::compare($this->plan(), $tree);

        $this->assertSame(2, $r['sections']['planned']);
        $this->assertSame(2, $r['sections']['produced']);
        $this->assertTrue($r['sections']['covered']);
        $this->assertSame(1.0, $r['text']['coverage']);
        $this->assertSame([], $r['notes']);
    }

    public function test_a_dropped_section_is_reported(): void
    {
        // Two bands, one block. Nothing else in the pipeline notices.
        $r = FidelityReport::compare($this->plan(), $this->tree([
            ['block' => 'acf/section', 'data' => ['title' => 'Wellness, rooted in nature']],
        ]));

        $this->assertFalse($r['sections']['covered']);
        $this->assertStringContainsString('produced no top-level block', implode(' ', $r['notes']));
    }

    public function test_missing_copy_is_named_not_just_counted(): void
    {
        // "82% coverage" is not actionable; knowing WHICH three strings vanished is.
        $r = FidelityReport::compare($this->plan(), $this->tree([
            ['block' => 'acf/section', 'data' => ['title' => 'Wellness, rooted in nature']],
            ['block' => 'acf/section', 'data' => ['x' => 'nothing relevant here at all']],
        ]));

        $this->assertLessThan(1.0, $r['text']['coverage']);
        $this->assertNotEmpty($r['text']['missing']);
        $this->assertStringContainsString('Customer Favorites', implode(' | ', $r['text']['missing']));
    }

    public function test_images_described_but_never_set_is_reported(): void
    {
        // The finding that explained a real page not looking like its design: 10 planned, 0 set.
        $r = FidelityReport::compare($this->plan(), $this->tree([
            ['block' => 'acf/section', 'data' => ['title' => 'Wellness, rooted in nature']],
            ['block' => 'acf/section', 'data' => ['title' => 'Customer Favorites, Naturally']],
        ]));

        $this->assertSame(7, $r['images']['planned']);
        $this->assertSame(0, $r['images']['produced']);
        $this->assertStringContainsString('image slot', implode(' ', $r['notes']));
    }

    public function test_an_unset_image_field_does_not_count_as_set(): void
    {
        // ACF writes `false`, `''`, `'null'` or `null` for an empty field, and counting any of
        // those would report imagery that is not there -- the direction that hides a problem.
        foreach ([false, '', 'null', null] as $empty) {
            $r = FidelityReport::compare($this->plan(), $this->tree([
                ['block' => 'acf/section', 'data' => ['image' => $empty]],
            ]));

            $this->assertSame(0, $r['images']['produced'], 'an empty value is not an image');
        }
    }

    public function test_a_reordered_page_is_reported(): void
    {
        $r = FidelityReport::compare($this->plan(), $this->tree([
            ['block' => 'acf/section', 'data' => ['title' => 'Customer Favorites, Naturally']],
            ['block' => 'acf/section', 'data' => ['title' => 'Wellness, rooted in nature']],
        ]));

        $this->assertFalse($r['order']['preserved']);
        $this->assertStringContainsString('different order', implode(' ', $r['notes']));
    }

    public function test_a_short_title_is_not_used_for_order(): void
    {
        // The trap this measure fell into on its first real run. "Herbs" matched at offset 160
        // inside the nav strip "Shop Herbs Journal Our story", which placed section 4 ahead of
        // sections 2 and 3 and reported the order as broken when it was not. Measuring fewer
        // titles reliably beats measuring more of them wrongly.
        $plan = ['sections' => [
            ['content' => ['title' => 'Wellness, rooted in nature']],
            ['content' => ['title' => 'Herbs']],
            ['content' => ['title' => 'Our Roots. Our Reason.']],
        ]];

        $tree = $this->tree([
            ['block' => 'acf/section', 'data' => ['nav' => 'Shop Herbs Journal Our story',
                'title' => 'Wellness, rooted in nature']],
            ['block' => 'acf/section', 'data' => ['title' => 'Our Roots. Our Reason.']],
        ]);

        $r = FidelityReport::compare($plan, $tree);

        $this->assertTrue($r['order']['preserved'], '"Herbs" must not be trusted for ordering');
        $this->assertNotContains('Herbs', $r['order']['planned']);
        $this->assertSame(2, $r['order']['checked']);
    }

    public function test_the_order_verdict_says_how_many_titles_it_rests_on(): void
    {
        // Otherwise `preserved: true` reads as "all sections are in order" when it may mean "the
        // two long enough to check are".
        $r = FidelityReport::compare($this->plan(), $this->tree([
            ['block' => 'acf/section', 'data' => ['title' => 'Wellness, rooted in nature']],
        ]));

        $this->assertArrayHasKey('checked', $r['order']);
        $this->assertSame(1, $r['order']['checked']);
    }

    public function test_copy_is_matched_despite_case_and_punctuation(): void
    {
        // A generator may legitimately change case or drop a trailing period; counting that as
        // lost copy would report failures that are not failures.
        $r = FidelityReport::compare(
            ['sections' => [['content' => ['title' => 'Our Roots. Our Reason. Our Future.']]]],
            $this->tree([['block' => 'acf/section', 'data' => ['h' => 'our roots our reason our future']]])
        );

        $this->assertSame(1.0, $r['text']['coverage']);
    }

    public function test_an_empty_plan_is_not_a_failure(): void
    {
        $r = FidelityReport::compare(['sections' => []], $this->tree([]));

        $this->assertSame(1.0, $r['text']['coverage'], 'nothing planned is fully covered');
        $this->assertSame([], $r['notes']);
    }

    public function test_copy_nested_deep_in_the_tree_still_counts(): void
    {
        // A card grid puts its item copy several levels down; a shallow read would report it lost.
        $r = FidelityReport::compare(
            ['sections' => [['content' => ['title' => 'Customer Favorites, Naturally']]]],
            $this->tree([['block' => 'acf/section', 'children' => [['block' => 'acf/row',
                'children' => [['block' => 'acf/card',
                    'data' => ['title' => 'Customer Favorites, Naturally']]]]]]])
        );

        $this->assertSame(1.0, $r['text']['coverage']);
    }
}
