<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\RuntimeMerge;

/**
 * AM4 — the build artifact is the base; the registry only adds what the build never saw.
 */
final class RuntimeMergeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function artifact(): array
    {
        return [
            'selection_index' => [
                ['slug' => 'card', 'best_for' => 'Uniform containers.', 'avoid_when' => 'Use Section instead.'],
                ['slug' => 'accordion', 'best_for' => 'Progressive disclosure.'],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function registry(): array
    {
        return [
            'acf/card' => ['description' => 'A card.', 'parent' => null],
            'acf/accordion' => ['description' => 'An accordion.', 'parent' => null],
            'marketplace/pricing-table' => ['description' => 'A pricing table from the marketplace.', 'parent' => null],
            'jetpack/subscriptions' => ['description' => 'Subscribe form.', 'parent' => null],
            'core/paragraph' => ['description' => 'Core paragraph.', 'parent' => null],
        ];
    }

    public function test_the_artifact_is_the_base_and_documented_entries_are_untouched(): void
    {
        $r = (new RuntimeMerge())->merge($this->artifact(), $this->registry());
        $card = null;
        foreach ($r['selection_index'] as $e) {
            if ($e['slug'] === 'card') { $card = $e; }
        }
        $this->assertSame('Uniform containers.', $card['best_for']);
        $this->assertSame('Use Section instead.', $card['avoid_when']);
        $this->assertArrayNotHasKey('x-tl-source', $card);
    }

    public function test_it_adds_a_block_the_build_never_saw(): void
    {
        // The marketplace case: third-party blocks ship into sites that will never be rebuilt.
        $r = (new RuntimeMerge())->merge($this->artifact(), $this->registry());
        // A third-party block KEEPS its namespace. Only `acf/` is stripped, because the
        // manifests key the framework's own blocks bare. Collapsing namespaces would collide --
        // `marketplace/pricing-table` and `jetpack/pricing-table` are different blocks.
        $this->assertSame(['jetpack/subscriptions', 'marketplace/pricing-table'], $r['added']);
    }

    public function test_a_runtime_block_is_marked_as_undocumented_rather_than_presented_as_equal(): void
    {
        // Nobody authored best_for or avoid_when for a third-party block. Saying so is the point:
        // a caller must be able to tell a documented block from an unknown one.
        $r = (new RuntimeMerge())->merge($this->artifact(), $this->registry());
        $added = null;
        foreach ($r['selection_index'] as $e) {
            if ($e['slug'] === 'marketplace/pricing-table') { $added = $e; }
        }
        $this->assertSame('runtime', $added['x-tl-source']);
        $this->assertFalse($added['x-tl-documented']);
        $this->assertSame('marketplace/pricing-table', $added['block_name']);
    }

    public function test_core_is_excluded_by_default(): void
    {
        // 116 of a live install's 207 block types are core. Merging them would spend the entire
        // 3,000-8,000 token budget on blocks the plan does not compose from.
        $r = (new RuntimeMerge())->merge($this->artifact(), $this->registry());
        $this->assertSame(1, $r['excluded']);
        $this->assertNotContains('core/paragraph', $r['added']);
    }

    public function test_the_exclusion_is_configurable(): void
    {
        $r = (new RuntimeMerge([]))->merge($this->artifact(), $this->registry());
        $this->assertContains('core/paragraph', $r['added']);
    }

    public function test_a_block_in_the_artifact_but_not_registered_is_reported_not_removed(): void
    {
        // Its plugin or theme is deactivated, so a composition using it renders an empty
        // comment. Silently shrinking the catalogue would hide that.
        $registry = $this->registry();
        unset($registry['acf/accordion']);
        $r = (new RuntimeMerge())->merge($this->artifact(), $registry);

        $this->assertSame(['accordion'], $r['missing_at_runtime']);
        $slugs = array_column($r['selection_index'], 'slug');
        $this->assertContains('accordion', $slugs);
    }

    public function test_a_runtime_block_is_never_reported_as_missing(): void
    {
        // It came FROM the registry, so it cannot be absent from it — reporting it would be a
        // contradiction the caller has to reason about.
        $r = (new RuntimeMerge())->merge($this->artifact(), $this->registry());
        $this->assertNotContains('marketplace/pricing-table', $r['missing_at_runtime']);
    }

    public function test_it_carries_a_runtime_blocks_parent_restriction(): void
    {
        $registry = $this->registry();
        $registry['marketplace/pricing-row'] = ['description' => 'A row.', 'parent' => ['marketplace/pricing-table']];
        $r = (new RuntimeMerge())->merge($this->artifact(), $registry);
        $row = null;
        foreach ($r['selection_index'] as $e) {
            if ($e['slug'] === 'marketplace/pricing-row') { $row = $e; }
        }
        $this->assertSame(['marketplace/pricing-table'], $row['parent_only']);
    }

    public function test_the_index_is_sorted_so_the_result_is_reproducible(): void
    {
        $r = (new RuntimeMerge())->merge($this->artifact(), $this->registry());
        $slugs = array_column($r['selection_index'], 'slug');
        $sorted = $slugs;
        sort($sorted);
        $this->assertSame($sorted, $slugs);
    }

    public function test_it_reports_the_token_cost_of_merging(): void
    {
        // Measured on a real install the merge takes the index from 2,843 to 5,158 tokens --
        // inside the 3,000-8,000 budget but past VP2's committed 3,000 ceiling. A caller that
        // prompts the merged index wholesale spends most of its budget on blocks with no
        // authored use-case metadata, which is exactly what L2 says not to do. So the cost and
        // the documented-only subset are returned rather than left to be discovered.
        $r = (new RuntimeMerge())->merge($this->artifact(), $this->registry());

        $this->assertArrayHasKey('tokens', $r);
        $this->assertGreaterThan($r['tokens']['documented'], $r['tokens']['merged']);
        $this->assertCount(2, $r['documented']);
        foreach ($r['documented'] as $e) {
            $this->assertArrayNotHasKey('x-tl-source', $e);
        }
    }
}
