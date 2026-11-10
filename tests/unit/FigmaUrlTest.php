<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaUrl;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * FG2's security item: a user-supplied URL plus a server-side fetch is SSRF.
 *
 * The assertion that matters is not "a bad URL is rejected" -- it is **"a bad URL is rejected
 * before anything is opened"**. A guard that validates after the fetch has already happened is no
 * guard at all, and the two look identical from a return value. So the fetch functions are stubbed
 * to FAIL THE TEST if they are called, and the rejection path is driven through them.
 */
class FigmaUrlTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        $this->stubWordPress();
        $this->stubUrlParsing();

        // Any network call during parsing is a defect, so make one fail loudly rather than
        // silently succeed and leave the test passing for the wrong reason.
        // `file_get_contents` is a PHP internal: Patchwork cannot redefine it without a
        // `redefinable-internals` entry, and it is not how this code fetches anything anyway.
        foreach (['wp_remote_get', 'wp_safe_remote_get', 'wp_remote_post'] as $fn) {
            Functions\when($fn)->alias(static function () use ($fn) {
                throw new \LogicException("{$fn}() was called during URL validation — the SSRF guard must run FIRST.");
            });
        }
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    private function stubUrlParsing(): void
    {
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_parse_str')->alias(static function (string $s, &$r): void {
            parse_str($s, $r);
        });
    }

    /**
     * Every entry carries a VALID path and node-id, so the ONLY thing that can refuse it is the
     * host check.
     *
     * The first version of this data used bare paths (`https://127.0.0.1/`), and those cases
     * passed even with the allowlist deleted -- the file-key regex was rejecting them, not the
     * security guard. They proved nothing about SSRF. Found by removing the allowlist and seeing
     * only 3 of 15 fail.
     *
     * @return array<string, array{0: string}>
     */
    public static function hostileUrls(): array
    {
        $path = '/file/abcdefghij/x?node-id=1-2';

        return [
            'cloud metadata' => ['https://169.254.169.254' . $path],
            'loopback by name' => ['https://localhost' . $path],
            'loopback by ip' => ['https://127.0.0.1' . $path],
            'octal loopback' => ['https://0177.0.0.1' . $path],
            'short loopback' => ['https://127.1' . $path],
            'ipv6 loopback' => ['https://[::1]' . $path],
            'private range' => ['https://10.0.0.5' . $path],
            'link-local ipv6' => ['https://[fe80::1]' . $path],
            'internal hostname' => ['https://redis.internal' . $path],
            'suffix lookalike' => ['https://figma.com.attacker.test' . $path],
            'prefix lookalike' => ['https://notfigma.com' . $path],
            'subdomain not allowed' => ['https://evil.figma.com.attacker.test' . $path],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:11211/'],
            'http not https' => ['http://www.figma.com' . $path],
            'credentials in url' => ['https://user:pass@www.figma.com' . $path],
            'odd port' => ['https://www.figma.com:8080' . $path],
        ];
    }

    /** @dataProvider hostileUrls */
    public function test_a_hostile_url_is_refused_before_any_fetch(string $url): void
    {
        $result = FigmaUrl::parse($url);

        $this->assertInstanceOf(\WP_Error::class, $result, "accepted a URL it must refuse: {$url}");
    }

    public function test_the_error_does_not_echo_the_attempted_host_back(): void
    {
        $result = FigmaUrl::parse('https://169.254.169.254/latest/meta-data/');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringNotContainsString(
            '169.254.169.254',
            $result->get_error_message(),
            'reflecting the attempted host turns the error into a probe oracle'
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function validUrls(): array
    {
        return [
            'design url, dashed node' => [
                'https://www.figma.com/design/aBcDeFgHiJkL/Marketing?node-id=2503-3665',
                'aBcDeFgHiJkL',
                '2503:3665',
            ],
            'file url, dashed node' => [
                'https://www.figma.com/file/aBcDeFgHiJkL/Marketing?node-id=12-34&t=xyz',
                'aBcDeFgHiJkL',
                '12:34',
            ],
            'already colon-separated' => [
                'https://www.figma.com/design/aBcDeFgHiJkL/X?node-id=2503:3665',
                'aBcDeFgHiJkL',
                '2503:3665',
            ],
            'apex host' => [
                'https://figma.com/design/aBcDeFgHiJkL/X?node-id=1-2',
                'aBcDeFgHiJkL',
                '1:2',
            ],
        ];
    }

    /** @dataProvider validUrls */
    public function test_a_real_share_url_parses(string $url, string $key, string $node): void
    {
        $result = FigmaUrl::parse($url);

        $this->assertInstanceOf(FigmaUrl::class, $result);
        $this->assertSame($key, $result->file_key());
        $this->assertSame($node, $result->node_id(), 'the API wants a colon, the share URL gives a dash');
    }

    public function test_a_link_without_a_frame_is_refused_with_how_to_fix_it(): void
    {
        $result = FigmaUrl::parse('https://www.figma.com/design/aBcDeFgHiJkL/Marketing');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('Copy link to selection', $result->get_error_message());
    }

    public function test_node_id_normalisation_rejects_anything_that_is_not_a_pair(): void
    {
        $this->assertSame('2503:3665', FigmaUrl::normalise_node_id('2503-3665'));
        $this->assertSame('2503:3665', FigmaUrl::normalise_node_id('2503:3665'));
        $this->assertSame('', FigmaUrl::normalise_node_id(''));
        $this->assertSame('', FigmaUrl::normalise_node_id('not-an-id'));
        $this->assertSame('', FigmaUrl::normalise_node_id('1-2-3'));
        $this->assertSame('', FigmaUrl::normalise_node_id('../../etc/passwd'));
    }
}
