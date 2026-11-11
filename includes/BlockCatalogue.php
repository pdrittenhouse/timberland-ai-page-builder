<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The list of blocks a tool-mode prompt offers, as text.
 *
 * **Built from the MANIFEST, never from the curated index.** The curated index documents what a
 * block is *for*, which is the most useful thing to say about one -- but it is editorial, and it
 * lags. Measured on the real install it covers **41 of 48 blocks, and the seven it omits are
 * `button`, `card`, `hero-unit`, `image`, `jumbotron`, `menu` and `posts-loop`** -- the content
 * blocks a page is mostly made of. A catalogue built from the index therefore cannot offer a card
 * or a hero, while telling the model these are the only blocks that exist. Both prompts did exactly
 * that.
 *
 * So the manifest decides membership and the curated text decorates it: curated `best_for` where it
 * exists, the manifest description as the fallback, and the bare slug as the last resort. A named
 * block with no description is still choosable; an omitted one is not.
 *
 * Shared by `PromptBuilder` (direct mode) and `StructurePromptBuilder` (multi-step) because the
 * previous arrangement -- each with its own copy -- is how this subsystem acquired the same defect
 * in two places four times over: a fence-stripper, an error map, a block scorer, and this.
 */
class BlockCatalogue
{
    /**
     * @param array<string, array<string, mixed>>                        $blocks  the manifest's blocks
     * @param array<string, array{best_for?: string, avoid_when?: string}> $curated keyed by bare or prefixed slug
     */
    public static function lines(array $blocks, array $curated = []): string
    {
        $out = '';

        foreach ($blocks as $slug => $block) {
            $bare = str_replace('acf/', '', (string) $slug);
            $guide = $curated[$bare] ?? $curated[$slug] ?? [];

            // The FULL slug, always. The index is keyed by bare name and one caller printed those
            // verbatim, so the catalogue named `accordion` for a block the model must emit as
            // `acf/accordion` -- an invitation to invent a name, in the one document whose job is
            // to stop that.
            $out .= "- `{$slug}`";
            $out .= ($block['is_container'] ?? false) ? ' (container)' : ' (leaf)';

            $best_for = trim((string) ($guide['best_for'] ?? ''));
            $description = trim((string) ($block['description'] ?? ''));

            if ('' !== $best_for) {
                $out .= " — {$best_for}";
            } elseif ('' !== $description) {
                $out .= " — {$description}";
            }

            if ('' !== trim((string) ($guide['avoid_when'] ?? ''))) {
                $out .= ' AVOID: ' . trim((string) $guide['avoid_when']);
            }

            // Placement decides whether a block is a candidate at all, so it belongs beside the
            // name rather than in field detail the model may never ask for.
            if (!empty($block['parent'])) {
                $out .= ' [only inside ' . implode(', ', (array) $block['parent']) . ']';
            }

            $out .= "\n";
        }

        return $out;
    }
}
