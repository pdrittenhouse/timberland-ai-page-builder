<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\CssCoverage;

/**
 * VP3 — the check must FAIL on the accordion case and must NOT fail on a structural block.
 */
final class CssCoverageTest extends TestCase
{
    /** @return array<string, mixed> a manifest shaped like the real one */
    private function manifest(): array
    {
        return [
            'blocks' => [
                'acf/accordion' => ['01-atoms/button', '02-molecules/accordion'],
                'acf/card' => ['03-organisms/card'],
                'acf/row' => [],
            ],
            'patterns' => [
                '01-atoms/button' => ['css' => true, 'dependencies' => []],
                '02-molecules/accordion' => ['css' => true, 'dependencies' => ['01-atoms/button']],
                '03-organisms/card' => ['css' => true, 'dependencies' => ['01-atoms/image']],
                '01-atoms/image' => ['css' => true, 'dependencies' => []],
                '01-atoms/skip-nav' => ['css' => false, 'dependencies' => []],
            ],
        ];
    }

    public function test_it_normalises_the_two_manifests_differing_block_keys(): void
    {
        // block-manifest.json uses the bare slug, pattern-manifest.json uses `acf/<slug>`.
        // Comparing them unnormalised reports all 48 blocks as absent, which it did.
        $c = new CssCoverage($this->manifest());
        $this->assertSame(
            $c->requiredPatterns(['accordion']),
            $c->requiredPatterns(['acf/accordion'])
        );
    }

    public function test_it_follows_pattern_dependencies_transitively(): void
    {
        $c = new CssCoverage($this->manifest());
        // card -> 03-organisms/card -> 01-atoms/image
        $this->assertSame(
            ['01-atoms/image', '03-organisms/card'],
            $c->requiredPatterns(['card'])
        );
    }

    public function test_it_catches_the_accordion_failure(): void
    {
        // The real case: the page rendered accordion classes while the resolved pattern list
        // did not include accordion, so none of its CSS was delivered and nothing failed.
        $c = new CssCoverage($this->manifest());
        $violations = $c->check(['accordion'], ['01-atoms/button']);

        $this->assertCount(1, $violations);
        $this->assertSame('02-molecules/accordion', $violations[0]['pattern']);
        $this->assertStringContainsString('will not deliver it', $violations[0]['reason']);
    }

    public function test_it_passes_when_every_required_pattern_is_resolved(): void
    {
        $c = new CssCoverage($this->manifest());
        $this->assertSame([], $c->check(['accordion'], ['01-atoms/button', '02-molecules/accordion']));
    }

    public function test_a_block_with_no_patterns_is_not_a_violation(): void
    {
        // 17 of 48 blocks declare no pattern — row, column, section, the table parts. They emit
        // Bootstrap classes from CSS that always ships. Failing them would fail a third of every
        // composition and train the caller to ignore this check.
        $c = new CssCoverage($this->manifest());
        $this->assertSame([], $c->check(['row'], []));
        $this->assertSame(['row'], $c->blocksWithoutPatterns(['row', 'accordion']));
    }

    public function test_a_pattern_with_no_css_cannot_have_undelivered_css(): void
    {
        $m = $this->manifest();
        $m['blocks']['acf/nav'] = ['01-atoms/skip-nav'];
        $c = new CssCoverage($m);
        $this->assertSame([], $c->check(['nav'], []));
    }

    public function test_a_declared_pattern_the_manifest_does_not_describe_is_a_violation(): void
    {
        // A block pointing at a pattern that no longer exists is the stale-edge case; silently
        // skipping it would report a composition as clean when its CSS cannot ship at all.
        $m = $this->manifest();
        $m['blocks']['acf/ghost'] = ['02-molecules/removed'];
        $c = new CssCoverage($m);
        $violations = $c->check(['ghost'], []);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('does not describe it', $violations[0]['reason']);
    }

    public function test_a_dependency_cycle_does_not_hang(): void
    {
        $m = $this->manifest();
        $m['patterns']['01-atoms/a'] = ['css' => true, 'dependencies' => ['01-atoms/b']];
        $m['patterns']['01-atoms/b'] = ['css' => true, 'dependencies' => ['01-atoms/a']];
        $m['blocks']['acf/cyclic'] = ['01-atoms/a'];
        $c = new CssCoverage($m);

        $this->assertSame(['01-atoms/a', '01-atoms/b'], $c->requiredPatterns(['cyclic']));
    }
}
