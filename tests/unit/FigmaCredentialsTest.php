<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaCredentials;
use TimberlandAIPageBuilder\Plugin;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * Storage for an account-wide credential.
 *
 * Two properties worth pinning: what lands in the database is not the token, and the function a
 * settings screen calls does not return the token. The second is how a credential ends up in an
 * HTML response, a browser cache and then a support screenshot.
 */
class FigmaCredentialsTest extends TestCase
{
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        $this->stubWordPress();
        $this->options = [];

        Functions\when('get_option')->alias(fn(string $n, $d = false) => $this->options[$n] ?? $d);
        Functions\when('update_option')->alias(function (string $n, $v, $a = null): bool {
            $this->options[$n] = $v;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $n): bool {
            unset($this->options[$n]);

            return true;
        });

        // What `Plugin::sanitize_settings()` reaches for. Stubbed here rather than in
        // `stubWordPress()` so a test declares what it actually exercises -- the trait's docblock
        // makes that point, and a credential test reaching add_settings_error() is worth seeing.
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->alias(static fn($v): int => abs((int) $v));
        Functions\when('add_settings_error')->justReturn(null);
        Functions\when('is_wp_error')->alias(static fn($t): bool => $t instanceof \WP_Error);

        if (!defined('AUTH_KEY')) {
            define('AUTH_KEY', str_repeat('k', 48));
        }
        if (!defined('AUTH_SALT')) {
            define('AUTH_SALT', str_repeat('s', 48));
        }
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    public function test_the_stored_value_is_not_the_token(): void
    {
        $token = 'figd_averylongfigmapersonalaccesstoken';
        $this->assertTrue(FigmaCredentials::store($token));

        $stored = $this->options['taipb_figma_token'] ?? '';

        $this->assertNotSame($token, $stored);
        $this->assertStringNotContainsString($token, $stored, 'the plaintext must not appear in the option');
        $this->assertStringNotContainsString('figd_', $stored, 'not even the recognisable prefix');
    }

    public function test_it_round_trips(): void
    {
        $token = 'figd_averylongfigmapersonalaccesstoken';
        FigmaCredentials::store($token);

        $this->assertSame($token, FigmaCredentials::get());
        $this->assertTrue(FigmaCredentials::is_configured());
    }

    public function test_a_tampered_ciphertext_yields_nothing_rather_than_garbage(): void
    {
        FigmaCredentials::store('figd_averylongfigmapersonalaccesstoken');

        // Flip a byte in the middle of the ciphertext. GCM's tag is what makes this detectable;
        // without it a mangled value would be sent to Figma as though it were a token.
        $stored = (string) $this->options['taipb_figma_token'];
        $marker = 'enc:v1:';
        $this->assertStringStartsWith($marker, $stored, 'the stored value must carry the version marker');

        $raw = base64_decode(substr($stored, strlen($marker)), true);
        $this->assertIsString($raw);
        $raw[40] = $raw[40] === 'A' ? 'B' : 'A';
        $this->options['taipb_figma_token'] = $marker . base64_encode($raw);

        $this->assertSame('', FigmaCredentials::get());
    }

    public function test_status_reports_without_returning_the_token(): void
    {
        $token = 'figd_averylongfigmapersonalaccesstoken';
        FigmaCredentials::store($token);

        $status = FigmaCredentials::status();

        $this->assertTrue($status['configured']);
        $this->assertSame('option', $status['source']);
        $this->assertSame(strlen($token), $status['length']);
        $this->assertStringNotContainsString($token, (string) wp_json_encode($status));
        // Not even a prefix: a prefix plus a length is a real head start on a brute force.
        $this->assertArrayNotHasKey('preview', $status);
        $this->assertArrayNotHasKey('masked', $status);
    }

    public function test_an_empty_value_clears_it(): void
    {
        FigmaCredentials::store('figd_averylongfigmapersonalaccesstoken');
        FigmaCredentials::store('');

        $this->assertFalse(FigmaCredentials::is_configured());
        $this->assertArrayNotHasKey('taipb_figma_token', $this->options);
    }

    public function test_an_implausibly_short_token_is_refused_at_entry(): void
    {
        $result = FigmaCredentials::store('nope');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('figma_token_short', $result->get_error_code());
    }

    public function test_the_settings_form_routes_the_token_to_its_own_option(): void
    {
        // The form posts `taipb_settings[figma_token]`, but FigmaCredentials owns
        // `taipb_figma_token`. Returning the value into the settings array would store the
        // credential twice, and the copy in `taipb_settings` is the one nothing decrypts on read.
        $saved = Plugin::sanitize_settings(['figma_token' => 'figd_averylongfigmapersonalaccesstoken']);

        $this->assertArrayNotHasKey('figma_token', $saved, 'the token must not be kept in taipb_settings');
        $this->assertSame('figd_averylongfigmapersonalaccesstoken', FigmaCredentials::get());
    }

    public function test_a_blank_submission_keeps_the_saved_token(): void
    {
        // The form never renders the stored value, so an untouched field submits nothing.
        // Treating that as "clear it" would wipe the token on any unrelated settings save.
        FigmaCredentials::store('figd_averylongfigmapersonalaccesstoken');
        Plugin::sanitize_settings(['model' => 'claude-opus-4-6']);

        $this->assertTrue(FigmaCredentials::is_configured());
    }

    public function test_the_sentinel_clears_it_through_the_form(): void
    {
        FigmaCredentials::store('figd_averylongfigmapersonalaccesstoken');
        Plugin::sanitize_settings(['figma_token' => Plugin::CLEAR_SENTINEL]);

        $this->assertFalse(FigmaCredentials::is_configured());
    }

    public function test_the_consent_notice_states_the_real_scope(): void
    {
        // The grant is account-wide, so the wording has to say so. "Connect your Figma account"
        // would be the comfortable version and would misrepresent what is being handed over.
        $notice = FigmaCredentials::CONSENT_NOTICE;

        $this->assertStringContainsString('account-wide', $notice);
        $this->assertStringContainsString('every file', $notice);
        $this->assertStringContainsString('revoke', $notice);
    }
}
