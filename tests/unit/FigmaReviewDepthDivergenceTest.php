<?php
namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaStructure;
use TimberlandAIPageBuilder\FigmaReview;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * The review and the model-facing tool read the same file at DIFFERENT depths, on purpose.
 *
 * `FigmaReview::DEFAULT_DEPTH` is 0 and `FigmaStructure::pages()`'s own default — what
 * `FigmaContextTool` uses — is 2. That looks exactly like drift, and `FigmaReview`'s docblock
 * records the measurement showing it is not: descending gives an operator more rows and fewer
 * names, because the 40-candidate cap then evicts the named top-level frames in favour of their own
 * children. An operator scanning for a frame they already recognise needs the named list; a model
 * choosing semantically benefits from the nested one.
 *
 * **This guards a future state, not a present one.** `FigmaContextTool` is wired nowhere, so the
 * depth-2 side has no live consumer: `FigmaReview` is the only production caller of `pages()` and
 * always passes a depth explicitly. The divergence is therefore real in the code and dormant in the
 * flow, and it goes live the moment that tool is wired — which is the moment the two wrong "fixes"
 * below become tempting. Said plainly because the rationale it rests on is written in the present
 * tense elsewhere, and reading it that way is what produced both of those wrong conclusions once
 * already.
 *
 * **Why this is worth a test.** The divergence invites two plausible-looking "fixes" that are both
 * wrong, and neither fails loudly:
 *
 *  1. Unifying the defaults, which silently changes what one of the two audiences sees.
 *  2. Serving one consumer from the other's cached projection to save a metered read. The cache key
 *     includes depth precisely because these answers are not interchangeable, and a depth-0 reading
 *     handed to a depth-2 caller is a narrower candidate list presented as a complete one.
 *
 * The second is tempting because the fetch really is shared — `fetch_node()` takes no depth and
 * returns the whole tree, so depth is a LOCAL projection parameter. Two consumers of one file
 * genuinely could share one read; what they cannot share is one PROJECTION. Anyone reaching for
 * that optimisation should cache or pass the payload, not the derived candidates.
 */
class FigmaReviewDepthDivergenceTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return (array) json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json'),
            true
        );
    }

    public function test_the_review_depth_and_the_tool_depth_are_not_interchangeable(): void
    {
        $payload = $this->payload();

        $review = FigmaStructure::pages($payload, FigmaReview::DEFAULT_DEPTH);
        $tool = FigmaStructure::pages($payload); // the tool takes pages()' own default

        $this->assertNotSame(
            array_column($review['pages'], 'id'),
            array_column($tool['pages'], 'id'),
            'if these ever match, the depths have been unified and one audience has silently '
                . 'changed — see FigmaReview::DEFAULT_DEPTH for the measurement behind the divergence'
        );
    }

    /**
     * The precondition the two docblocks above now state outright.
     *
     * `FigmaContextTool` and `FigmaReview::DEFAULT_DEPTH` both say, in prose, that the depth-2 side
     * has no live consumer. Prose cannot notice when that stops being true, and the moment someone
     * wires the tool those notes become exactly the kind of confidently-wrong present tense that
     * caused this file to exist. So the claim is pinned: wiring the tool fails here, deliberately,
     * with instructions rather than a bare red.
     */
    public function test_the_context_tool_is_still_unwired_or_the_docs_now_lie(): void
    {
        $constructed = [];

        foreach ((array) glob(__DIR__ . '/../../includes/*.php') as $file) {
            $source = (string) file_get_contents((string) $file);

            if (str_contains($source, 'new FigmaContextTool(')) {
                $constructed[] = basename((string) $file);
            }
        }

        $this->assertSame(
            [],
            $constructed,
            "FigmaContextTool is now constructed in: " . implode(', ', $constructed) . ".\n"
                . "That is not a failure of the wiring -- it is a signal that three things now need "
                . "revisiting:\n"
                . "  1. FigmaContextTool's class docblock still says NOT WIRED.\n"
                . "  2. FigmaReview::DEFAULT_DEPTH still says the second audience is not live; the "
                . "depth divergence is now REAL and the two tests above guard a live behaviour.\n"
                . "  3. The review and the tool are now two consumers of one metered file. Share the "
                . "PAYLOAD, not the derived candidates -- fetch_node() takes no depth and returns the "
                . "whole tree, so the fetch is shareable while the depth-0 and depth-2 projections "
                . "are not.\n"
                . "Update those, then delete this test."
        );
    }

    public function test_the_deeper_read_is_what_costs_the_operator_named_frames(): void
    {
        $payload = $this->payload();

        $shallow = FigmaStructure::pages($payload, 0);
        $deep = FigmaStructure::pages($payload);

        $named = static fn(array $found): int => count(array_filter(
            $found['pages'],
            static fn(array $p): bool => (string) ($p['name'] ?? '') !== ''
        ));

        // The specific trade the docblock measured: deeper finds more and names less. Asserted as
        // a relationship rather than fixed counts, because the fixture is a trimmed capture and the
        // absolute numbers in the docblock come from the full file.
        $this->assertGreaterThanOrEqual(
            $named($deep),
            $named($shallow),
            'the shallow read must not surface fewer named frames than the deep one'
        );
        $this->assertGreaterThanOrEqual(
            count($shallow['pages']),
            count($deep['pages']),
            'the deep read must not find fewer candidates than the shallow one'
        );
    }
}
