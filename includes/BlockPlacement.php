<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress's three placement primitives, in one place.
 *
 * **Why this class exists.** The same three rules were implemented twice: once in
 * {@see MarkupValidator::validate_placement()}, which turns a violation into an operator-facing
 * message, and once in `RestController::may_sit_here()`, which turned the same violation into a
 * boolean so `/blocks/placeable` could filter a dropdown. Both copies were correct and both were
 * tested -- `BlockPlacementTest` covered one, `PlaceableBlocksTest` the other -- which is precisely
 * why the duplication was invisible: two suites passing against two copies.
 *
 * The endpoint's own docblock claimed "the panel and the validator cannot disagree". That was a
 * statement of intent, not a property of the code; nothing made it true. This class makes it true.
 * It is the fifth instance of this shape in this subsystem (the fence-stripper, the editor error
 * map, the block scorer and the catalogue were the others), and `capture.js` carries the same note
 * about its ID patterns being duplicated in `generated-ids.js` -- "if these patterns change, change
 * both" -- citing the three near-identical CSS minifiers as precedent. The drift is invisible: the
 * suite passes while production accepts what it rejects.
 *
 * **All three primitives are positive.** WordPress has no "may not sit here" rule. `parent` and
 * `ancestor` are declared by the CHILD and say where it may go; `allowedBlocks` is declared by the
 * CONTAINER and says what it accepts. An absent or empty rule therefore means "no restriction", not
 * "nothing allowed" -- inverting that would reject every unrestricted block in the catalogue.
 */
final class BlockPlacement
{
    /** Declared by the child: it must sit DIRECTLY inside one of these. */
    public const PARENT = 'parent';

    /** Declared by the child: it must sit SOMEWHERE beneath one of these, at any depth. */
    public const ANCESTOR = 'ancestor';

    /** Declared by the container: the whitelist of what it accepts. */
    public const ALLOWED_BLOCKS = 'allowed_blocks';

    /**
     * Every placement rule this position violates.
     *
     * Returns structure rather than a message so both callers can use it: the validator formats
     * each violation for an operator, the endpoint only asks whether the list is empty. Returning
     * a pre-formatted string would have forced the endpoint to parse prose, and returning a bare
     * boolean would have forced the validator to re-derive which rule failed -- either way the
     * logic splits again.
     *
     * @param string               $name   the block being placed, canonical (`acf/card`, `core/heading`)
     * @param string               $parent the immediate parent, `''` at the top level
     * @param list<string>         $stack  open ancestors, outermost first, NOT including `$name`
     * @param array<string, mixed> $blocks the manifest's block map
     *
     * The `rule` key is typed as the three literals rather than `string` deliberately: callers
     * `match` on it, and a widened type makes that match non-exhaustive -- which is an
     * `UnhandledMatchError` fatal inside the validator the moment a fourth rule is added without
     * handling it. Narrow here, and adding one fails static analysis at every call site instead.
     *
     * @return list<array{rule: self::PARENT|self::ANCESTOR|self::ALLOWED_BLOCKS, allowed: list<string>}>
     *         empty when the placement is legal
     */
    public static function violations(string $name, string $parent, array $stack, array $blocks): array
    {
        $violations = [];

        $allowed_parents = self::rule($blocks[$name][self::PARENT] ?? null);

        if ([] !== $allowed_parents && !in_array($parent, $allowed_parents, true)) {
            $violations[] = ['rule' => self::PARENT, 'allowed' => $allowed_parents];
        }

        // Checked against the WHOLE stack, not the immediate parent -- otherwise a block
        // legitimately nested two levels beneath its required ancestor reads as misplaced.
        $required_ancestors = self::rule($blocks[$name][self::ANCESTOR] ?? null);

        if ([] !== $required_ancestors && [] === array_intersect($stack, $required_ancestors)) {
            $violations[] = ['rule' => self::ANCESTOR, 'allowed' => $required_ancestors];
        }

        // The one rule owned by the parent rather than the child, so it is read from the PARENT's
        // entry. At the top level there is no container, hence no whitelist to satisfy.
        $parent_allows = '' !== $parent
            ? self::rule($blocks[$parent][self::ALLOWED_BLOCKS] ?? null)
            : [];

        if ([] !== $parent_allows && !in_array($name, $parent_allows, true)) {
            $violations[] = ['rule' => self::ALLOWED_BLOCKS, 'allowed' => $parent_allows];
        }

        return $violations;
    }

    /**
     * Whether this position is legal -- the boolean half, for callers that only filter.
     *
     * @param list<string>         $stack
     * @param array<string, mixed> $blocks
     */
    public static function allows(string $name, string $parent, array $stack, array $blocks): bool
    {
        return [] === self::violations($name, $parent, $stack, $blocks);
    }

    /**
     * A declared rule as a list of block names, or `[]` when there is no restriction.
     *
     * `block.json` permits a bare string as well as an array, and the manifest carries whatever was
     * declared. Normalising here keeps the three checks above identical in shape.
     *
     * @return list<string>
     */
    private static function rule(mixed $declared): array
    {
        if (is_string($declared) && '' !== $declared) {
            return [$declared];
        }

        if (!is_array($declared)) {
            return [];
        }

        return array_values(array_filter($declared, static fn($v): bool => is_string($v) && '' !== $v));
    }
}
