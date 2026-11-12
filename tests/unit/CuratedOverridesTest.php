<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\CuratedOverrides;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * UC3 — per-site curation as an override layer, never as the source.
 *
 * The merge is the whole item. An override that fills in one field must not blank the other two:
 * that is the same assign-versus-union mistake this stack has now paid for four times -- a tier
 * scan that assigned erased earlier tiers, then a per-file resolve dropped every section a fork
 * did not restate. Here it would be an operator writing one note and silently deleting two others
 * they never saw, which is worse than either, because nothing would fail.
 */
final class CuratedOverridesTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        $this->stubWordPress();

        // Stubbed to the real semantics, not pass-throughs. `wp_kses_post` matters: the
        // `configuration` field is multi-line prose, and a sanitiser that stripped newlines would
        // flatten a three-point note into one run-on line without anything failing.
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);
        Functions\when('sanitize_key')->alias(static fn($v): string => strtolower(preg_replace('/[^a-z0-9_\-\/]/i', '', (string) $v)));
        Functions\when('wp_kses_post')->alias(static fn($v): string => (string) $v);

        CuratedOverrides::flush_cache();
    }

    protected function tearDown(): void
    {
        CuratedOverrides::flush_cache();
        $this->unstubWordPress();
    }

    private function shipped(): array
    {
        return [
            'best_for' => 'A short teaser linking to a longer page.',
            'avoid_when' => 'The content is the destination, not a pointer to it.',
            'configuration' => 'Background images go in bg_image, never an inline image block.',
        ];
    }

    public function test_an_override_of_one_field_leaves_the_other_two_shipped(): void
    {
        $merged = CuratedOverrides::merge($this->shipped(), ['avoid_when' => 'Never on this site.']);

        $this->assertSame('Never on this site.', $merged['avoid_when']);
        $this->assertSame($this->shipped()['best_for'], $merged['best_for'], 'best_for was blanked');
        $this->assertSame($this->shipped()['configuration'], $merged['configuration'], 'configuration was blanked');
    }

    public function test_an_empty_field_inherits_rather_than_blanking(): void
    {
        // The form submits every field on every save, so untouched fields arrive as ''. Treating
        // that as "blank it" would delete the shipped guidance for two fields each time an
        // operator edited the third -- and nothing would report it.
        $merged = CuratedOverrides::merge($this->shipped(), [
            'best_for' => '',
            'avoid_when' => '   ',
            'configuration' => 'Site rule.',
        ]);

        $this->assertSame($this->shipped()['best_for'], $merged['best_for']);
        $this->assertSame($this->shipped()['avoid_when'], $merged['avoid_when']);
        $this->assertSame('Site rule.', $merged['configuration']);
    }

    public function test_a_block_with_nothing_shipped_can_still_be_curated(): void
    {
        // The likeliest reason to use the screen: a plugin- or theme-tier block has no harvested
        // documentation at all until someone writes some.
        $merged = CuratedOverrides::merge([], ['best_for' => 'Only this site knows.']);

        $this->assertSame('Only this site knows.', $merged['best_for']);
        $this->assertSame('', $merged['avoid_when']);
    }

    public function test_every_field_is_always_present_so_callers_need_no_guards(): void
    {
        $merged = CuratedOverrides::merge([], []);

        foreach (CuratedOverrides::FIELDS as $field) {
            $this->assertArrayHasKey($field, $merged);
            $this->assertSame('', $merged[$field]);
        }
    }

    public function test_the_filter_is_the_last_word_and_also_merges_per_field(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(
            static fn($hook, $value) => 'taipb_curated_metadata' === $hook
                ? ['avoid_when' => 'from the filter']
                : $value
        );

        $merged = CuratedOverrides::for_block('card', $this->shipped());

        $this->assertSame('from the filter', $merged['avoid_when']);
        // A filter returning a partial array must not take the rest of the guidance with it --
        // the same rule as the option layer, for the same reason.
        $this->assertSame($this->shipped()['best_for'], $merged['best_for']);
    }

    public function test_a_filter_returning_nonsense_degrades_to_the_shipped_default(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(static fn() => 'not an array');

        $merged = CuratedOverrides::for_block('card', $this->shipped());

        $this->assertSame($this->shipped()['best_for'], $merged['best_for']);
    }

    public function test_the_option_is_applied_over_the_shipped_default(): void
    {
        Functions\when('get_option')->justReturn([
            'card' => ['avoid_when' => 'Not on this site.'],
        ]);

        $merged = CuratedOverrides::for_block('acf/card', $this->shipped());

        $this->assertSame('Not on this site.', $merged['avoid_when']);
        $this->assertSame($this->shipped()['best_for'], $merged['best_for']);
    }

    public function test_a_prefixed_and_a_bare_slug_are_the_same_block(): void
    {
        // The registry says `acf/card`, the artifacts say `card`. A caller that had to know which
        // form to pass would get it wrong somewhere, and the symptom would be an override that
        // saves, displays, and never reaches a prompt.
        Functions\when('get_option')->justReturn(['card' => ['best_for' => 'site text']]);

        $this->assertSame('site text', CuratedOverrides::for_block('acf/card', [])['best_for']);
        $this->assertSame('site text', CuratedOverrides::for_block('card', [])['best_for']);
    }
}
