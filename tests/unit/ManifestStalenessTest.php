<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\ManifestBuilder;
use TimberlandAIPageBuilder\ManifestStore;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * A block registered after the manifest was cached has to be picked up on the next generation.
 *
 * **The transient branch is the whole point of these tests.** The first version of this guard sat
 * only on the `get_option` fallback, and a live probe showed it never ran at all: `get()` returns
 * from the transient first, and the transient lasts a day. The code read correctly and did nothing.
 * So each test below runs through BOTH cache layers.
 */
class ManifestStalenessTest extends TestCase
{
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private int $builds = 0;

    protected function setUp(): void
    {
        $this->stubWordPress();
        $this->transients = [];
        $this->options = [];
        $this->builds = 0;

        Functions\when('get_transient')->alias(fn(string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, $v): bool {
            $this->transients[$k] = $v;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $k): bool {
            unset($this->transients[$k]);

            return true;
        });
        Functions\when('update_option')->alias(function (string $k, $v): bool {
            $this->options[$k] = $v;

            return true;
        });
        Functions\when('get_option')->alias(fn(string $k, $d = false) => $this->options[$k] ?? $d);
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
        FakeBlockRegistry::$registered = [];
    }

    /** @param array<int, string> $registered */
    private function store(array $registered, array $manifestBlocks): ManifestStore
    {
        FakeBlockRegistry::$registered = $registered;

        $builder = $this->createMock(ManifestBuilder::class);
        $builder->method('build')->willReturnCallback(function () use ($registered): array {
            ++$this->builds;
            $blocks = [];

            foreach ($registered as $name) {
                if (str_starts_with($name, 'acf/')) {
                    $blocks[$name] = ['name' => $name, 'title' => $name, 'field_key_map' => []];
                }
            }

            return ['blocks' => $blocks, 'generated_at' => 'rebuilt-' . $this->builds];
        });

        $cached = ['blocks' => $manifestBlocks, 'generated_at' => 'cached'];
        $this->transients['taipb_manifest_cache'] = $cached;
        $this->options['taipb_manifest'] = $cached;

        return new ManifestStore($builder);
    }

    public function test_a_newly_registered_block_rebuilds_through_the_transient(): void
    {
        $store = $this->store(
            ['acf/section', 'acf/brand-new'],
            ['acf/section' => ['name' => 'acf/section']]
        );

        $manifest = $store->get();

        $this->assertSame(1, $this->builds, 'the transient must not be served stale');
        $this->assertArrayHasKey('acf/brand-new', $manifest['blocks']);
    }

    public function test_an_unchanged_registry_is_served_from_cache(): void
    {
        // The cost of getting this wrong is a full rebuild per request, each re-reading ~106 ACF
        // JSON files.
        $store = $this->store(
            ['acf/section'],
            ['acf/section' => ['name' => 'acf/section']]
        );

        $store->get();
        $store->get();

        $this->assertSame(0, $this->builds);
    }

    public function test_a_removed_block_does_not_trigger_a_rebuild(): void
    {
        // Deliberately asymmetric. `get()` is not guaranteed to run after every block has
        // registered, and a partially-populated registry looks exactly like removal -- which would
        // rebuild on every request forever. A stale extra entry costs one catalogue line for a
        // block the model cannot successfully use; a rebuild loop costs every request.
        $store = $this->store(
            ['acf/section'],
            ['acf/section' => ['name' => 'acf/section'], 'acf/gone' => ['name' => 'acf/gone']]
        );

        $store->get();

        $this->assertSame(0, $this->builds);
    }

    public function test_core_blocks_never_trigger_a_rebuild(): void
    {
        // The registry is full of core blocks the manifest deliberately excludes. Without the
        // `acf/` filter every request would see them as missing and rebuild.
        $store = $this->store(
            ['acf/section', 'core/paragraph', 'core/heading', 'jetpack/whatever'],
            ['acf/section' => ['name' => 'acf/section']]
        );

        $store->get();

        $this->assertSame(0, $this->builds);
    }
}

/**
 * Stands in for WordPress's registry, which the unit bootstrap does not load.
 *
 * Declared as the global class name so `ManifestStore`'s `class_exists()` guard passes and the real
 * code path runs -- without it the guard returns false and every test here would pass vacuously.
 */
class FakeBlockRegistry
{
    /** @var array<int, string> */
    public static array $registered = [];

    public static function get_instance(): self
    {
        return new self();
    }

    /** @return array<string, object> */
    public function get_all_registered(): array
    {
        $out = [];

        foreach (self::$registered as $name) {
            $out[$name] = (object) ['name' => $name];
        }

        return $out;
    }
}

if (!class_exists('\WP_Block_Type_Registry')) {
    class_alias(FakeBlockRegistry::class, '\WP_Block_Type_Registry');
}
