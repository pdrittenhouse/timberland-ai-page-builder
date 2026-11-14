<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FieldKeyMap;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;
use Brain\Monkey\Functions;

/**
 * `FieldKeyMap::build()` must not serve a DEACTIVATED group's fields to any block.
 *
 * `Block: Buttons` is `"active": false` and has been since it was added, yet this class read it
 * off disk and merged its five fields into every block's map regardless -- `load_relevant_groups()`
 * asks ACF only for the DIRECTORIES (`acf_get_setting('load_json')`), then reads every JSON file
 * in them itself, so it never consults ACF's own registry and never sees `active`. ACF itself
 * excludes it: asked for the groups serving a real block, it returns none matching this one.
 *
 * Measured before the fix: 154 of 167 keys this class returned for `acf/table-body` came from
 * that one deactivated group. A writer told `button_list` is a valid field for any block emits
 * it, gets a real `field_xxx` key back for it, and nothing renders -- a silent no-op, which is
 * worse than the key being absent.
 *
 * The mirror of `wildcardGroupsIn()`'s guard in `src/tools/lib/acf-groups.mjs` (the build-time
 * schema generator hit the identical bug, independently, for the identical reason).
 *
 * `#[RunTestsInSeparateProcesses]` is load-bearing, not caution. `StructureToolPromptTest`
 * documents why: `function_exists('get_template_directory')` reports TRUE for every test in the
 * SUITE, not just this class, the moment Brain\Monkey is asked to mock it anywhere -- Patchwork's
 * redefinition is process-global. `GapReportingTest::test_both_tools_route_correctly_...` depends
 * on that guard staying FALSE (it asserts behaviour that only holds when no schema oracle is
 * built), so stubbing `get_template_directory` here without isolation broke that unrelated test
 * on the very first full-suite run -- caught by running `vendor/bin/phpunit` with no filter, not
 * by this file's own tests, which passed in isolation.
 */
#[RunTestsInSeparateProcesses]
class FieldKeyMapActiveGuardTest extends TestCase
{
    use WordPressStubs;

    private string $dir;

    protected function setUp(): void
    {
        $this->stubWordPress();

        // `function_exists('acf_get_setting')` is false in this suite (ACF is not loaded), so
        // `load_relevant_groups()` takes its documented fallback: `get_template_directory() .
        // '/src/acf-json'`. Stubbing `get_template_directory()` to a fixture root -- and writing
        // fixtures into its `src/acf-json` subdirectory, which is what the fallback actually
        // reads -- is enough, without reaching for a real ACF install.
        $this->dir = sys_get_temp_dir() . '/taipb-fieldkeymap-' . uniqid() . '/src/acf-json';
        mkdir($this->dir, 0777, true);
        Functions\when('get_template_directory')->justReturn(dirname($this->dir, 2));
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
        array_map('unlink', glob($this->dir . '/*.json'));
        rmdir($this->dir);
        rmdir(dirname($this->dir));
        rmdir(dirname($this->dir, 2));
    }

    /** @param array<string, mixed> $group */
    private function writeGroup(string $filename, array $group): void
    {
        file_put_contents($this->dir . '/' . $filename, json_encode($group));
    }

    public function test_a_deactivated_wildcard_group_contributes_no_fields_to_any_block(): void
    {
        $this->writeGroup('block-card.json', [
            'key' => 'group_card',
            'title' => 'Block: Card',
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/card']]],
            'fields' => [['key' => 'field_title', 'name' => 'card_title', 'type' => 'text']],
        ]);
        $this->writeGroup('block-buttons.json', [
            'key' => 'group_buttons',
            'title' => 'Block: Buttons',
            'active' => false,
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'all']]],
            'fields' => [['key' => 'field_bl', 'name' => 'button_list', 'type' => 'repeater']],
        ]);

        $map = (new FieldKeyMap())->build();

        $this->assertArrayHasKey('card_title', $map['acf/card'] ?? [], 'the real field must still be present');
        $this->assertArrayNotHasKey(
            'button_list',
            $map['acf/card'] ?? [],
            'a deactivated wildcard must not reach any block, including one that never referenced it'
        );
    }

    public function test_an_active_wildcard_group_still_reaches_every_block(): void
    {
        // The guard must exclude ONLY `active === false`, not wildcards in general -- an active
        // one is the documented, working case this class exists to support.
        $this->writeGroup('block-card.json', [
            'key' => 'group_card',
            'title' => 'Block: Card',
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/card']]],
            'fields' => [],
        ]);
        $this->writeGroup('block-buttons.json', [
            'key' => 'group_buttons',
            'title' => 'Block: Buttons',
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'all']]],
            'fields' => [['key' => 'field_bl', 'name' => 'button_list', 'type' => 'repeater']],
        ]);

        $map = (new FieldKeyMap())->build();

        $this->assertArrayHasKey('button_list', $map['acf/card'] ?? []);
    }

    public function test_a_group_with_no_active_key_at_all_is_treated_as_active(): void
    {
        // ACF itself treats an absent `active` key as active (it defaults the property on save);
        // most groups in the real corpus predate the key existing at all.
        $this->writeGroup('block-card.json', [
            'key' => 'group_card',
            'title' => 'Block: Card',
            // no 'active' key
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/card']]],
            'fields' => [['key' => 'field_title', 'name' => 'card_title', 'type' => 'text']],
        ]);

        $map = (new FieldKeyMap())->build();

        $this->assertArrayHasKey('card_title', $map['acf/card'] ?? []);
    }

    public function test_cloning_from_a_deactivated_module_group_still_works(): void
    {
        // The guard must sit where fields are MERGED into a block's map, not where groups are
        // loaded into group_cache/field_cache -- resolve_clone() looks those caches up directly by
        // key, and nearly every `Module: *` group in the real corpus is deactivated BY DESIGN
        // because it exists only to be cloned, never served to a block on its own.
        $this->writeGroup('block-card.json', [
            'key' => 'group_card',
            'title' => 'Block: Card',
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/card']]],
            'fields' => [[
                'key' => 'field_clone',
                'name' => 'card_width',
                'type' => 'clone',
                'clone' => ['group_width_module'],
                'prefix_name' => 0,
            ]],
        ]);
        $this->writeGroup('module-width.json', [
            'key' => 'group_width_module',
            'title' => 'Module: Width',
            'active' => false,
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'block-modules']]],
            'fields' => [['key' => 'field_w', 'name' => 'width', 'type' => 'number']],
        ]);

        $map = (new FieldKeyMap())->build();

        $this->assertArrayHasKey(
            'width',
            $map['acf/card'] ?? [],
            'a clone sourced from a deactivated Module group must still resolve'
        );
    }
}
