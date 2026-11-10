<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\Plugin;
use TimberlandAIPageBuilder\SecretStore;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * The LLM keys, encrypted at rest — and the two ways that goes wrong.
 *
 * **Losing the key on an unrelated save.** The form no longer renders the stored value, so an
 * untouched field submits an empty string. Treating that as "clear it" wipes a working credential
 * every time someone changes the model dropdown. That is the classic WordPress settings bug and
 * it is the first thing asserted here.
 *
 * **Migrating an install that already has plaintext.** Those keys must keep working on the next
 * read and be re-encrypted on the next save, with no manual step.
 */
class ApiKeyEncryptionTest extends TestCase
{
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        $this->options = [];
        $this->stubWordPress();

        Functions\when('get_option')->alias(fn(string $n, $d = false) => $this->options[$n] ?? $d);
        Functions\when('update_option')->alias(function (string $n, $v, $a = null): bool {
            $this->options[$n] = $v;

            return true;
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->alias(static fn($v): int => abs((int) $v));

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

    /** @param array<string, mixed> $input */
    private function save(array $input): array
    {
        $saved = Plugin::sanitize_settings($input);
        $this->options['taipb_settings'] = $saved;

        return $saved;
    }

    public function test_a_saved_key_is_not_stored_in_plaintext(): void
    {
        $key = 'sk-ant-api03-averylongexamplekeyvalue';
        $saved = $this->save(['api_key' => $key]);

        $this->assertNotSame($key, $saved['api_key']);
        $this->assertTrue(SecretStore::is_encrypted($saved['api_key']));
        $this->assertStringNotContainsString($key, $saved['api_key']);
        $this->assertStringNotContainsString('sk-ant', $saved['api_key'], 'not even the prefix');
    }

    public function test_it_decrypts_transparently_for_every_existing_caller(): void
    {
        $key = 'sk-ant-api03-averylongexamplekeyvalue';
        $this->save(['api_key' => $key]);

        // ClaudeClient, OpenAIClient and the REST controller all read it this way.
        $this->assertSame($key, Plugin::get_settings()['api_key']);
    }

    public function test_an_empty_submission_keeps_the_saved_key(): void
    {
        $key = 'sk-ant-api03-averylongexamplekeyvalue';
        $this->save(['api_key' => $key]);

        // Someone changes the model dropdown and saves; the key field was never touched.
        $this->save(['model' => 'claude-opus-4-6']);

        $this->assertSame($key, Plugin::get_settings()['api_key'], 'an unrelated save must not wipe the credential');
    }

    public function test_the_sentinel_clears_the_key(): void
    {
        $this->save(['api_key' => 'sk-ant-api03-averylongexamplekeyvalue']);
        $this->save(['api_key' => Plugin::CLEAR_SENTINEL]);

        $this->assertSame('', Plugin::get_settings()['api_key']);
    }

    public function test_an_existing_plaintext_key_keeps_working_and_is_re_encrypted_on_save(): void
    {
        $key = 'sk-ant-api03-averylongexamplekeyvalue';

        // An install that predates encryption.
        $this->options['taipb_settings'] = ['api_key' => $key];

        $this->assertSame($key, Plugin::get_settings()['api_key'], 'the plaintext leftover must still work');

        // The next save -- of anything -- migrates it without a manual step.
        $this->save(['model' => 'claude-opus-4-6']);

        $this->assertSame($key, Plugin::get_settings()['api_key'], 'still readable after migration');
    }

    public function test_a_resubmitted_ciphertext_does_not_double_encrypt(): void
    {
        $key = 'sk-ant-api03-averylongexamplekeyvalue';
        $saved = $this->save(['api_key' => $key]);

        // Should not be reachable now that the form posts an empty field, but double-encrypting
        // would destroy the key irrecoverably, so it is guarded and asserted.
        $this->save(['api_key' => $saved['api_key']]);

        $this->assertSame($key, Plugin::get_settings()['api_key']);
    }

    public function test_status_reports_configured_and_encrypted_without_the_key(): void
    {
        $key = 'sk-ant-api03-averylongexamplekeyvalue';
        $this->save(['api_key' => $key]);

        $status = Plugin::credential_status();

        $this->assertTrue($status['api_key']['configured']);
        $this->assertTrue($status['api_key']['encrypted']);
        $this->assertFalse($status['openai_api_key']['configured']);
        $this->assertStringNotContainsString($key, (string) wp_json_encode($status));
    }

    public function test_a_plaintext_leftover_is_reported_as_unencrypted(): void
    {
        // An operator is entitled to know which of the two they have, rather than a UI that
        // claims encryption uniformly.
        $this->options['taipb_settings'] = ['api_key' => 'sk-ant-api03-averylongexamplekeyvalue'];

        $status = Plugin::credential_status();

        $this->assertTrue($status['api_key']['configured']);
        $this->assertFalse($status['api_key']['encrypted']);
    }
}
