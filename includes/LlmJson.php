<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pull a JSON document out of a model's reply.
 *
 * **Why this is not just `json_decode`.** Two copies of a fence-stripper lived in this codebase --
 * one in `RestController::parse_block_tree()`, one in `PromptDecomposer::parse_decomposition()` --
 * and both only stripped a fence anchored at the very START of the reply:
 *
 *     preg_replace('/^```(?:json)?\s*\n?/i', '', trim($raw))
 *
 * So a reply that opened with one courteous sentence -- "Here is the block tree:" -- decoded to
 * null, and the caller reported "the LLM returned an invalid block tree, please try again". The
 * model had returned a perfectly good tree. The failure was intermittent, because whether a
 * preamble appears is a sampling matter, which made it look like model flakiness rather than a
 * parser that could not see past the first character.
 *
 * **It is deliberately permissive about the wrapper and strict about the content.** Anything that
 * is not valid JSON still fails; the only thing being forgiven is prose and fences around it.
 */
class LlmJson
{
    /**
     * @return array<mixed>|null null when nothing decodable is present
     */
    public static function decode(string $raw): ?array
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return null;
        }

        foreach (self::candidates($raw) as $candidate) {
            $parsed = json_decode($candidate, true);

            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Progressively less literal readings of the reply, best first.
     *
     * @return array<int, string>
     */
    private static function candidates(string $raw): array
    {
        $candidates = [$raw];

        // A fenced block ANYWHERE, not just at position 0 -- the whole point of this class. The
        // last fence wins when there are several, because a reply that reasons in a first block and
        // answers in a second puts the answer last.
        if (preg_match_all('/```(?:json)?\s*\n?(.*?)```/is', $raw, $m)) {
            foreach (array_reverse($m[1]) as $fenced) {
                $candidates[] = trim($fenced);
            }
        }

        // An unterminated fence: the reply was cut off mid-document. Decoding still fails, but
        // trying costs nothing and a cap raised later makes this path succeed.
        if (preg_match('/```(?:json)?\s*\n?(.*)$/is', $raw, $m)) {
            $candidates[] = trim($m[1]);
        }

        // Last resort: the outermost braces or brackets. Handles a bare preamble with no fence at
        // all. Not a parser -- `json_decode` is still the judge of whether the slice is valid.
        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $start = strpos($raw, $open);
            $end = strrpos($raw, $close);

            if (false !== $start && false !== $end && $end > $start) {
                $candidates[] = substr($raw, $start, $end - $start + 1);
            }
        }

        return $candidates;
    }
}
