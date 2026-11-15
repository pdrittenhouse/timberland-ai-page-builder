<?php

/**
 * WP-CLI adapter for `wp taipb figma-flush`.
 *
 * Deliberately OUTSIDE `includes/`, which is the analysed and unit-tested set. WP-CLI is not a
 * dependency of this plugin, so `WP_CLI::` is an unknown class to PHPStan and an untestable
 * collaborator to PHPUnit. Keeping every decision in {@see \TimberlandAIPageBuilder\FigmaCacheCommand}
 * and only the printing here means the part worth checking is checked, and the part that cannot be
 * is small enough to read in one go.
 *
 * Loaded by Plugin::init_hooks() only when WP-CLI is running.
 *
 * @package TimberlandAIPageBuilder
 */

use TimberlandAIPageBuilder\FigmaCacheCommand;

if (!defined('ABSPATH') || !defined('WP_CLI') || !constant('WP_CLI')) {
    return;
}

WP_CLI::add_command(
    'taipb figma-flush',
    /**
     * Drop cached Figma readings. Never contacts Figma.
     *
     * ## OPTIONS
     *
     * [--url=<url>]
     * : The Figma file or frame URL whose readings should be dropped. Every depth for that frame
     * goes, because depth is part of the cache key and "this frame" means the frame.
     *
     * [--all]
     * : Drop every cached Figma reading, for every frame.
     *
     * ## EXAMPLES
     *
     *     wp taipb figma-flush --url='https://www.figma.com/design/KEY/Name?node-id=0-1'
     *     wp taipb figma-flush --all
     *
     * @param array<int, string>    $args       positional arguments (unused)
     * @param array<string, string> $assoc_args --url / --all
     */
    static function (array $args, array $assoc_args): void {
        $result = FigmaCacheCommand::flush(
            $assoc_args['url'] ?? null,
            isset($assoc_args['all'])
        );

        if (!$result['ok']) {
            WP_CLI::error($result['message']);
        }

        WP_CLI::success($result['message']);
    }
);
