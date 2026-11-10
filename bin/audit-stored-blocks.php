<?php
/**
 * Differential audit: every ACF block the EDITOR has saved, against the schema and key map.
 *
 * Run with:
 *   wp eval-file bin/audit-stored-blocks.php
 *
 * ## Why this exists
 *
 * Five separate storage-shape bugs were found one at a time, each by a failed generation and
 * each the same mistake: the schema described what `get_field()` RETURNS rather than what ACF
 * STORES. image as `object`, repeater as `array`, url rejecting empty, true_false as `boolean`,
 * and repeater rows missing their companions.
 *
 * Reasoning type-by-type found them slowly and would never prove the list complete. The editor's
 * own saved output is the authority -- it is, by definition, what ACF accepts -- and this site
 * has 10,740 saved block instances carrying ~542,000 field values across all 48 block types.
 * Checking generated output against the schema asks "did the model guess right?"; checking SAVED
 * output against the schema asks "is the schema right?", which is the question that was actually
 * wrong five times.
 *
 * ## What it reports
 *
 * 1. VALUES whose stored form its schema rejects. Every one is a schema bug, not a data bug:
 *    the editor wrote it, so ACF accepts it.
 * 2. COMPANIONS whose stored key differs from what the field key map would supply. Every one is
 *    a key-derivation bug, and the post-processor would write the wrong key for that field.
 *
 * Both are grouped by cause and ranked by how many stored values each affects, so the biggest
 * wrong assumption is the first thing read.
 */

// No declare(strict_types=1): `wp eval-file` EVALS this file rather than including it, so a
// strict_types declaration is not the first statement in the script and PHP fatals.
use TimberlandAIPageBuilder\Plugin;

if (!class_exists(Plugin::class)) {
    fwrite(STDERR, "The plugin is not active on this install.\n");
    exit(1);
}

global $wpdb;

$manifest = Plugin::get_manifest_store()->get();
$oracle = Plugin::get_schema_oracle();

$valueIssues = [];
$orphanedKeys = [];
$removedFields = [];
$unproducible = [];      // "block|field|message" => count
$companionIssues = [];  // "block|field" => [stored, expected, count]
$examples = [];
$stats = ['posts' => 0, 'blocks' => 0, 'values' => 0, 'companions' => 0, 'no_schema' => 0];

/**
 * CURRENT content only.
 *
 * The first version of this query had no `post_type` or `post_status` filter at all, so it swept
 * revisions, trashed posts and autosaves in with live content -- and every count it produced was
 * inflated by roughly the revision depth. It reported 1,045 orphaned keys where 131 were live, and
 * 1,050 bad `alignment` values where 1 was. Both sent an investigation after content nobody can
 * see, and in one case nearly produced a rewrite of 14 posts to fix a single real value.
 *
 * A revision records what was saved. It is not wrong, it is history, and rewriting one falsifies
 * the record -- so it is reported separately below rather than mixed in or hidden.
 */
/**
 * Is this stored key a legitimate repeater ROW of some field in the map?
 *
 * A row is stored as `{field}_{index}_{subfield}` -- but a FIELD NAME may itself contain
 * `_<digits>_`: `promo_2_buttons` is a real repeater on `acf/promo`. A non-greedy
 * `^(.+?)_(\d+)_(.+)$` takes the FIRST digit group, decides `promo` is not a repeater, and reports
 * `promo_2_buttons_0_button_text` as an unmapped key. That one mistake accounted for **406 of 449**
 * reported problems -- 90% of the list was this check, not the content.
 *
 * So every `_<digits>_` position is tried, and the un-indexed form must be a field the block
 * actually has, or the match is a coincidence.
 *
 * @param array<string, string> $keyMap
 * @param array<string, string> $typeMap
 */
function tl_is_repeater_row(string $key, array $keyMap, array $typeMap): bool
{
    if (!preg_match_all('/_(\d+)_/', $key, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }

    foreach ($m[0] as $hit) {
        $match = $hit[0];
        $offset = $hit[1];
        $parent = substr($key, 0, $offset);
        $sub = substr($key, $offset + strlen($match));
        $parentType = $typeMap[$parent] ?? '';

        if ($parentType !== 'repeater' && $parentType !== 'flexible_content') {
            continue;
        }

        if (isset($keyMap[$parent . '_' . $sub])) {
            return true;
        }
    }

    return false;
}

$contents = $wpdb->get_col(
    "SELECT post_content FROM {$wpdb->posts}
     WHERE post_content LIKE '%<!-- wp:acf/%'
       AND post_type NOT IN ('revision')
       AND post_status NOT IN ('trash', 'auto-draft', 'inherit')"
);

// Counted, not scanned: enough to say how much history exists without letting it set the agenda.
$historical = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_content LIKE '%<!-- wp:acf/%'
       AND (post_type = 'revision' OR post_status IN ('trash', 'auto-draft', 'inherit'))"
);

foreach ($contents as $content) {
    $stats['posts']++;

    if (!preg_match_all('/<!-- wp:acf\/([a-z0-9-]+) (\{.*?\}) \/?-->/s', $content, $matches, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($matches as $match) {
        $name = 'acf/' . $match[1];
        $decoded = json_decode($match[2], true);
        $data = $decoded['data'] ?? null;

        if (!is_array($data) || $data === []) {
            continue;
        }

        $stats['blocks']++;

        $schema = $oracle->schema_for($name);
        $keyMap = $manifest['blocks'][$name]['field_key_map'] ?? [];
        $typeMap = $manifest['blocks'][$name]['field_type_map'] ?? [];

        if ($schema === null) {
            $stats['no_schema']++;
        }

        foreach ($data as $field => $value) {
            // --- companions: compare the STORED key against what the map would supply ---
            if (str_starts_with((string) $field, '_')) {
                $stats['companions']++;
                $target = substr((string) $field, 1);
                $expected = $keyMap[$target] ?? null;

                // Derive the row form the post-processor uses, so this audits the same logic.
                if ($expected === null && preg_match('/^(.+?)_(\d+)_(.+)$/', $target, $p)) {
                    $parentType = $typeMap[$p[1]] ?? '';
                    if ($parentType === 'repeater' || $parentType === 'flexible_content') {
                        $expected = $keyMap[$p[1] . '_' . $p[3]] ?? null;
                    }
                }

                if ($expected !== null && $expected !== $value) {
                    $k = $name . '|' . $target;
                    $companionIssues[$k] ??= ['stored' => $value, 'expected' => $expected, 'count' => 0];
                    $companionIssues[$k]['count']++;
                }

                continue;
            }

            $stats['values']++;

            if ($schema === null) {
                continue;
            }

            if (!isset($schema['properties'][$field])) {
                // A key with no matching property. Every schema is `additionalProperties: false`,
                // so this is a real violation -- but the old code skipped it silently, which is
                // why 131 stored `acf/posts-loop.alignment` keys (no companion, no property, not
                // a field on that block at all) were invisible to every previous run.
                //
                // A repeater ROW is not orphaned: it is stored as `{field}_{index}_{subfield}`
                // while the schema declares the un-indexed name. Stripping the index first is
                // what keeps this from reporting thousands of legitimate rows -- the shape of
                // false-positive flood that makes an audit worth ignoring.
                if (tl_is_repeater_row($field, $keyMap, $typeMap)) {
                    continue;
                }

                // Two honest categories, and no third one that flatters the report.
                //
                // The previous version called this "pre-rename" when the companion's key appeared
                // ANYWHERE in the map. That was wrong: a key is the cloned SOURCE field and
                // legitimately appears under many names -- `field_634651e5a85fc` is Module: Color's
                // `color`, mapped under 25 names on `acf/card` -- so the test matched almost
                // everything and the label hid real gaps behind a reassuring word. Exactly one
                // genuine rename existed (`section.margin`).
                //
                //  - the field is GONE from ACF  -> stale data, nothing can read it
                //  - the field exists           -> the map cannot produce this name, which is the
                //                                  signature of a clone-resolution gap. The
                //                                  deepest ancestor the map DOES have is the
                //                                  diagnostic: a clone/group ancestor with a
                //                                  missing child is where resolution stopped.
                $companion = $data['_' . $field] ?? null;
                $resolves = is_string($companion)
                    && str_starts_with($companion, 'field_')
                    && function_exists('acf_get_field')
                    && acf_get_field($companion) !== false
                    && acf_get_field($companion) !== null;

                if (!$resolves) {
                    $removedFields[$name . '.' . $field] = ($removedFields[$name . '.' . $field] ?? 0) + 1;
                    continue;
                }

                $parts = explode('_', $field);
                $deepest = '';

                for ($i = 1; $i < count($parts); $i++) {
                    $cand = implode('_', array_slice($parts, 0, $i));

                    if (isset($keyMap[$cand])) {
                        $deepest = $cand;
                    }
                }

                $ancestor = $deepest === ''
                    ? '(no ancestor in the map)'
                    : sprintf('%s [%s]', $deepest, $typeMap[$deepest] ?? '?');
                $unproducible[$name . ' :: ' . $ancestor][] = $field;

                continue;
            }

            // Validate this one value against its own property schema.
            $validator = new JsonSchema\Validator();
            $subject = json_decode(json_encode(['v' => $value]));
            $wrapper = json_decode(json_encode([
                'type' => 'object',
                'properties' => ['v' => $schema['properties'][$field]],
            ]));

            $validator->validate($subject, $wrapper);

            foreach ($validator->getErrors() as $error) {
                $acfType = $typeMap[$field] ?? '?';
                $k = $acfType . '|' . ($error['message'] ?? '');
                $valueIssues[$k] = ($valueIssues[$k] ?? 0) + 1;
                $examples[$k] ??= sprintf('%s.%s = %s', $name, $field, var_export($value, true));
            }
        }
    }
}

printf(
    "Audited %d posts, %d block instances, %d values, %d companions.\n",
    $stats['posts'], $stats['blocks'], $stats['values'], $stats['companions']
);
printf("Block instances with no resolvable schema: %d\n", $stats['no_schema']);
printf(
    "Excluded: %d revision/trashed/autosave row(s) -- history, not content. Every count below is live content.\n\n",
    $historical
);

arsort($valueIssues);
printf("== STORED VALUES THE SCHEMA REJECTS (each one is a SCHEMA bug) ==\n");
if ($valueIssues === []) {
    printf("  none -- every stored value validates against its own schema.\n");
}
foreach (array_slice($valueIssues, 0, 25, true) as $k => $count) {
    [$acfType, $message] = explode('|', $k, 2);
    printf("  %6d  acf-type=%-16s %s\n", $count, $acfType, substr($message, 0, 70));
    printf("          e.g. %s\n", substr($examples[$k], 0, 100));
}

arsort($removedFields);
printf(
    "\n== DATA FOR FIELDS ACF NO LONGER DEFINES (%d value(s), %d name(s)) ==\n",
    array_sum($removedFields),
    count($removedFields)
);
printf("  The field was deleted; nothing can read this. Inert, and only a data edit clears it.\n");
foreach (array_slice($removedFields, 0, 10, true) as $k => $count) {
    printf("  %6d  %s\n", $count, $k);
}
if (count($removedFields) > 10) {
    printf("  … %d more\n", count($removedFields) - 10);
}

uasort($unproducible, static fn(array $a, array $b): int => count($b) <=> count($a));
$unproducibleTotal = array_sum(array_map('count', $unproducible));
printf(
    "\n== STORED NAMES THE MAP CANNOT PRODUCE (%d value(s), %d ancestor group(s)) ==\n",
    $unproducibleTotal,
    count($unproducible)
);
printf("  The field exists, so the map is missing a path to it. The ancestor is where\n");
printf("  resolution stopped -- a clone/group ancestor with a missing child is the signature.\n");
foreach (array_slice($unproducible, 0, 12, true) as $group => $fields) {
    printf("  %6d  %s\n", count($fields), $group);
    printf("          e.g. %s\n", $fields[0]);
}

arsort($orphanedKeys);
printf("\n== STORED KEYS WITH NO SCHEMA PROPERTY (additionalProperties is false, so each is a violation) ==\n");
if ($orphanedKeys === []) {
    printf("  none -- every stored key maps to a declared property.\n");
}
foreach (array_slice($orphanedKeys, 0, 25, true) as $k => $count) {
    printf("  %6d  %s\n", $count, $k);
}

// NOT a defect class. Measured 2026-11-10 against post 11's real stored section block: the
// nested read the templates actually use -- `fields['section_border']['top']['color']`, i.e.
// `get_fields()` -- returns the same value whether the companions are composite (as stored),
// plain (as the field key map emits), BOGUS, or removed outright. A control that changed the
// VALUE did move the result, so the probe was live. ACF rebuilds the nested shape from the
// field-group structure and the flat value names, not from the `_` companions.
//
// Only the FLAT read differs (`get_field('section_border_top_color')` is NULL for the composite
// form, because a clone-composite key is not registered in ACF's field store even after the
// block's groups are prepared) -- and no template reads a clone child by its flat name.
//
// Left in the report because it is still a real difference worth seeing, and because the EDITOR
// path is NOT covered by that measurement: ACF uses `_name => key` when loading a block back
// into the admin form, so a wrong companion could still affect editing. Front-end rendering is
// unaffected either way.
printf("\n== COMPANION KEYS THAT DIFFER FROM THE MAP (informational -- not a render defect) ==\n");
uasort($companionIssues, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
if ($companionIssues === []) {
    printf("  none -- every stored companion matches what the map supplies.\n");
}
foreach (array_slice($companionIssues, 0, 15, true) as $k => $info) {
    [$block, $field] = explode('|', $k, 2);
    printf("  %6d  %s.%s\n", $info['count'], $block, $field);
    printf("          stored:   %s\n", $info['stored']);
    printf("          map says: %s\n", $info['expected']);
}
