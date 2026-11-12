<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * VP4 — which published pages still need their post-publish checks, and what came back.
 *
 * ## WordPress never launches a browser
 *
 * The post-publish specs need Playwright, ~7 s and a real Chromium. Shelling out to node from a
 * WordPress request would make the gate a production dependency, and it would be a dependency
 * that is absent on most hosts. So the direction is inverted: this plugin publishes a QUEUE and
 * accepts a REPORT, and the suite -- which already has the browser -- pulls. Nothing here runs a
 * check; it only records that one is owed and what it said.
 *
 * ## The queue is derived, not maintained
 *
 * There is no list of pending pages to keep in step with reality. "Pending" is computed from two
 * facts already stored on the post: it carries generated content, and its last check does not
 * match its current `post_modified_gmt`. A maintained queue can leak entries (a page deleted
 * between enqueue and run), go stale (a page edited after being enqueued but before being
 * checked) and disagree with the posts it describes. A derived one cannot: the answer is a
 * function of the posts themselves, which is the same reason the block manifest reads
 * `WP_Block_Type_Registry` instead of scanning directories.
 *
 * It also gets re-checking right for free. Editing a checked page changes `post_modified_gmt`,
 * which makes the stored report no longer match, which makes the page pending again -- without
 * anything having to notice the edit.
 */
final class CheckQueue
{
    /** Set when a generation targets a post. Presence means "this page has generated content". */
    public const GENERATED_META = '_taipb_generated_at';

    /** The last report, including the `post_modified_gmt` it was taken against. */
    public const REPORT_META = '_taipb_check_report';

    /** A page the runner cannot reach is not worth queueing; only published posts are offered. */
    private const STATUS = 'publish';

    /**
     * Whether a post still owes a check.
     *
     * Pure, and separated from the WordPress plumbing deliberately: this is the whole decision,
     * and a test that had to stand up posts and meta to reach it would be testing the stubs.
     *
     * @param array<string, mixed>|null $report       the stored report, or null if never checked
     * @param string                    $modified_gmt the post's current `post_modified_gmt`
     */
    public static function needs_check(?array $report, string $modified_gmt): bool
    {
        if (null === $report || [] === $report) {
            return true;
        }

        $checked = $report['checked_modified'] ?? null;

        // A report that does not say what it was taken against cannot be matched to the current
        // content, so it is treated as no report at all. Trusting it would pin a page to a result
        // that may describe markup it no longer has.
        if (!is_string($checked) || '' === $checked) {
            return true;
        }

        return $checked !== $modified_gmt;
    }

    /**
     * Record that this post now carries generated content.
     *
     * Called with the generation, not with the publish: at generation time the markup exists but
     * the page may still be a draft, and a draft has no URL for the runner to fetch. Marking here
     * and filtering by status in {@see pending()} keeps the two concerns apart -- what was
     * generated, and what is reachable.
     */
    public static function mark_generated(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        update_post_meta($post_id, self::GENERATED_META, gmdate('c'));
    }

    /**
     * Published pages with generated content whose last check does not match their current state.
     *
     * @return list<array{id: int, path: string, modified: string}>
     */
    public static function pending(int $limit = 20): array
    {
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => self::STATUS,
            'numberposts' => max(1, $limit) * 2,
            'meta_key' => self::GENERATED_META,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        $out = [];

        foreach ((array) $posts as $id) {
            $id = (int) $id;
            $modified = (string) get_post_field('post_modified_gmt', $id);

            if (!self::needs_check(self::report_for($id), $modified)) {
                continue;
            }

            $permalink = get_permalink($id);

            if (!is_string($permalink) || '' === $permalink) {
                continue;
            }

            // A PATH, not a URL. The runner already has a base URL -- its own theme config's --
            // and that is the one the browser must use: a permalink built from a stale `home`
            // option would send the run at a host the gate is not configured for.
            $path = (string) wp_parse_url($permalink, PHP_URL_PATH);

            $out[] = ['id' => $id, 'path' => '' === $path ? '/' : $path, 'modified' => $modified];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function report_for(int $post_id): ?array
    {
        $stored = get_post_meta($post_id, self::REPORT_META, true);

        return is_array($stored) && [] !== $stored ? $stored : null;
    }

    /**
     * Store one report against the state it was taken from.
     *
     * `checked_modified` is read HERE rather than taken from the runner's payload. The runner
     * fetched the page at some point in the past; if the page was edited in between, accepting its
     * word would mark stale markup as checked and the edit would never be re-checked. Reading the
     * current value means an edit mid-run leaves the page pending, which is the safe direction to
     * be wrong in.
     *
     * @param array<string, mixed> $report
     */
    public static function record_report(int $post_id, array $report): void
    {
        if ($post_id <= 0) {
            return;
        }

        update_post_meta($post_id, self::REPORT_META, self::sanitise($report, (string) get_post_field('post_modified_gmt', $post_id)));
    }

    /**
     * Keep only the shape this plugin defines.
     *
     * The payload arrives from a process outside WordPress, so it is treated as input rather than
     * as data: unknown keys are dropped instead of stored, and nothing in it decides what
     * `checked_modified` says.
     *
     * @param  array<string, mixed> $report
     * @return array<string, mixed>
     */
    private static function sanitise(array $report, string $modified_gmt): array
    {
        $specs = [];

        foreach ((array) ($report['specs'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['spec'])) {
                continue;
            }

            $specs[] = [
                'spec' => sanitize_text_field((string) $row['spec']),
                'ran' => max(0, (int) ($row['ran'] ?? 0)),
                'failed' => max(0, (int) ($row['failed'] ?? 0)),
            ];
        }

        return [
            'ok' => (bool) ($report['ok'] ?? false),
            'specs' => $specs,
            // A spec that generated zero tests PASSES, so "covered nothing" is carried as its own
            // signal rather than being folded into `ok`. Without it a vacuous run and a clean run
            // are indistinguishable in the editor.
            'covered_nothing' => array_values(array_map(
                static fn($s): string => sanitize_text_field((string) $s),
                array_filter((array) ($report['covered_nothing'] ?? []), 'is_string')
            )),
            'checked_modified' => $modified_gmt,
            'checked_at' => gmdate('c'),
        ];
    }
}
