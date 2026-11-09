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
    public function test_the_default_allow_list_is_short_on_purpose(): void
    {
        // A live install has 425 `options_*` rows across 50 field roots, almost all layout and
        // feature configuration. Dumping them would spend the 3,000-8,000 token budget
        // describing sidebar widths.
        $ref = new \ReflectionClass(BrandContext::class);
        $default = $ref->getConstant('DEFAULT_ROOTS');

        $this->assertIsArray($default);
        $this->assertLessThanOrEqual(5, count($default), 'the default allow-list must stay small');
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
}
