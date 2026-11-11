<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\DesignVocabulary;

/**
 * The values a generated page may style itself with.
 *
 * All of this was already generated and nothing read it: `scssVariables.json` (33 colours, 41
 * spacers), `icon-manifest.json`, and a 1.3 MB `utility-index.json` holding 14,934 rules across
 * **14,252 class names** -- zero references from the plugin to any of them. The gap was a missing
 * projection, not a missing document.
 */
class DesignVocabularyTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function preset(array $slugs): array
    {
        return array_map(static fn(string $s): array => ['slug' => $s], $slugs);
    }

    public function test_a_flat_preset_list_is_read(): void
    {
        $slugs = new \ReflectionMethod(DesignVocabulary::class, 'slugs');
        $slugs->setAccessible(true);

        $this->assertSame(
            ['primary', 'secondary'],
            $slugs->invoke(null, self::preset(['primary', 'secondary']))
        );
    }

    public function test_an_origin_keyed_preset_is_also_read(): void
    {
        // theme.json settings arrive flat OR grouped by origin depending on how they are read, and
        // handling one shape returns nothing for the other -- silently, so "no colours" would be
        // indistinguishable from "no palette configured".
        $slugs = new \ReflectionMethod(DesignVocabulary::class, 'slugs');
        $slugs->setAccessible(true);

        $this->assertSame(
            ['primary'],
            $slugs->invoke(null, ['theme' => self::preset(['primary'])])
        );
    }

    public function test_wordpress_core_defaults_are_not_offered_as_the_design_system(): void
    {
        // Measured: merging every origin reported 18 gradients where the theme defines 6, the extra
        // twelve being WordPress's own `vivid-cyan-blue-to-vivid-purple` and friends. They exist,
        // but offering them makes an off-brand choice look sanctioned.
        $slugs = new \ReflectionMethod(DesignVocabulary::class, 'slugs');
        $slugs->setAccessible(true);

        $result = $slugs->invoke(null, [
            'default' => self::preset(['vivid-cyan-blue-to-vivid-purple', 'luminous-vivid-amber']),
            'theme' => self::preset(['primary-secondary-horizontal']),
        ]);

        $this->assertSame(['primary-secondary-horizontal'], $result);
    }

    public function test_a_custom_origin_is_kept_because_a_site_adding_one_means_it(): void
    {
        $slugs = new \ReflectionMethod(DesignVocabulary::class, 'slugs');
        $slugs->setAccessible(true);

        $result = $slugs->invoke(null, [
            'default' => self::preset(['core-thing']),
            'theme' => self::preset(['brand']),
            'custom' => self::preset(['client-override']),
        ]);

        $this->assertSame(['brand', 'client-override'], $result);
    }

    public function test_core_defaults_are_used_when_there_is_no_theme_origin(): void
    {
        // Falling back to nothing would be worse than falling back to core: a site with no theme
        // palette still has colours, and reporting none invites the model to invent names.
        $slugs = new \ReflectionMethod(DesignVocabulary::class, 'slugs');
        $slugs->setAccessible(true);

        $this->assertSame(
            ['core-thing'],
            $slugs->invoke(null, ['default' => self::preset(['core-thing'])])
        );
    }

    public function test_a_malformed_preset_yields_nothing_rather_than_throwing(): void
    {
        $slugs = new \ReflectionMethod(DesignVocabulary::class, 'slugs');
        $slugs->setAccessible(true);

        foreach ([null, 'a string', [], ['no-slug-key' => 1]] as $bad) {
            $this->assertSame([], $slugs->invoke(null, $bad));
        }
    }

    public function test_utility_lookup_prefers_a_prefix_over_a_substring(): void
    {
        // A model asking for "m" means margin utilities, not every class containing an m.
        //
        // Asserted against the REAL ranking. The first version of this test re-implemented the
        // ordering inside an anonymous subclass because the method read the index from disk --
        // which tests the test. The ranking is a pure function now for exactly that reason.
        $result = DesignVocabulary::rank_matches(
            ['mt-1', 'mb-2', 'form-label', 'm-0', 'custom-m-thing'],
            'm'
        );

        $this->assertSame('m-0', $result[0], 'the exact match comes first');
        $this->assertContains('custom-m-thing', $result, 'substrings still appear, just later');
        $this->assertGreaterThan(
            array_search('m-0', $result, true),
            array_search('custom-m-thing', $result, true),
            'a substring match must rank below every prefix match'
        );
    }

    public function test_a_prefix_match_requires_the_separator(): void
    {
        // `mt` must not match `mtable`: a prefix is a family, not a string the name happens to
        // start with, or every query would pull in unrelated classes as though they were kin.
        $result = DesignVocabulary::rank_matches(['mt-1', 'mtable-thing'], 'mt');

        $this->assertSame('mt-1', $result[0]);
        $this->assertSame(['mt-1', 'mtable-thing'], $result, 'mtable-thing is a substring, ranked last');
    }

    public function test_the_query_is_case_insensitive(): void
    {
        $this->assertSame(['MT-1'], DesignVocabulary::rank_matches(['MT-1'], 'mt'));
    }

    public function test_an_empty_query_matches_nothing_rather_than_everything(): void
    {
        // Returning all 14,252 for an empty query would blow any budget it was charged against.
        $result = (new DesignVocabulary())->utilities('');

        $this->assertSame([], $result['classes']);
        $this->assertSame(0, $result['matched']);
    }
}
