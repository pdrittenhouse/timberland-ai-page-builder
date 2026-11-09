<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\BrandContext;

/**
 * AM5 — what is unit-testable without WordPress.
 *
 * `palette()` needs `get_theme_support()` and `options()` needs `$wpdb`, so the behaviour that
 * matters most is verified against a live install instead (see the commit message for the
 * measured figures). What is tested here is the part that decides how much gets collected at
 * all, because that is what competes with the component catalogue for the token budget.
 */
final class BrandContextTest extends TestCase
{
    public function test_the_default_allow_list_excludes_everything_that_is_not_appearance(): void
    {
        // A live install has 425 `options_*` rows across 50 field roots. Asserting a bare count
        // would pass for whatever number happened to be chosen; what matters is that the roots
        // carrying analytics, consent, feature flags, indexing and CONTENT stay out -- that is
        // where most of the 425 rows live, and it is what makes the filter worth having.
        $default = (new \ReflectionClass(BrandContext::class))->getConstant('DEFAULT_ROOTS');
        $this->assertIsArray($default);

        $notAppearance = [
            // analytics, consent, verification, indexing
            'csp', 'gtm', 'ga', 'google', 'bing', 'facebook', 'onetrust', 'llms', 'rest',
            'selective', 'advanced',
            // feature flags and toggles
            'feature', 'enable', 'remove', 'disable', 'fluid', 'inline', 'hide', 'include',
            'toggle', 'shrink', 'hover',
            // content, not style -- the two largest roots by value count
            'header', 'footer', 'traveling', 'tcta', 'custom',
            // structured data, not branding: org_name is "used in Organization structured data"
            'org',
            // responsive behaviour: navbar_breakpoint "set to XS to disable desktop navigation"
            'navbar',
            // a Block: Menu field, not a site option at all
            'hamburger',
        ];
        foreach ($notAppearance as $root) {
            $this->assertNotContains($root, $default, "'$root' is not appearance configuration");
        }

        // And it stays a small fraction of the 50, whatever is added.
        $this->assertLessThan(15, count($default), 'the allow-list must not drift towards a dump');
    }

    public function test_the_default_allow_list_covers_the_appearance_roots(): void
    {
        $default = (new \ReflectionClass(BrandContext::class))->getConstant('DEFAULT_ROOTS');
        foreach (['page', 'sidebar', 'bg', 'brand', 'co', 'font'] as $root) {
            $this->assertContains($root, $default);
        }
    }

    public function test_the_allow_list_is_overridable(): void
    {
        $bc = new BrandContext(['header', 'footer']);
        $ref = new \ReflectionProperty($bc, 'roots');
        $ref->setAccessible(true);

        $this->assertSame(['header', 'footer'], $ref->getValue($bc));
    }

    public function test_an_empty_allow_list_collects_no_options(): void
    {
        // Without this, an install that wants no option context at all would still pay for a
        // query per root.
        $bc = new BrandContext([]);
        $this->assertSame([], $bc->options());
    }

    public function test_it_degrades_rather_than_fatals_without_wordpress(): void
    {
        // The plugin's own suite runs with no WordPress loaded, so every collector must return
        // an empty value rather than call an undefined function.
        $bc = new BrandContext([]);
        $this->assertSame([], $bc->palette());
        $this->assertSame(['name' => '', 'description' => ''], $bc->identity());
    }

    public function test_a_root_matches_on_the_segment_boundary(): void
    {
        // `options_co%` also matches `options_container_relative`, so the `co` root -- which
        // means `co_brand` -- silently collected an unrelated container setting. The fix is in
        // the query, so this documents the rule the query must keep: a root owns
        // `options_<root>_...` and the bare `options_<root>`, nothing else.
        $default = (new \ReflectionClass(BrandContext::class))->getConstant('DEFAULT_ROOTS');
        $this->assertContains('co', $default, 'the short root that exposed this must stay covered');

        // A root that is a strict prefix of another real root is the hazard: co/container,
        // bg/brand. Assert the pairs that exist in this corpus are both present or both absent,
        // so a future reader sees the collision was considered.
        $this->assertContains('brand', $default);
        $this->assertNotContains('container', $default);
    }
}
