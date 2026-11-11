<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\Plugin;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * Two operator overrides that existed in every respect except the one that matters.
 *
 * `token_budget` and `context_mode` each shipped with a default, a docblock describing them as
 * operator controls, and a reader (`PromptBudget`, `MarkupGenerator`). Neither had a field on the
 * settings screen, and `sanitize_settings()` dropped the key even if one had been posted -- so the
 * documented override silently did nothing. These tests cover the dropping half, which is the half
 * that would survive someone adding the field back.
 */
class SettingsOverridesTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        $this->stubWordPress();

        // `sanitize_settings()` is a pure function over WordPress's sanitisers, so those have to
        // exist for it to run at all. Stubbed to the real semantics, not to pass-throughs --
        // `absint` in particular is what stops a negative budget turning enforcement off.
        Functions\when('absint')->alias(static fn($v): int => abs((int) $v));
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_textarea_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_key')->alias(static fn($v): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)));
        Functions\when('wp_unslash')->alias(static fn($v) => is_string($v) ? stripslashes($v) : $v);
        Functions\when('esc_url_raw')->alias(static fn($v): string => (string) $v);
        Functions\when('wp_kses_post')->alias(static fn($v): string => (string) $v);
        Functions\when('sanitize_email')->alias(static fn($v): string => (string) $v);
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    public function test_the_token_budget_survives_a_save(): void
    {
        $clean = Plugin::sanitize_settings(['token_budget' => '16000']);

        $this->assertArrayHasKey('token_budget', $clean, 'the key was dropped on save');
        $this->assertSame(16000, $clean['token_budget']);
    }

    public function test_zero_is_preserved_because_it_means_use_the_default(): void
    {
        // Not "unset" and not "unlimited" -- 0 is the sentinel for "use DEFAULT_CEILING", so
        // coercing it away would change which ceiling applies.
        $this->assertSame(0, Plugin::sanitize_settings(['token_budget' => '0'])['token_budget']);
    }

    public function test_a_nonsense_budget_cannot_become_negative(): void
    {
        // PromptBudget treats <= 0 as UNLIMITED, so a negative here would silently switch
        // enforcement off entirely -- the opposite of what someone typing a number intends.
        foreach (['-500', 'abc', ''] as $bad) {
            $this->assertGreaterThanOrEqual(0, Plugin::sanitize_settings(['token_budget' => $bad])['token_budget']);
        }
    }

    public function test_the_context_mode_survives_a_save(): void
    {
        $this->assertSame('inline', Plugin::sanitize_settings(['context_mode' => 'inline'])['context_mode']);
        $this->assertSame('tools', Plugin::sanitize_settings(['context_mode' => 'tools'])['context_mode']);
    }

    /**
     * @dataProvider badModes
     */
    public function test_an_unrecognised_mode_falls_back_to_tools_not_inline(string $bad): void
    {
        // The readers test `=== 'tools'`, so anything else lands on the inline path -- which
        // measured 6 visible blocks of 48 and 40 invented field names. A typo must not silently
        // downgrade generation quality; it has to fail toward the good path.
        $this->assertSame('tools', Plugin::sanitize_settings(['context_mode' => $bad])['context_mode']);
    }

    /** @return array<string, array{0: string}> */
    public static function badModes(): array
    {
        return [
            'a typo' => ['tolls'],
            'wrong case' => ['Tools'],
            'empty' => [''],
            'something invented' => ['hybrid'],
        ];
    }

    public function test_an_absent_key_falls_back_to_the_shipped_default(): void
    {
        // A partial POST must not wipe a configured value into something unintended.
        $clean = Plugin::sanitize_settings([]);

        $this->assertSame(0, $clean['token_budget']);
        $this->assertSame('tools', $clean['context_mode']);
    }
}
