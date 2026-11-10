<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turn a repeater written as a JSON array into the flat rows ACF actually stores.
 *
 * ACF stores a repeater as a **row count** on the parent plus one key per cell,
 * `{field}_{index}_{subfield}`. A model asked for rows reaches for the obvious shape instead:
 *
 *     "col_width": [{"col_width_breakpoint": "lg", "col_width_width": "10"}]
 *
 * which ACF cannot read at all. The prompt warns against it and the schema rejects it, and both
 * of those were already true when a real generation produced exactly this and it reached a saved
 * draft (post 7100). A rejected block is better than a silently broken one, but a CORRECTED block
 * is better than both -- the shape is unambiguous, so converting it is strictly preferable to
 * failing and burning a retry.
 *
 * **Why this is its own class.** `MarkupGenerator::post_process_markup()` and
 * `MarkupAssembler::build_data_object()` are two separate write paths that both produce stored
 * block data, and neither handled this. Fixing it in one would have left the other broken and
 * made the two harder to reason about; fixing it in both by copying would guarantee they drift.
 */
class RepeaterRows
{
    /** How deep a nested repeater may go before this stops trying. */
    private const MAX_DEPTH = 3;

    /**
     * Rewrite every array-shaped repeater value in `$data` into indexed row keys.
     *
     * Companions are NOT written here. The callers already have a step that writes a companion
     * for every indexed row key, and it runs after this -- leaving it there keeps one owner for
     * key assignment rather than two.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $type_map field name => ACF type
     * @return array<string, mixed>
     */
    public static function flatten(array $data, array $type_map, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return $data;
        }

        foreach ($data as $field => $value) {
            $field = (string) $field;

            if (!is_array($value) || str_starts_with($field, '_')) {
                continue;
            }

            $type = $type_map[$field] ?? '';

            if ($type !== 'repeater' && $type !== 'flexible_content') {
                continue;
            }

            // A list of row objects is the shape being corrected. An associative array is
            // something else -- a single nested group, say -- and guessing at it would be worse
            // than leaving it for the validator to report.
            if (!array_is_list($value)) {
                continue;
            }

            $rows = [];

            foreach ($value as $i => $row) {
                if (!is_array($row)) {
                    // A list of scalars is not a repeater shape this can interpret.
                    continue 2;
                }

                foreach ($row as $sub => $sub_value) {
                    $rows[$field . '_' . $i . '_' . self::sub_name($field, (string) $sub)] = $sub_value;
                }
            }

            // The parent carries the ROW COUNT. This is the part that makes ACF read the rest.
            $data[$field] = count($value);

            foreach ($rows as $k => $v) {
                // Never clobber a key the caller already set: an explicit value wins over one
                // derived from the array form.
                if (!array_key_exists($k, $data)) {
                    $data[$k] = $v;
                }
            }
        }

        return $data;
    }

    /**
     * The sub-field name as it appears in a stored row key.
     *
     * Models write row keys both ways -- fully prefixed (`col_width_breakpoint`, which is the
     * flattened name they were shown) and bare (`breakpoint`). Both have to land on
     * `col_width_0_breakpoint`, so a prefix that is already there is stripped rather than
     * doubled into `col_width_0_col_width_breakpoint`.
     */
    private static function sub_name(string $field, string $sub): string
    {
        $prefix = $field . '_';

        return str_starts_with($sub, $prefix) ? substr($sub, strlen($prefix)) : $sub;
    }
}
