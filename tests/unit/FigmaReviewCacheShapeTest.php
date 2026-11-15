<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Timberland\Framework\FigmaStructure;
use Timberland\Framework\FigmaUrl;
use TimberlandAIPageBuilder\FigmaReview;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * A cached reading is shaped by TWO tiers, so its key has to move when EITHER of them changes.
 *
 * `FigmaReview` stores an envelope of its own -- `file`, `node`, `source`, `depth`, `skipped`,
 * `truncated`, `candidates`, `cached` -- wrapped around a projection the FRAMEWORK produces, from
 * `FigmaStructure::bands()` via `FigmaDecomposer`. Each tier therefore has its own shape version,
 * and {@see FigmaReview::cache_key()} composes both.
 *
 * **This is not a hypothetical split.** It was written because AD7's styling keys were added to
 * `FigmaStructure` -- `style` on every band, group and text entry -- while this plugin's
 * `SHAPE_VERSION` stayed at 3. One counter cannot see the other tier's change, so every reading
 * cached before that work would have been served, unchanged and reported as a successful
 * inspection, to a consumer that now expects styling and would have found none. `SHAPE_VERSION`'s
 * own version-3 note records the identical trap one tier further out, for `chrome`.
 *
 * **Why a test and not a comment.** The failure is silent in exactly the way this codebase keeps
 * getting caught by: the cache returns a well-formed answer, the panel says the inspection
 * succeeded, and only the missing styling downstream says otherwise. A comment cannot notice when
 * someone simplifies the key back to one component; this fails instead.
 */
class FigmaReviewCacheShapeTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubWordPress();
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
        parent::tearDown();
    }

    private function reference(): FigmaUrl
    {
        $ref = FigmaUrl::parse(
            'https://www.figma.com/design/P7oiVKKoeacO5W2nd8Bpwq/Natural-Rose?node-id=0-1'
        );

        $this->assertInstanceOf(FigmaUrl::class, $ref, 'the fixture URL must parse');

        return $ref;
    }

    private function keyFor(FigmaUrl $ref, int $depth): string
    {
        $method = new \ReflectionMethod(FigmaReview::class, 'cache_key');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $ref, $depth);
    }

    /**
     * Read through reflection so the plugin's envelope version stays private -- widening a
     * class's surface to make it testable buys a worse contract than the test is worth.
     */
    private function envelopeVersion(): int
    {
        return (int) (new \ReflectionClass(FigmaReview::class))->getConstant('SHAPE_VERSION');
    }

    /**
     * The composition itself, pinned component by component.
     *
     * Asserting the exact key rather than "it contains the version" on purpose: dropping the
     * framework component is the regression this guards, and a substring check would still pass
     * if the component were present but, say, stringified differently on one side of a refactor.
     */
    public function test_the_key_composes_both_tiers_shape_versions(): void
    {
        $ref = $this->reference();

        $expected = FigmaReview::cache_prefix() . md5(implode('|', [
            $ref->file_key(),
            $ref->node_id(),
            (string) FigmaReview::DEFAULT_DEPTH,
            'v' . $this->envelopeVersion(),
            'f' . FigmaStructure::SHAPE_VERSION,
        ]));

        $this->assertSame(
            $expected,
            $this->keyFor($ref, FigmaReview::DEFAULT_DEPTH),
            "The cache key no longer composes both tiers' shape versions.\n"
                . "A reading is this plugin's envelope wrapped around the framework's projection, so "
                . "the key must change when EITHER shape does. Keying on only one of them serves a "
                . "stale projection as a successful inspection -- see this class's docblock for the "
                . 'case that produced it.'
        );
    }

    /**
     * The framework's version has to be a real contributor, not a constant the key ignores.
     *
     * Recomputing the key WITHOUT the framework component and asserting it differs is the part
     * that would actually catch the regression: if someone removes that component, the key above
     * and this one converge, and this assertion is what notices.
     */
    public function test_dropping_the_framework_version_would_change_the_key(): void
    {
        $ref = $this->reference();

        $without_framework = FigmaReview::cache_prefix() . md5(implode('|', [
            $ref->file_key(),
            $ref->node_id(),
            (string) FigmaReview::DEFAULT_DEPTH,
            'v' . $this->envelopeVersion(),
        ]));

        $this->assertNotSame(
            $without_framework,
            $this->keyFor($ref, FigmaReview::DEFAULT_DEPTH),
            'the framework projection version is not reaching the cache key at all'
        );
    }

    /**
     * Depth stays part of the key, because the two depths are not interchangeable projections.
     *
     * Guarded next door in {@see FigmaReviewDepthDivergenceTest} from the other direction -- that
     * the depths genuinely differ. This is the cache-side half: that the key can tell them apart.
     */
    public function test_depth_still_separates_two_readings_of_one_frame(): void
    {
        $ref = $this->reference();

        $this->assertNotSame(
            $this->keyFor($ref, 0),
            $this->keyFor($ref, 2),
            'a depth-0 reading handed to a depth-2 caller is a narrower candidate list presented '
                . 'as a complete one'
        );
    }
}
