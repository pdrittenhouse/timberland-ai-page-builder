<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The accumulated record of what the library could not express.
 *
 * Stored in an option rather than a table: gaps are low-volume by nature (a gap per generation
 * would mean the catalogue is badly wrong), and a bounded ring needs no migration. If this ever
 * outgrows an option, that fact is itself the finding.
 *
 * **Bounded on purpose.** An unbounded option grows the autoloaded row and slows every request on
 * the site -- a reporting feature degrading page loads would be a poor trade. The oldest entries
 * are dropped, because the recent ones are the ones a roadmap acts on.
 */
class GapLog
{
    private const OPTION = 'taipb_gap_log';

    /** Keeping the newest N. Chosen to stay well inside a comfortable option size. */
    private const MAX_ENTRIES = 200;

    /**
     * @param array<int, array{need: string, why: string, closest: string}> $gaps
     */
    public static function record(array $gaps, string $prompt, int $user_id): void
    {
        if ($gaps === []) {
            return;
        }

        $log = get_option(self::OPTION, []);

        if (!is_array($log)) {
            $log = [];
        }

        $now = current_time('mysql', true);

        foreach ($gaps as $gap) {
            $log[] = [
                'need' => (string) $gap['need'],
                'why' => (string) $gap['why'],
                'closest' => (string) ($gap['closest'] ?? ''),
                'prompt' => $prompt,
                'user_id' => $user_id,
                'at' => $now,
            ];
        }

        if (count($log) > self::MAX_ENTRIES) {
            $log = array_slice($log, -self::MAX_ENTRIES);
        }

        // Not autoloaded: this is read by a report, never on a page view.
        update_option(self::OPTION, $log, false);
    }

    /**
     * @return array<int, array{need: string, why: string, closest: string, prompt: string, user_id: int, at: string}>
     */
    public static function all(): array
    {
        $log = get_option(self::OPTION, []);

        return is_array($log) ? $log : [];
    }

    /**
     * The gaps most often hit, which is the question a roadmap actually asks.
     *
     * Grouped by `need` verbatim rather than by any similarity measure: two requesters phrasing
     * the same need differently will group separately, and that is the honest answer until there
     * is enough volume to justify something cleverer. Pretending to cluster would invent
     * confidence the data does not support.
     *
     * @return array<int, array{need: string, count: int, latest: string}>
     */
    public static function ranked(): array
    {
        $by_need = [];

        foreach (self::all() as $entry) {
            $need = $entry['need'];

            if (!isset($by_need[$need])) {
                $by_need[$need] = ['need' => $need, 'count' => 0, 'latest' => ''];
            }

            $by_need[$need]['count']++;

            if ($entry['at'] > $by_need[$need]['latest']) {
                $by_need[$need]['latest'] = $entry['at'];
            }
        }

        // usort reindexes in place, so no array_values is needed.
        usort($by_need, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return $by_need;
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
