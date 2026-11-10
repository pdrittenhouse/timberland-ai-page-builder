<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The token ceiling for prompt context, and the accumulator that keeps spending under it.
 *
 * **Two different numbers, deliberately separated.** The *budget* is the cost model's target
 * (3,000-8,000 input tokens for component context) and is soft: crossing it degrades what the
 * model is shown. The *limit* is the model's context window, is physical, and is not something
 * this class pretends to manage. Nothing here ever refuses to generate -- a page too large for
 * the budget still generates, with less field detail and a report saying so.
 *
 * That distinction is the whole reason the override exists. Enforcement that could block a
 * legitimate page would be worse than the drift it prevents.
 *
 * **Resolution order** mirrors the framework's own convention for `options_enable_full_page_cache`
 * (code default -> option -> constant/filter), and the plugin's existing `TAIPB_API_KEY` pattern:
 *
 *   1. {@see self::DEFAULT_CEILING} -- the shipped default, 8,000.
 *   2. `taipb_settings['token_budget']` -- operator override in Settings. 0 means "use the default".
 *   3. `TAIPB_TOKEN_BUDGET` -- constant, for wp-config and per-environment control.
 *   4. `taipb_token_budget` filter -- programmatic, and the only layer that sees the request
 *      context, so a single oversized page can be allowed through without changing a setting.
 *
 * A resolved ceiling of 0 or less means **unlimited**: no degradation, and the report says the
 * budget was not enforced rather than silently claiming it was respected.
 */
class PromptBudget
{
    /** The top of the cost model's allowance for component context. */
    public const DEFAULT_CEILING = 8000;

    /** Cheap, stable, and the same estimator the framework's own manifest tooling uses. */
    public const CHARS_PER_TOKEN = 4;

    private int $ceiling;

    private int $spent = 0;

    /** @var array<int, array{what: string, tokens: int}> */
    private array $admitted = [];

    /** @var array<int, array{what: string, tokens: int, reason: string}> */
    private array $withheld = [];

    /**
     * @param array<string, mixed> $context Passed to the filter so a decision can depend on the
     *                                      request -- post type, prompt, or an explicit
     *                                      `ignore_budget` from the generate screen.
     */
    public function __construct(array $context = [], ?int $ceiling = null)
    {
        $this->ceiling = $ceiling ?? self::resolve_ceiling($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function resolve_ceiling(array $context = []): int
    {
        $ceiling = self::DEFAULT_CEILING;

        $settings = Plugin::get_settings();
        $option = (int) ($settings['token_budget'] ?? 0);

        if ($option > 0) {
            $ceiling = $option;
        }

        if (defined('TAIPB_TOKEN_BUDGET')) {
            $ceiling = (int) constant('TAIPB_TOKEN_BUDGET');
        }

        // An explicit per-request escape, so one oversized page does not need a settings change.
        if (!empty($context['ignore_budget'])) {
            $ceiling = 0;
        }

        /**
         * Filter the prompt token ceiling.
         *
         * Return 0 or less for unlimited. The report will say the budget was not enforced, which
         * is the point: an unenforced budget must never look like a respected one.
         *
         * @param int                  $ceiling Tokens.
         * @param array<string, mixed> $context Request context.
         */
        return (int) apply_filters('taipb_token_budget', $ceiling, $context);
    }

    public function ceiling(): int
    {
        return $this->ceiling;
    }

    public function enforced(): bool
    {
        return $this->ceiling > 0;
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function remaining(): int
    {
        return $this->enforced() ? max(0, $this->ceiling - $this->spent) : PHP_INT_MAX;
    }

    public static function estimate(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Charge text against the budget unconditionally -- for the parts that are not optional.
     *
     * RULES, the user's own prompt and the operator's custom prose are not negotiable: they are
     * recorded as spent even when that puts the budget over, because pretending otherwise is how
     * an overage goes unnoticed.
     */
    public function charge(string $what, string $text): int
    {
        $tokens = self::estimate($text);
        $this->spent += $tokens;
        $this->admitted[] = ['what' => $what, 'tokens' => $tokens];

        return $tokens;
    }

    /**
     * Admit optional text only if it fits.
     *
     * @return bool true when admitted; false when withheld, and the caller must then leave the
     *              thing out ENTIRELY rather than include a partial version of it.
     */
    public function admit(string $what, string $text, string $reason = 'over the token ceiling'): bool
    {
        $tokens = self::estimate($text);

        if ($this->enforced() && $tokens > $this->remaining()) {
            $this->withheld[] = ['what' => $what, 'tokens' => $tokens, 'reason' => $reason];

            return false;
        }

        $this->spent += $tokens;
        $this->admitted[] = ['what' => $what, 'tokens' => $tokens];

        return true;
    }

    /**
     * What the budget did, for the generation result.
     *
     * `withheld` is the half that matters. A block whose fields could not be afforded must be
     * taken out of the model's allowed set, not left in play with no field list -- otherwise the
     * model writes field names it was never shown. Reporting it is what lets the caller do that,
     * and what lets an operator re-run with the override instead of wondering why the page is thin.
     *
     * @return array{ceiling: int, enforced: bool, spent: int, remaining: int, over: bool, admitted: array, withheld: array}
     */
    public function report(): array
    {
        return [
            'ceiling' => $this->ceiling,
            'enforced' => $this->enforced(),
            'spent' => $this->spent,
            'remaining' => $this->enforced() ? $this->remaining() : -1,
            'over' => $this->enforced() && $this->spent > $this->ceiling,
            'admitted' => $this->admitted,
            'withheld' => $this->withheld,
        ];
    }
}
