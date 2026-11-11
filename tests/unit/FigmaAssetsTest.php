<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaAssets;
use TimberlandAIPageBuilder\FigmaClient;
use TimberlandAIPageBuilder\FigmaUrl;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * Importing a design's own images, which is what made image fields reachable at all.
 *
 * The fidelity report showed a page with 10 image slots described and 0 set, and that was
 * *unreachable* rather than ignored: the plan named an image and gave the model nothing to
 * reference. These pin the parts that write to a real media library.
 */
class FigmaAssetsTest extends TestCase
{
    use WordPressStubs;

    /** @var array<int, array{ref: string, file: string}> */
    private array $created = [];

    /** @var array<string, int> ref => attachment id, standing in for the meta query */
    private array $existing = [];

    protected function setUp(): void
    {
        $this->stubWordPress();
        $this->created = [];
        $this->existing = [];

        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('is_wp_error')->alias(static fn($t): bool => $t instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r): int => $r['code'] ?? 200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r): string => $r['body'] ?? '');
        Functions\when('wp_remote_retrieve_header')->alias(
            static fn(array $r, string $h): string => $r['headers'][$h] ?? ''
        );
        Functions\when('get_posts')->alias(function (array $args) {
            $ref = $args['meta_value'] ?? '';

            return isset($this->existing[$ref]) ? [$this->existing[$ref]] : [];
        });
        Functions\when('wp_upload_bits')->alias(static fn(string $name, $x, string $bits) => [
            'file' => '/tmp/' . $name,
            'url' => 'https://example.test/' . $name,
            'error' => false,
        ]);
        Functions\when('wp_insert_attachment')->alias(function (array $post, string $file) {
            $id = 500 + count($this->created);
            $this->created[$id] = ['file' => $file, 'ref' => ''];

            return $id;
        });
        Functions\when('wp_generate_attachment_metadata')->justReturn([]);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value) {
            if (FigmaAssets::REF_META === $key && isset($this->created[$id])) {
                $this->created[$id]['ref'] = (string) $value;
            }

            return true;
        });
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    private function ref(): FigmaUrl
    {
        $ref = FigmaUrl::parse('https://www.figma.com/design/aBcDeFgHiJkL/X?node-id=1-2');
        $this->assertInstanceOf(FigmaUrl::class, $ref);

        return $ref;
    }

    /**
     * @param array<string, string> $urls
     */
    private function client(array $urls, string $mime = 'image/png', int $size = 1024): FigmaClient
    {
        return new class ($urls, $mime, $size) extends FigmaClient {
            public int $urlCalls = 0;

            public int $byteCalls = 0;

            /** @param array<string, string> $urls */
            public function __construct(private array $urls, private string $mime, private int $size)
            {
                parent::__construct('figd_stub');
            }

            public function fetch_asset_urls(FigmaUrl $ref)
            {
                ++$this->urlCalls;

                return $this->urls;
            }

            public function fetch_image_bytes(string $image_url, ?int $max_bytes = null)
            {
                ++$this->byteCalls;

                return [
                    'bytes' => str_repeat('x', $this->size),
                    'content_type' => $this->mime,
                    'url_host' => 's3-alpha-sig.figma.com',
                ];
            }
        };
    }

    public function test_an_asset_is_imported_and_tagged_with_its_figma_ref(): void
    {
        // The tag is both the provenance record and the dedupe key, so an untagged import would
        // silently duplicate on every run.
        $client = $this->client(['ref-a' => 'https://s3-alpha-sig.figma.com/a.png']);

        $result = (new FigmaAssets($client))->import($this->ref(), ['ref-a']);

        $this->assertCount(1, $result['imported']);
        $this->assertSame([], $result['failed']);
        $this->assertSame('ref-a', reset($this->created)['ref']);
    }

    public function test_an_already_imported_ref_is_reused_not_duplicated(): void
    {
        // Idempotence. A design iterated five times must not leave five copies of an unchanged
        // photograph, and the refs are content hashes so "unchanged" is knowable.
        $this->existing['ref-a'] = 99;
        $client = $this->client(['ref-a' => 'https://s3-alpha-sig.figma.com/a.png']);

        $result = (new FigmaAssets($client))->import($this->ref(), ['ref-a']);

        $this->assertSame(['ref-a' => 99], $result['reused']);
        $this->assertSame([], $result['imported']);
    }

    public function test_nothing_new_means_no_figma_call_at_all(): void
    {
        // The URL map is one request per FILE against a cost-based limit measured in DAYS. Spending
        // one to discover there was nothing to download would be the expensive kind of no-op.
        $this->existing['ref-a'] = 99;
        $client = $this->client(['ref-a' => 'https://s3-alpha-sig.figma.com/a.png']);

        (new FigmaAssets($client))->import($this->ref(), ['ref-a']);

        $this->assertSame(0, $client->urlCalls);
        $this->assertSame(0, $client->byteCalls);
    }

    public function test_a_ref_figma_cannot_resolve_is_reported_not_skipped(): void
    {
        // It means the design has a picture this page cannot get. Silence would present a page
        // missing imagery as a complete one.
        $client = $this->client([]);

        $result = (new FigmaAssets($client))->import($this->ref(), ['ref-missing']);

        $this->assertArrayHasKey('ref-missing', $result['failed']);
        $this->assertSame([], $result['imported']);
    }

    public function test_an_unexpected_content_type_is_refused_before_anything_is_written(): void
    {
        // Checked on what the server SAID it sent, before a file exists on disk. A download whose
        // type we do not recognise must not become a file first and a question later.
        $client = $this->client(['ref-a' => 'https://s3-alpha-sig.figma.com/a.bin'], 'application/zip');

        $result = (new FigmaAssets($client))->import($this->ref(), ['ref-a']);

        $this->assertArrayHasKey('ref-a', $result['failed']);
        $this->assertSame([], $this->created, 'nothing should have been written');
    }

    public function test_an_import_is_capped_and_says_so(): void
    {
        // A band can reference a dozen images and a whole file 168. Truncating silently would
        // present a page missing most of its imagery with no explanation.
        $refs = [];
        $urls = [];

        for ($i = 0; $i < FigmaAssets::MAX_PER_IMPORT + 5; ++$i) {
            $refs[] = "ref-{$i}";
            $urls["ref-{$i}"] = "https://s3-alpha-sig.figma.com/{$i}.png";
        }

        $result = (new FigmaAssets($this->client($urls)))->import($this->ref(), $refs);

        $this->assertSame(5, $result['truncated']);
        $this->assertCount(FigmaAssets::MAX_PER_IMPORT, $result['imported']);
    }

    public function test_duplicate_refs_in_one_request_are_fetched_once(): void
    {
        $client = $this->client(['ref-a' => 'https://s3-alpha-sig.figma.com/a.png']);

        $result = (new FigmaAssets($client))->import($this->ref(), ['ref-a', 'ref-a', 'ref-a']);

        $this->assertCount(1, $result['imported']);
        $this->assertSame(1, $client->byteCalls);
    }

    public function test_no_refs_is_a_no_op_rather_than_an_error(): void
    {
        $client = $this->client([]);

        $result = (new FigmaAssets($client))->import($this->ref(), []);

        $this->assertSame([], $result['imported']);
        $this->assertSame(0, $client->urlCalls);
    }

    public function test_the_asset_cap_is_larger_than_the_preview_cap(): void
    {
        // Measured: 6 of the 24 images on the real Home page exceed the preview's 8 MB, and a
        // skipped hero photograph is the failure this whole class exists to fix.
        $this->assertGreaterThan(8388608, FigmaAssets::MAX_ASSET_BYTES);
    }
}
