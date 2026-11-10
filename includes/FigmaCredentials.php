<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The per-site Figma personal access token, encrypted at rest.
 *
 * **A Figma PAT is account-wide.** It is not scoped to a file, a project or a team: it can read
 * every file the issuing account can see. For an agency account that is every client's work. So
 * the consent language has to say that plainly rather than "connect your Figma account", and the
 * token deserves better storage than a plain option row -- see {@see self::CONSENT_NOTICE}.
 *
 * **On "the same pattern as the BYOK LLM key".** FG2 asks for that, but the LLM keys are stored
 * in the `taipb_settings` option in **plain text** with a constant override and no encryption at
 * all. So the two halves of that instruction contradict each other. This implements the stronger
 * reading -- option + constant override, encrypted -- and the gap is worth naming: **the Anthropic
 * and OpenAI keys should get the same treatment**, and until they do, "we encrypt your
 * credentials" is not a claim this plugin can make.
 *
 * Encryption is {@see SecretStore}'s, shared with the LLM keys — see there for what it is and is
 * not worth.
 */
class FigmaCredentials
{
    private const OPTION = 'taipb_figma_token';

    /**
     * The consent text. Must be shown where the token is entered, not buried in documentation.
     *
     * Written to the real scope of the grant rather than the comfortable version of it.
     */
    public const CONSENT_NOTICE =
        'A Figma personal access token is account-wide: it can read every file your Figma account '
        . 'can see, not only the file you paste. If this account has access to other clients\' '
        . 'work, that access comes with the token. Use a token from an account scoped to this '
        . 'project where you can, and revoke it in Figma when you are done.';

    /**
     * Store a token, encrypted. An empty string clears it.
     *
     * @return bool|\WP_Error true on success
     */
    public static function store(string $token)
    {
        $token = trim($token);

        if ($token === '') {
            delete_option(self::OPTION);

            return true;
        }

        // Figma PATs are long opaque strings; a short value is a paste error, and storing it
        // would fail later as a confusing 403 rather than here as a clear message.
        if (strlen($token) < 20) {
            return new \WP_Error('figma_token_short', 'That does not look like a Figma personal access token.');
        }

        $encrypted = SecretStore::encrypt($token);

        if ($encrypted === null) {
            return new \WP_Error(
                'figma_token_encrypt_failed',
                'Could not encrypt the token: this site has no usable WordPress salts.'
            );
        }

        update_option(self::OPTION, $encrypted, false);

        return true;
    }

    /**
     * The token, or '' when none is configured.
     *
     * A constant wins over the option, matching `TAIPB_API_KEY`, so an environment can supply it
     * without it ever reaching the database.
     */
    public static function get(): string
    {
        if (defined('TAIPB_FIGMA_TOKEN')) {
            return (string) constant('TAIPB_FIGMA_TOKEN');
        }

        $stored = get_option(self::OPTION, '');

        if (!is_string($stored) || $stored === '') {
            return '';
        }

        // `reveal()` rather than `decrypt()`: an install that stored a token before
        // encryption existed keeps working, and the next `store()` writes it back encrypted.
        return SecretStore::reveal($stored);
    }

    public static function is_configured(): bool
    {
        return self::get() !== '';
    }

    /**
     * Whether a token exists, WITHOUT returning it.
     *
     * What a settings screen should call. Returning the token to render a form field is how a
     * credential ends up in an HTML response, a browser cache and a support screenshot.
     *
     * @return array{configured: bool, source: string, length: int}
     */
    public static function status(): array
    {
        $token = self::get();

        return [
            'configured' => $token !== '',
            'source' => defined('TAIPB_FIGMA_TOKEN') ? 'constant' : 'option',
            // Length only. Never a prefix, never a mask of real characters -- a prefix plus a
            // length is a meaningful head start on a brute force.
            'length' => strlen($token),
        ];
    }
}
