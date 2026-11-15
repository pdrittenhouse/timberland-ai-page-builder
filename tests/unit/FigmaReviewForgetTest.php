<?php
namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Timberland\Framework\FigmaClient;
use TimberlandAIPageBuilder\FigmaReview;
use Timberland\Framework\FigmaUrl;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * Dropping a cached reading must cost nothing and must drop every depth.
 *
 * `$refresh` already covers the operator looking at a stale reading in the editor. `forget()` covers
 * the cases where nobody is looking: a designer reports the file moved on, a deploy changes what the
 * projection should hold, or a reading is suspected bad. The two requirements worth pinning are that
 * it reaches EVERY depth — depth is part of the key, and "flush this frame" means the frame, not one
 * reading of it — and that it never touches Figma, because the budget it protects is measured in
 * days.
 */
class FigmaReviewForgetTest extends TestCase
{
    use WordPressStubs;

    /** @var array<string, mixed> in-memory stand-in for the transient store */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubWordPress();
        $this->transients = [];

        Functions\when('get_transient')->alias(fn(string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, $v): bool {
            $this->transients[$k] = $v;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $k): bool {
            if (!array_key_exists($k, $this->transients)) {
                return false;
            }

            unset($this->transients[$k]);

            return true;
        });

        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_parse_str')->alias(static function (string $s, &$r): void {
            parse_str($s, $r);
        });
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
        parent::tearDown();
    }

    private function ref(): FigmaUrl
    {
        $ref = FigmaUrl::parse(
            'https://www.figma.com/design/P7oiVKKoeacO5W2nd8Bpwq/Natural-Rose?node-id=0-1'
        );
        $this->assertInstanceOf(FigmaUrl::class, $ref);

        return $ref;
    }

    /** A client that fails the test if it is asked to fetch. */
    private function neverFetches(): FigmaClient
    {
        return new class extends FigmaClient {
            public function __construct()
            {
                parent::__construct('figd_stub');
            }

            public function fetch_node(FigmaUrl $ref)
            {
                TestCase::fail('forget() must not read from Figma — it protects a cost-based budget.');
            }
        };
    }

    /** A client that answers from the captured fixture. */
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

    public function test_it_drops_every_depth_of_one_frame(): void
    {
        $ref = $this->ref();

        // Three different readings of the same frame, which are three different cache entries.
        foreach ([0, 1, 2] as $depth) {
            $this->assertIsArray(FigmaReview::inspect($ref, $depth, $this->client()));
        }
        $this->assertCount(3, $this->transients, 'three depths should be three entries');

        $this->assertSame(3, FigmaReview::forget($ref));
        $this->assertSame([], $this->transients, 'every depth of the frame must be gone');
    }

    public function test_it_never_reads_from_figma(): void
    {
        // The client fails the test if touched; forget() on a cold cache must still be safe.
        $this->assertSame(0, FigmaReview::forget($this->ref()));
        $this->assertSame([], $this->transients);

        // And on a warm one.
        FigmaReview::inspect($this->ref(), 0, $this->client());
        $this->neverFetches();
        $this->assertSame(1, FigmaReview::forget($this->ref()));
    }

    public function test_the_count_is_readings_dropped_not_deletes_attempted(): void
    {
        $ref = $this->ref();
        FigmaReview::inspect($ref, 0, $this->client());

        // One of five possible depths is cached, so one is dropped -- not MAX_DEPTH + 1. An operator
        // told "flushed 5" when a single reading existed would learn the wrong thing.
        $this->assertSame(1, FigmaReview::forget($ref));
        $this->assertSame(0, FigmaReview::forget($ref), 'a second flush has nothing left to drop');
    }

    public function test_a_flushed_frame_is_read_again_rather_than_served_stale(): void
    {
        $ref = $this->ref();

        $first = FigmaReview::inspect($ref, 0, $this->client());
        $this->assertIsArray($first);
        $this->assertFalse($first['cached'], 'the first reading is not cached');

        $warm = FigmaReview::inspect($ref, 0, $this->client());
        $this->assertIsArray($warm);
        $this->assertTrue($warm['cached'], 'the second is served from cache');

        FigmaReview::forget($ref);

        $after = FigmaReview::inspect($ref, 0, $this->client());
        $this->assertIsArray($after);
        $this->assertFalse($after['cached'], 'after a flush the next read is a real read again');
    }

    public function test_the_command_rejects_both_flags_and_neither(): void
    {
        $both = \TimberlandAIPageBuilder\FigmaCacheCommand::flush(
            'https://www.figma.com/design/P7oiVKKoeacO5W2nd8Bpwq/Natural-Rose?node-id=0-1',
            true
        );
        $this->assertFalse($both['ok']);
        $this->assertStringContainsString('not both', $both['message']);

        $neither = \TimberlandAIPageBuilder\FigmaCacheCommand::flush(null, false);
        $this->assertFalse($neither['ok']);
        $this->assertStringContainsString('Nothing to do', $neither['message']);
    }

    public function test_a_bad_url_is_operator_error_but_an_empty_cache_is_not(): void
    {
        $bad = \TimberlandAIPageBuilder\FigmaCacheCommand::flush('https://example.com/not-figma');
        $this->assertFalse($bad['ok'], 'a non-Figma URL is the operator getting it wrong');

        $cold = \TimberlandAIPageBuilder\FigmaCacheCommand::flush(
            'https://www.figma.com/design/P7oiVKKoeacO5W2nd8Bpwq/Natural-Rose?node-id=0-1'
        );
        $this->assertTrue($cold['ok'], '"nothing was cached" is a successful outcome, not an error');
        $this->assertStringContainsString('Nothing was cached', $cold['message']);
    }

    public function test_the_command_reports_what_it_actually_dropped(): void
    {
        $ref = $this->ref();
        FigmaReview::inspect($ref, 0, $this->client());
        FigmaReview::inspect($ref, 2, $this->client());

        $out = \TimberlandAIPageBuilder\FigmaCacheCommand::flush(
            'https://www.figma.com/design/P7oiVKKoeacO5W2nd8Bpwq/Natural-Rose?node-id=0-1'
        );

        $this->assertTrue($out['ok']);
        $this->assertStringContainsString('2 cached reading(s) dropped', $out['message']);
        $this->assertSame([], $this->transients);
    }
}
