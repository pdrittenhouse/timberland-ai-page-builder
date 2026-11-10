<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaClient;
use TimberlandAIPageBuilder\FigmaReview;
use TimberlandAIPageBuilder\FigmaUrl;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * The operator-facing half of the Figma path.
 *
 * These lean on the real captured file rather than a hand-written tree, because every interesting
 * property here -- duplicate frame names, designs nested a level down, decorative junk outside the
 * frames -- is a property of a file nobody tidied for us, and a tidy fixture asserts that the happy
 * path works while proving nothing about the files we will actually be sent.
 */
class FigmaReviewTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        $this->stubWordPress();

        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_parse_str')->alias(static function (string $s, &$r): void {
            parse_str($s, $r);
        });

        // Inspection must not reach the network: the client is stubbed, and a real fetch getting
        // through would otherwise look like a pass.
        Functions\when('wp_safe_remote_get')->alias(static function (): void {
            TestCase::fail('FigmaReview::inspect() must not fetch anything itself.');
        });
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    private function ref(): FigmaUrl
    {
        $ref = FigmaUrl::parse('https://www.figma.com/design/P7oiVKKoeacO5W2nd8Bpwq/Natural-Rose?node-id=0-1');
        $this->assertNotInstanceOf(\WP_Error::class, $ref);

        return $ref;
    }

    private function client(): FigmaClient
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json'),
            true
        );

        return new class ($payload) extends FigmaClient {
            /** @param array<string, mixed> $payload */
            public function __construct(private array $payload)
            {
                parent::__construct('figd_stub');
            }

            public function fetch_node(FigmaUrl $ref)
            {
                return $this->payload;
            }
        };
    }

    public function test_every_candidate_arrives_with_its_plan(): void
    {
        // The point of one response: switching pages during review must not cost another fetch.
        $result = FigmaReview::inspect($this->ref(), 1, $this->client());

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertNotEmpty($result['candidates']);

        foreach ($result['candidates'] as $candidate) {
            $this->assertArrayHasKey('decomposition', $candidate);
            $this->assertArrayHasKey('sections', $candidate['decomposition']);
            $this->assertArrayHasKey('id', $candidate);
        }
    }

    public function test_one_fetch_only(): void
    {
        // A second fetch of a multi-megabyte, metered, rate-limited file is the cost this design
        // exists to avoid, so it is asserted rather than assumed.
        $client = new class () extends FigmaClient {
            public int $calls = 0;

            /** @var array<string, mixed> */
            public array $payload = [];

            public function __construct()
            {
                parent::__construct('figd_stub');
            }

            public function fetch_node(FigmaUrl $ref)
            {
                ++$this->calls;

                return $this->payload;
            }
        };

        $client->payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json'),
            true
        );

        FigmaReview::inspect($this->ref(), 2, $client);

        $this->assertSame(1, $client->calls);
    }

    public function test_depth_changes_what_is_found_and_is_reported_back(): void
    {
        $shallow = FigmaReview::inspect($this->ref(), 0, $this->client());
        $deep = FigmaReview::inspect($this->ref(), 2, $this->client());

        $this->assertLessThan(count($deep['candidates']), count($shallow['candidates']));
        $this->assertSame(0, $shallow['depth']);
        $this->assertSame(2, $deep['depth']);
    }

    public function test_the_review_default_is_the_top_level_only(): void
    {
        // Pinned because it was 2, reasoned from "don't drift from the model-facing tool", and the
        // measurement said otherwise: descending gave an operator more rows, fewer names, and 33
        // candidates not listed at all -- so the frame they are looking for can be missing from the
        // list they are reviewing. See the constant's own docblock for the figures.
        $this->assertSame(0, FigmaReview::DEFAULT_DEPTH);

        $default = FigmaReview::inspect($this->ref(), FigmaReview::DEFAULT_DEPTH, $this->client());

        $this->assertSame([0], array_values(array_unique(array_column($default['candidates'], 'depth'))));
        $this->assertSame(0, $default['truncated'], 'the default view must not hide candidates');
    }

    public function test_an_out_of_range_depth_is_clamped_and_the_clamp_is_visible(): void
    {
        // A caller that asked for 9 and got 4 should be able to see that, rather than conclude the
        // file is shallower than it is.
        $result = FigmaReview::inspect($this->ref(), 99, $this->client());

        $this->assertSame(FigmaReview::MAX_DEPTH, $result['depth']);
    }

    public function test_nesting_is_reported_so_the_caller_can_render_it(): void
    {
        // At depth 2 this file yields frame/child pairs carrying the same text. They are not two
        // pages, and a flat list makes them look like a bug in the extractor. `depth` and `parent`
        // are what let a UI show them as what they are.
        $result = FigmaReview::inspect($this->ref(), 2, $this->client());
        $depths = array_unique(array_column($result['candidates'], 'depth'));

        $this->assertGreaterThan(1, count($depths), 'a depth-2 scan must report more than one level');

        foreach ($result['candidates'] as $candidate) {
            $this->assertArrayHasKey('parent', $candidate);
        }
    }

    public function test_a_fetch_failure_is_returned_not_swallowed(): void
    {
        $client = new class () extends FigmaClient {
            public function __construct()
            {
                parent::__construct('figd_stub');
            }

            public function fetch_node(FigmaUrl $ref)
            {
                return new \WP_Error('figma_api_error', 'Figma returned HTTP 403.');
            }
        };

        $result = FigmaReview::inspect($this->ref(), 1, $client);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('figma_api_error', $result->get_error_code());
    }

    public function test_the_file_key_travels_into_every_section(): void
    {
        // FG3: a node id without the file it belongs to cannot be fetched again.
        $result = FigmaReview::inspect($this->ref(), 1, $this->client());

        foreach ($result['candidates'] as $candidate) {
            foreach ($candidate['decomposition']['sections'] as $section) {
                $this->assertSame('P7oiVKKoeacO5W2nd8Bpwq', $section['figma']['file']);
            }
        }
    }
}
