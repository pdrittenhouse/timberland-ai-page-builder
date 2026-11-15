<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Is the framework's Figma layer actually here?
 *
 * **Why this exists.** `FigmaStructure`, `ChromeHints`, `FigmaUrl`, `FigmaDecomposer` and `FigmaClient` used to
 * live in this plugin and now live in the framework, as `Timberland\Framework\*`. The framework
 * arrives through the active theme's `vendor/`, which this plugin deliberately does not require:
 * requiring it would put a second copy on disk that could shadow the theme's at runtime, which is a
 * worse problem than the duplication the relocation removed.
 *
 * So the dependency is real but soft, and the failure mode changed. Before the move, a site with no
 * Timberland theme still had these classes; now it does not, and an unguarded Figma request would
 * fatal on a missing class instead of answering. A fatal in a REST handler reaches an operator as a
 * dead panel with nothing in it, which is the least useful way to say "the theme this plugin is for
 * is not installed".
 *
 * Nothing here attempts a fallback. There is no honest one: the plugin cannot read a Figma file
 * without the reader, and shipping a second copy to cover the case is the thing being undone. The
 * only correct behaviour is to say so plainly, which is also true of the rest of this plugin --
 * without a Timberland theme there are no block manifests, no `src/acf-json`, and no catalogue to
 * generate against either. This guard makes one existing, unstated requirement visible at the point
 * it first bites.
 */
class FigmaSupport
{
    /**
     * One class is enough to test with: they ship together or not at all, from one `src/`
     * directory under one PSR-4 prefix. Checking all five would imply they can arrive separately.
     */
    private const SENTINEL = 'Timberland\\Framework\\FigmaUrl';

    public static function available(): bool
    {
        return class_exists(self::SENTINEL);
    }

    /**
     * Say so in the admin, once, rather than only when somebody tries to use it.
     *
     * The 501 from {@see self::unavailable_error()} is honest but LATE: it answers a request the
     * operator has already made, which means the first sign anything is wrong is a Figma import
     * that refuses. The requirement is knowable at page load, so it is stated at page load.
     *
     * **Warning, not error, and never a deactivation.** Everything in this plugin that does not
     * read Figma still works -- prompt-driven generation, the block catalogue, the checks queue --
     * so disabling it over a missing parent theme would take away more than the gap costs. The
     * notice says which half is unavailable and why, and leaves the rest alone.
     *
     * Scoped to users who could act on it (`switch_themes`), because somebody without the
     * capability cannot install a theme and the notice is then just noise on every screen.
     */
    public static function register_admin_notice(): void
    {
        if (self::available()) {
            return;
        }

        add_action('admin_notices', static function (): void {
            if (!current_user_can('switch_themes')) {
                return;
            }

            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Timberland AI Page Builder: Figma import is unavailable.', 'timberland-ai-page-builder'),
                esc_html__(
                    'The Figma reader ships with the Timberland framework, which arrives with a '
                    . 'Timberland parent theme. Activate one to enable Figma import. Everything '
                    . 'else in this plugin continues to work.',
                    'timberland-ai-page-builder'
                )
            );
        });
    }

    /**
     * The refusal, shaped like every other REST failure in this controller.
     *
     * 501 rather than 400 or 500: the request was fine and the server is fine, the capability is
     * genuinely not installed here. A 400 would send an operator looking at their URL, and a 500
     * would send them looking at the log.
     */
    public static function unavailable_error(): \WP_Error
    {
        return new \WP_Error(
            'taipb_figma_unavailable',
            'The Figma reader is part of the Timberland framework, which this site does not have. '
            . 'It arrives with a Timberland parent theme; the rest of this plugin needs that theme '
            . 'too, for the block manifests it generates against.',
            ['status' => 501]
        );
    }
}
