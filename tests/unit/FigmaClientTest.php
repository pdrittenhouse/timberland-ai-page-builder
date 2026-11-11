<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaClient;
use TimberlandAIPageBuilder\FigmaUrl;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * The remaining three FG2 details, each one silent when wrong.
 *
 * The most important assertion here is the NEGATIVE one: the preview request must not carry the
 * Figma token. The preview is served from a signed S3 URL where the signature is the
 * authorisation, so sending an account-wide credential there hands it to a third-party host that
 * has no use for it — and nothing would fail, which is exactly why it needs a test.
 */
class FigmaClientTest extends TestCase
{
    use WordPressStubs;

    /** @var array<int, array{url: string, headers: array<string, string>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->stubWordPress();
        $this->requests = [];

        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('is_wp_error')->alias(static fn($t): bool => $t instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body'] ?? '');
        Functions\when('wp_remote_retrieve_header')->alias(
            static fn(array $r, string $h): string => $r['headers'][$h] ?? ''
        );
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    /** @param array<int, array<string, mixed>> $responses */
    private function stubTransport(array $responses): void
    {
        $queue = $responses;
        Functions\when('wp_safe_remote_get')->alias(function (string $url, array $args = []) use (&$queue) {
            $this->requests[] = ['url' => $url, 'headers' => $args['headers'] ?? []];

            return array_shift($queue) ?? ['code' => 200, 'body' => ''];
        });
    }

    private function ref(): FigmaUrl
    {
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        $ref = FigmaUrl::parse('https://www.figma.com/design/aBcDeFgHiJkL/X?node-id=2503-3665');
        $this->assertInstanceOf(FigmaUrl::class, $ref);

        return $ref;
    }

    public function test_the_api_request_uses_the_colon_node_id_and_carries_the_token(): void
    {
        $this->stubTransport([
            ['code' => 200, 'body' => '{"nodes":{"2503:3665":{"document":{}}}}'],
        ]);

        $result = (new FigmaClient('figd_testtoken_longenough'))->fetch_node($this->ref());

        $this->assertIsArray($result);
        $this->assertStringContainsString('2503%3A3665', $this->requests[0]['url'], 'the API wants a colon');
        $this->assertSame('figd_testtoken_longenough', $this->requests[0]['headers']['X-Figma-Token']);
    }

    public function test_an_empty_nodes_map_is_reported_not_treated_as_an_empty_frame(): void
    {
        // Figma answers 200 with `{"nodes":{}}` for a mis-formatted id. Reading that as success
        // is how the dash/colon mistake hides.
        $this->stubTransport([['code' => 200, 'body' => '{"nodes":{}}']]);

        $result = (new FigmaClient('figd_testtoken_longenough'))->fetch_node($this->ref());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('figma_node_not_found', $result->get_error_code());
    }

    /**
     * @dataProvider apiFailures
     */
    public function test_a_failure_is_classified_by_what_the_operator_should_do(
        int $http,
        string $code
    ): void {
        // Measured against the live API: five inspections of one file inside a minute is enough to
        // get a 429, so rate limiting is an ordinary event during a review session. Collapsed into
        // one `figma_api_error` it reads as a dead token, and sends somebody to regenerate a PAT
        // that was working -- the opposite of the correct action, which is to wait.
        $this->stubTransport([['code' => $http, 'body' => '']]);

        $result = (new FigmaClient('figd_testtoken_longenough'))->fetch_node($this->ref());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame($code, $result->get_error_code());
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function apiFailures(): array
    {
        return [
            'rate limited -- wait' => [429, 'figma_rate_limited'],
            'expired token -- re-authorise' => [403, 'figma_forbidden'],
            'unauthorised -- re-authorise' => [401, 'figma_forbidden'],
            'anything else -- report' => [500, 'figma_api_error'],
            'not found -- report' => [404, 'figma_api_error'],
        ];
    }

    public function test_rate_limiting_does_not_blame_the_token(): void
    {
        // The message is the whole point of the distinction, so assert the message.
        $this->stubTransport([['code' => 429, 'body' => '']]);

        $message = (new FigmaClient('figd_testtoken_longenough'))->fetch_node($this->ref())->get_error_message();

        $this->assertStringContainsString('token is fine', $message);
        // Measured by actually rotating one: a freshly issued PAT with the right scopes got the
        // same 429 and the same retry-after, so the budget is per account. Without ruling it out,
        // "the token is fine" still leaves rotating as the obvious next move -- and it costs a
        // token for nothing.
        $this->assertStringContainsString('rotating it will NOT help', $message);
    }

    public function test_the_wait_is_read_from_figma_not_guessed(): void
    {
        // These are the REAL headers this endpoint returned on 2026-11-10. The message used to say
        // "wait about a minute", which was my assumption; the truth was 4.6 days. Telling somebody
        // to retry in a minute when they are locked out until next week is worse than saying
        // nothing, because they keep retrying and keep paying a cost-based limit.
        $this->stubTransport([[
            'code' => 429,
            'body' => '{"status":429,"err":"Rate limit exceeded"}',
            'headers' => ['retry-after' => '395785', 'x-figma-rate-limit-type' => 'high'],
        ]]);

        $error = (new FigmaClient('figd_testtoken_longenough'))->fetch_node($this->ref());

        $this->assertSame('figma_rate_limited', $error->get_error_code());
        $this->assertStringContainsString('4.6 days', $error->get_error_message());
        $this->assertStringContainsString('high', $error->get_error_message());
        // Carried as data too, so a caller can act on it without parsing prose.
        $this->assertSame(395785, $error->get_error_data()['retry_after']);
    }

    public function test_a_missing_retry_after_says_so_rather_than_inventing_one(): void
    {
        $this->stubTransport([['code' => 429, 'body' => '']]);

        $message = (new FigmaClient('figd_testtoken_longenough'))->fetch_node($this->ref())->get_error_message();

        $this->assertStringContainsString('did not say how long', $message);
        $this->assertStringNotContainsString('minute', $message);
    }

    /**
     * @dataProvider waits
     */
    public function test_the_wait_is_phrased_in_units_a_person_acts_on(int $seconds, string $expected): void
    {
        $this->stubTransport([['code' => 429, 'body' => '', 'headers' => ['retry-after' => (string) $seconds]]]);

        $message = (new FigmaClient('figd_testtoken_longenough'))->fetch_node($this->ref())->get_error_message();

        $this->assertStringContainsString($expected, $message);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function waits(): array
    {
        return [
            'seconds stay seconds' => [45, '45 seconds'],
            'minutes once it is minutes' => [600, '10 minutes'],
            'hours once it is hours' => [10800, '3 hours'],
            'the measured lockout' => [395785, '4.6 days'],
        ];
    }

    public function test_the_preview_is_requested_at_half_scale(): void
    {
        $this->stubTransport([
            ['code' => 200, 'body' => '{"images":{"2503:3665":"https://figma-alpha-api.s3.amazonaws.com/img/abc"}}'],
            ['code' => 200, 'body' => 'PNGDATA', 'headers' => ['content-type' => 'image/png']],
        ]);

        $result = (new FigmaClient('figd_testtoken_longenough'))->fetch_preview($this->ref());

        $this->assertIsArray($result);
        $this->assertStringContainsString('scale=0.5', $this->requests[0]['url']);
    }

    public function test_the_image_request_does_not_carry_the_token(): void
    {
        $this->stubTransport([
            ['code' => 200, 'body' => '{"images":{"2503:3665":"https://figma-alpha-api.s3.amazonaws.com/img/abc"}}'],
            ['code' => 200, 'body' => 'PNGDATA', 'headers' => ['content-type' => 'image/png']],
        ]);

        (new FigmaClient('figd_testtoken_longenough'))->fetch_preview($this->ref());

        $this->assertCount(2, $this->requests);
        $this->assertArrayHasKey('X-Figma-Token', $this->requests[0]['headers'], 'the API call is authenticated');
        $this->assertArrayNotHasKey(
            'X-Figma-Token',
            $this->requests[1]['headers'],
            'the signed S3 URL must NOT receive an account-wide credential'
        );
    }

    public function test_an_oversized_preview_is_refused_on_what_arrived(): void
    {
        $this->stubTransport([
            ['code' => 200, 'body' => '{"images":{"2503:3665":"https://figma-alpha-api.s3.amazonaws.com/img/abc"}}'],
            // No Content-Length at all: a cap that trusts the header is not a cap.
            ['code' => 200, 'body' => str_repeat('x', 8388609)],
        ]);

        $result = (new FigmaClient('figd_testtoken_longenough'))->fetch_preview($this->ref());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('figma_image_too_large', $result->get_error_code());
    }

    public function test_a_preview_url_from_an_unexpected_host_is_refused(): void
    {
        // The URL came from the API, not the user — still not a trusted input when what it
        // steers is a server-side fetch.
        $this->stubTransport([
            ['code' => 200, 'body' => '{"images":{"2503:3665":"https://169.254.169.254/latest/meta-data/"}}'],
        ]);

        $result = (new FigmaClient('figd_testtoken_longenough'))->fetch_preview($this->ref());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('figma_image_host', $result->get_error_code());
        $this->assertCount(1, $this->requests, 'the second fetch must never happen');
    }

    public function test_no_token_means_no_request_at_all(): void
    {
        $this->stubTransport([]);

        $result = (new FigmaClient(''))->fetch_node($this->ref());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('figma_no_token', $result->get_error_code());
        $this->assertSame([], $this->requests);
    }
}
