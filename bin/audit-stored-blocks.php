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

$valueIssues = [];      // "block|field|message" => count
$companionIssues = [];  // "block|field" => [stored, expected, count]
$examples = [];
$stats = ['posts' => 0, 'blocks' => 0, 'values' => 0, 'companions' => 0, 'no_schema' => 0];

$contents = $wpdb->get_col(
    "SELECT post_content FROM {$wpdb->posts} WHERE post_content LIKE '%<!-- wp:acf/%'"
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

            if ($schema === null || !isset($schema['properties'][$field])) {
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
printf("Block instances with no resolvable schema: %d\n\n", $stats['no_schema']);

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

printf("\n== COMPANION KEYS THAT DISAGREE WITH THE MAP (each one is a KEY bug) ==\n");
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
