<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encryption for credentials this plugin has to keep: API keys, access tokens.
 *
 * One implementation, used by every secret. `FigmaCredentials` had its own copy first, and two
 * copies of a cipher is one more than anyone will keep correct.
 *
 * **What it is worth, stated honestly.** The key derives from the WordPress salts, which live in
 * `wp-config.php`. A **database dump alone does not yield the secret** — the common breach shape,
 * and the one that covers an exposed backup, a leaked read-replica or SQL injection. It does NOT
 * protect against anyone who can read the filesystem, because they have the salts too. That is the
 * ceiling for any WordPress-resident secret; a plugin claiming more would be lying.
 *
 * **Why the marker matters.** Stored values carry a `enc:v1:` prefix. Without it, migrating an
 * install with existing plaintext keys means guessing whether a stored string is ciphertext or a
 * real key — and "try to decrypt, fall back to plaintext" silently treats a corrupted ciphertext
 * as a valid key and sends it to the provider. The prefix makes the question unambiguous and the
 * version lets the cipher change later without re-reading every row blind.
 */
class SecretStore
{
    private const MARKER = 'enc:v1:';

    private const CIPHER = 'aes-256-gcm';

    /** Whether a stored value is one of ours, as opposed to a plaintext leftover. */
    public static function is_encrypted(string $value): bool
    {
        return str_starts_with($value, self::MARKER);
    }

    /** Whether this install can encrypt at all. */
    public static function is_available(): bool
    {
        return self::key() !== null && function_exists('openssl_encrypt');
    }

    /**
     * Encrypt, or return null when this install cannot.
     *
     * Null rather than the plaintext: a caller that cannot tell the difference would store a raw
     * secret believing it was protected.
     */
    public static function encrypt(string $plain): ?string
    {
        $key = self::key();

        if ($plain === '' || $key === null || !function_exists('openssl_encrypt')) {
            return null;
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            return null;
        }

        // GCM's tag is what makes tampering detectable. Without it a modified ciphertext decrypts
        // to garbage, and garbage gets sent to the provider as though it were a credential.
        return self::MARKER . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decrypt a marked value. Returns null for anything unmarked, tampered or undecryptable.
     *
     * An unmarked value is NOT returned as-is: that decision belongs to the caller, which is the
     * only place that knows whether a plaintext leftover is expected (a migration) or a bug.
     */
    public static function decrypt(string $stored): ?string
    {
        $key = self::key();

        if ($key === null || !self::is_encrypted($stored) || !function_exists('openssl_decrypt')) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::MARKER)), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        return $plain === false ? null : $plain;
    }

    /**
     * Read a stored value that may be either encrypted or a plaintext leftover.
     *
     * The migration seam. An unmarked value is returned verbatim so an install that predates
     * encryption keeps working, and the caller re-saves it encrypted.
     */
    public static function reveal(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (!self::is_encrypted($stored)) {
            return $stored;
        }

        return self::decrypt($stored) ?? '';
    }

    private static function key(): ?string
    {
        $material = (defined('AUTH_KEY') ? (string) constant('AUTH_KEY') : '')
            . (defined('AUTH_SALT') ? (string) constant('AUTH_SALT') : '');

        // A default wp-config has 64-char salts. Anything this short means they were never
        // generated, and deriving a key from a predictable string is worse than refusing.
        if (strlen($material) < 32) {
            return null;
        }

        return hash('sha256', $material, true);
    }
}
