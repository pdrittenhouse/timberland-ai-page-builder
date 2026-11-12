<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UC3 — per-site curation, as an override layer and never as the source.
 *
 * ## Why WordPress is not the source of truth
 *
 * A site owner legitimately wants to say "on this site, never use the Pattern block" without
 * forking a documentation file. But if WordPress held the metadata, a fresh install would carry
 * none of it, a block could not ship its own guidance alongside itself, and no change would be
 * reviewable in a diff. So the repo `.md` stays authoritative and this sits on top of it, in the
 * same three layers the framework already uses for `options_enable_full_page_cache`:
 *
 *   1. **Repo `.md`** — the shipped default, version-controlled, present on a fresh install.
 *   2. **Site override** — this option, edited in admin.
 *   3. **Filter** — programmatic, last word.
 *
 * ## The merge is per FIELD, and empty means inherit
 *
 * An override that fills in `configuration` must not blank `best_for` and `avoid_when`. This is
 * the same assign-versus-union mistake that has now bitten this stack four times -- a tier scan
 * that assigned erased earlier tiers, and then a per-file resolve that dropped every section a
 * fork did not restate. Here it would be an operator writing one note and silently deleting two
 * others they never saw.
 *
 * So an empty field means INHERIT, never "blank it". That makes a deliberate blanking
 * impossible, which is the right trade: suppressing guidance is served by overriding it with
 * different text, and the alternative -- a form where clearing a textarea deletes the shipped
 * default -- loses data to an ordinary mistake. (`Plugin::CLEAR_SENTINEL` is the established
 * pattern here if an explicit clear is ever wanted.)
 */
final class CuratedOverrides
{
    /** Site overrides, keyed by BARE slug (`card`, not `acf/card`). */
    public const OPTION = 'taipb_curated_overrides';

    /**
     * The last word, applied after the option.
     *
     * @param array{best_for: string, avoid_when: string, configuration: string} $merged
     * @param string                                                            $slug bare slug
     */
    public const FILTER = 'taipb_curated_metadata';

    /** The three curated fields, in the order the admin screen shows them. */
    public const FIELDS = ['best_for', 'avoid_when', 'configuration'];

    /** @var array<string, array<string, string>>|null */
    private static ?array $cache = null;

    /**
     * Merge one block's shipped default with one block's override, field by field.
     *
     * Pure, and separated from everything else deliberately: this is the entire decision, and a
     * test that had to stand up options and filters to reach it would be testing WordPress.
     *
     * @param  array<string, mixed> $default  from the build artifact
     * @param  array<string, mixed> $override from the option
     * @return array{best_for: string, avoid_when: string, configuration: string}
     */
    public static function merge(array $default, array $override): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $overridden = trim((string) ($override[$field] ?? ''));

            $out[$field] = '' !== $overridden
                ? $overridden
                : trim((string) ($default[$field] ?? ''));
        }

        return $out;
    }

    /**
     * Every stored override, sanitised on read as well as on write.
     *
     * Read-side sanitising is not redundant: the option can be written by WP-CLI, a migration or
     * another plugin, none of which pass through this class's save path, and the values end up in
     * a prompt.
     *
     * @return array<string, array<string, string>> bare slug -> field -> value
     */
    public static function stored(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $raw = get_option(self::OPTION, []);
        self::$cache = is_array($raw) ? self::sanitise($raw) : [];

        return self::$cache;
    }

    /**
     * The curation actually in force for one block.
     *
     * @param  array<string, mixed> $default
     * @return array{best_for: string, avoid_when: string, configuration: string}
     */
    public static function for_block(string $slug, array $default): array
    {
        $slug = self::bare($slug);
        $merged = self::merge($default, self::stored()[$slug] ?? []);

        /**
         * Filter the curated metadata for one block, after the site override.
         *
         * @param array{best_for: string, avoid_when: string, configuration: string} $merged
         * @param string                                                            $slug
         */
        $filtered = apply_filters(self::FILTER, $merged, $slug);

        // A filter returning something unusable must not take the block's guidance with it: the
        // merged value is the fallback, so a bad filter degrades to the shipped default rather
        // than to nothing.
        return is_array($filtered) ? self::merge($merged, $filtered) : $merged;
    }

    /**
     * Store the admin screen's submission.
     *
     * Blocks with nothing overridden are dropped rather than stored as empty strings, so the
     * option stays small and "has an override" stays a question the stored data can answer.
     *
     * @param array<string, mixed> $input slug -> field -> value
     */
    public static function save(array $input): void
    {
        $clean = self::sanitise($input);

        self::$cache = null;

        if ([] === $clean) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, $clean);
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, array<string, string>>
     */
    private static function sanitise(array $raw): array
    {
        $clean = [];

        foreach ($raw as $slug => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            $slug = self::bare(sanitize_key((string) $slug));

            if ('' === $slug) {
                continue;
            }

            $kept = [];

            foreach (self::FIELDS as $field) {
                // `wp_kses_post`, not `sanitize_text_field`: `configuration` is multi-line prose
                // and a newline is meaningful in it. `sanitize_text_field` strips newlines, which
                // would silently flatten a three-point configuration note into one run-on line.
                $value = trim(wp_kses_post((string) ($fields[$field] ?? '')));

                if ('' !== $value) {
                    $kept[$field] = $value;
                }
            }

            if ([] !== $kept) {
                $clean[$slug] = $kept;
            }
        }

        return $clean;
    }

    /** `acf/card` and `card` are one block; the artifacts key on the bare slug. */
    private static function bare(string $slug): string
    {
        $slash = strrpos($slug, '/');

        return false === $slash ? $slug : substr($slug, $slash + 1);
    }

    /** Test seam: the option is cached per request, and a test changes it between assertions. */
    public static function flush_cache(): void
    {
        self::$cache = null;
    }
}
