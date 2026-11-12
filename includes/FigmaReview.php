<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What an operator is shown before a Figma design becomes blocks.
 *
 * **This exists because the review step already existed.** `/structure` contracts to build "from a
 * confirmed layout plan", so the Figma path's job is to produce a plan worth confirming -- not to
 * grow a second flow with its own confirmation. A separate Figma pipeline would have been the one
 * path where nobody checks the plan, and it is the path where checking matters most: the operator
 * can see their own file and the model cannot.
 *
 * **Everything in one response, deliberately.** The obvious design is "list the candidates, then
 * fetch the one the operator picked", and it is wrong twice over. The file is one metered,
 * rate-limited, multi-megabyte fetch, and the operator *will* switch pages -- that is what reviewing
 * is -- so the second request is the common case, not the exception. Deriving every candidate's
 * decomposition from the single fetched tree costs local CPU and ~26 KB of JSON, and makes page
 * switching free.
 *
 * **And the derived result is cached, which the first version argued was unnecessary.** That
 * argument was "one fetch per inspection, nothing to invalidate", and it was reasoning about a
 * single session. Then the real 429 arrived carrying `retry-after: 395785` -- **4.6 days** -- with
 * `x-figma-rate-limit-type: high`. The limit is cost-based, roughly eight reads of one 3.9 MB file
 * exhausted the budget, and the lockout is measured in days rather than the minute I had assumed.
 * At that price the constraint is not "do not fetch twice in one session", it is "do not fetch the
 * same file twice at all" -- and without a cache, re-pasting the same link, reopening the editor or
 * a second person reviewing the same design each pays again and deepens the lockout. So the
 * PROJECTION is cached (~26 KB), never the 3.9 MB payload, keyed by file + node + depth and
 * independent of which token fetched it, because the file's content does not depend on that.
 *
 * **Depth is reported, never silently chosen.** `FigmaStructure::pages()` descends, and how far
 * down the page designs sit is a property of the file. The deeper levels surface frame/child pairs
 * carrying the same text -- harmless for a model choosing semantically, but an operator shown two
 * rows both reading "Our Roots. Our Reason. Our Future." will reasonably conclude the tool is
 * broken. So each candidate reports its own `depth` and `parent`, the caller renders the nesting
 * rather than being handed a flat list that hides it, and `truncated` is returned so a caller can
 * tell "that is the whole file" from "there are more I did not show you". See
 * {@see DEFAULT_DEPTH} for why the default is the top level only.
 */
class FigmaReview
{
    /**
     * Zero -- the top level only -- and deliberately NOT `pages()`'s own default of 2.
     *
     * This started as 2, reasoned from "the review route and the model-facing tool should not
     * drift apart". Measured against one real file, that reasoning was simply wrong:
     *
     * | depth | candidates | truncated | named |
     * |-------|-----------:|----------:|------:|
     * |   0   |         17 |         0 |    13 |
     * |   1   |         40 |        33 |    10 |
     * |   2   |         40 |       121 |    10 |
     *
     * Descending gives an operator **more rows and fewer names**, because the 40-candidate cap
     * then evicts the named top-level frames in favour of their own children. At depth 0 the list
     * opens with the seven real page frames; at depth 1, thirty-three candidates are not listed at
     * all, and nothing guarantees the operator's page is among the forty that survive. A review
     * list that can silently omit the thing being reviewed is worse than a short one.
     *
     * The model-facing {@see FigmaContextTool} keeps 2 on purpose. It chooses semantically from
     * evidence and benefits from seeing nested candidates; an operator is scanning for the frame
     * they already recognise. Different audiences, so the divergence is the point rather than
     * drift -- and a caller who wants the deeper view can still ask for it.
     */
    public const DEFAULT_DEPTH = 0;

    /** A file can legitimately nest designs, but a deep scan is a lot of fruitless recursion. */
    public const MAX_DEPTH = 4;

    /**
     * How long a derived projection stays usable.
     *
     * An hour is a compromise between the two real costs. Too short and an operator reviewing a
     * handful of pages re-reads the file and pays the cost-based limit again; too long and a
     * designer's update is invisible with no obvious reason why. `$refresh` exists for the second
     * case, so the TTL only has to cover an ordinary review session.
     */
    public const CACHE_TTL = 3600;

    private const CACHE_PREFIX = 'taipb_figma_';

    /**
     * Bumped whenever the PROJECTION's shape changes, because the cache key is otherwise blind to
     * it.
     *
     * Caught the hard way: adding `image_refs` to each band left an hour of cached readings in the
     * old shape, so an asset import found no refs to import and reported success having done
     * nothing. The file had not changed, so nothing about the key changed either -- and "the data
     * is fine, the reader is new" is exactly the case a content-addressed key cannot see. A deploy
     * would have done the same to a real operator.
     *
     * 3: FG5's `chrome` hint per section, plus the page origin `bands()` now returns so offsets
     * can be compared across frames. Same trap as `image_refs`, caught before shipping this time --
     * a cached v2 reading has no `chrome` key at all, and a panel reading it would show every band
     * as unlabelled while reporting a successful inspection.
     */
    private const SHAPE_VERSION = 3;

    /**
     * Candidate page designs in the linked node, each with the plan it would produce.
     *
     * @return array{
     *     file: string,
     *     node: string,
     *     source: string,
     *     depth: int,
     *     skipped: int,
     *     truncated: int,
     *     candidates: array<int, array<string, mixed>>,
     *     cached: bool
     * }|\WP_Error
     */
    public static function inspect(
        FigmaUrl $ref,
        int $depth = self::DEFAULT_DEPTH,
        ?FigmaClient $client = null,
        bool $refresh = false
    ) {
        $depth = max(0, min(self::MAX_DEPTH, $depth));
        $client = $client ?? new FigmaClient();
        $cache_key = self::cache_key($ref, $depth);

        if (!$refresh) {
            $cached = get_transient($cache_key);

            if (is_array($cached)) {
                // Flagged, not disguised. An operator looking at a design that does not match what
                // they see in Figma needs to be able to tell "this is an hour old" from "the
                // extractor misread it", and those two look identical without this.
                $cached['cached'] = true;

                return $cached;
            }
        }

        $payload = $client->fetch_node($ref);

        if (is_wp_error($payload)) {
            // Failures are NOT cached. A 429 in particular must not be: the operator may fix it
            // with a different token, and a cached refusal would outlive the cause.
            return $payload;
        }

        $found = FigmaStructure::pages($payload, $depth);
        $candidates = [];

        $read = [];

        foreach ($found['pages'] as $page) {
            $bands = FigmaStructure::bands($payload, $page['id']);

            if (is_wp_error($bands)) {
                // `pages()` just told us this id exists, so this is unreachable in practice. Skip
                // rather than fail the whole inspection: one unreadable candidate must not cost the
                // operator the other thirty-seven.
                continue;
            }

            $read[] = ['page' => $page, 'bands' => $bands];
        }

        /*
         * FG5: chrome hints need EVERY frame, because repetition across frames is the signal and a
         * single frame cannot show repetition. Computed once here, from the frames already read in
         * the loop above -- this costs no additional Figma call, which is what made repetition the
         * affordable signal rather than a second fetch.
         */
        $chrome = ChromeHints::label(array_column($read, 'bands'));

        foreach ($read as $entry) {
            $candidates[] = $entry['page'] + [
                'decomposition' => FigmaDecomposer::from_page(
                    $entry['bands'],
                    $ref->file_key(),
                    $chrome
                ),
            ];
        }

        $result = [
            'file' => $ref->file_key(),
            'node' => $ref->node_id(),
            'source' => $found['source'],
            // Echoed back because it was clamped, and because a caller that asked for 9 and got 4
            // should be able to see that rather than wonder why the list looks shallow.
            'depth' => $depth,
            'skipped' => $found['skipped'],
            'truncated' => $found['truncated'],
            'candidates' => $candidates,
            'cached' => false,
        ];

        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Cache identity: the file, the frame and how deep we looked.
     *
     * Deliberately NOT keyed by token. The projection describes the file's contents, which do not
     * depend on whose token read them -- keying by token would make a second reviewer, or the same
     * person after a token rotation, pay the cost-based limit again for a byte-identical answer.
     * Hashed because a file key plus a node id plus a prefix can exceed the 172-character limit on
     * an option name, and a silently truncated key collides.
     */
    private static function cache_key(FigmaUrl $ref, int $depth): string
    {
        return self::CACHE_PREFIX . md5(implode('|', [
            $ref->file_key(),
            $ref->node_id(),
            (string) $depth,
            // The shape of what we store, not just the identity of what we read.
            'v' . self::SHAPE_VERSION,
        ]));
    }
}
