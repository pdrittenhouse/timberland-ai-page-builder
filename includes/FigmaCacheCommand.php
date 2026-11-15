<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

use Timberland\Framework\FigmaUrl;

/**
 * The logic behind `wp taipb figma-flush` — drop cached Figma readings without touching Figma.
 *
 * **Why a command and not just the editor's button.** The panel's *Read it again from Figma* covers
 * the operator sitting in front of a stale reading. It does not cover the cases where nobody is: a
 * designer reports the file moved on while no review is open, a deploy changes what the projection
 * should contain, or a reading is suspected bad and dropping it is cheaper than reasoning about it.
 * Those are all shell-shaped, and before this the plugin registered no CLI commands at all.
 *
 * **Flushing never costs rate limit.** Every path here is local deletes. That matters because the
 * thing being managed is a cost-based limit measured in days: one 429 on this account carried
 * `retry-after: 395785` (4.6 days). A flush tool that re-read to "verify" would spend the budget it
 * exists to protect, so this drops readings and stops — the next inspection pays, visibly.
 *
 * **No `WP_CLI` reference anywhere in here, deliberately.** WP-CLI is not a dependency of this
 * plugin and is not in the analysed set, so a static call to it is an unknown class to PHPStan —
 * and the project's rule is to fix the cause rather than baseline it. Keeping the decision-making
 * here and the printing in `cli/figma-flush.php` means this is analysed and unit-tested like
 * everything else in `includes/`, while the adapter that cannot be either stays three lines long.
 */
class FigmaCacheCommand
{
    /**
     * Drop cached readings, either for one frame or for every frame.
     *
     * Returns rather than prints so the decision is testable without WP-CLI loaded. `ok` is false
     * only for operator error -- a bad or missing URL -- never for "there was nothing cached",
     * which is a successful outcome and a useful thing to be told.
     *
     * @param  string|null $url one frame's Figma URL, or null with $all
     * @param  bool        $all every cached reading, regardless of frame
     * @return array{ok: bool, message: string}
     */
    public static function flush(?string $url, bool $all = false): array
    {
        if (!FigmaSupport::available()) {
            return ['ok' => false, 'message' => FigmaSupport::unavailable_error()->get_error_message()];
        }

        $url = trim((string) $url);

        if ($all && $url !== '') {
            return [
                'ok' => false,
                'message' => 'Pass --url or --all, not both: one is a frame, the other is everything.',
            ];
        }

        if (!$all && $url === '') {
            return [
                'ok' => false,
                'message' => 'Nothing to do. Pass --url=<figma-url> for one frame, or --all for every frame.',
            ];
        }

        if ($all) {
            $dropped = self::forget_all();

            return [
                'ok' => true,
                'message' => sprintf('%d cached Figma reading(s) dropped.', $dropped),
            ];
        }

        $ref = FigmaUrl::parse($url);

        if (is_wp_error($ref)) {
            // The parser owns the host allowlist and id normalising, so its message beats anything
            // restated here.
            return [
                'ok' => false,
                'message' => 'Could not read that as a Figma URL: ' . $ref->get_error_message(),
            ];
        }

        $dropped = FigmaReview::forget($ref);

        if ($dropped === 0) {
            return ['ok' => true, 'message' => 'Nothing was cached for that frame. Nothing to drop.'];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                '%d cached reading(s) dropped for file %s, node %s.',
                $dropped,
                $ref->file_key(),
                $ref->node_id()
            ),
        ];
    }

    /**
     * Every cached Figma reading, regardless of frame.
     *
     * Prefix-matched because the keys are hashed -- `taipb_figma_` plus an md5 of
     * file|node|depth|shape, which cannot be reversed to ask "which frames are cached?".
     *
     * Note the escaped underscore. `_` is a LIKE wildcard and this prefix is full of them, so an
     * unescaped `_transient_taipb_figma_%` matches more than intended; the same trap is recorded in
     * `BrandContext::options()`, where it was first measured wrong. Timeouts are removed through
     * `delete_transient()` rather than a second LIKE, so expiry bookkeeping stays WordPress's
     * problem.
     *
     * @return int how many readings were dropped
     */
    private static function forget_all(): int
    {
        global $wpdb;

        if (!isset($wpdb)) {
            return 0;
        }

        $like = $wpdb->esc_like('_transient_' . FigmaReview::cache_prefix()) . '%';

        /** @var array<int, string>|null $names */
        $names = $wpdb->get_col(
            $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like)
        );

        $dropped = 0;

        foreach ((array) $names as $name) {
            $key = preg_replace('/^_transient_/', '', (string) $name);

            if (is_string($key) && $key !== '' && delete_transient($key)) {
                $dropped++;
            }
        }

        return $dropped;
    }
}
