<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * On-demand lookup for the half of the vocabulary too large to inline.
 *
 * The same division as the block catalogue: enumerate cheaply, fetch detail for what is actually
 * chosen. 15 colours and 33 icons are named in the prompt because a model cannot choose from a
 * count; **14,252 utility class names are roughly 43,000 tokens** and cannot be.
 *
 * Charged against the same {@see PromptBudget} as block context, because they compete for the same
 * prompt, and a family that cannot be afforded is declared unavailable by name rather than silently
 * omitted -- the same rule as a withheld block, for the same reason.
 */
class DesignTokenTool
{
    public const NAME = 'get_utility_classes';

    private DesignVocabulary $vocabulary;

    private PromptBudget $budget;

    /** @var array<int, string> families the model has been given classes for */
    private array $served = [];

    public function __construct(PromptBudget $budget, ?DesignVocabulary $vocabulary = null)
    {
        $this->budget = $budget;
        $this->vocabulary = $vocabulary ?? new DesignVocabulary();
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' =>
                'List the exact utility class names in a family, e.g. "mt" for top margin, "bg" '
                . 'for background colour, "d" for display. Use this only when no field on the '
                . 'block expresses what the design needs — a field is design-system styling, a '
                . 'utility class is an override. Colours, spacing steps, font sizes and icons are '
                . 'already listed in full in the prompt and do NOT need this call.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'family' => [
                        'type' => 'string',
                        'description' => 'A prefix such as `mt`, `px`, `bg`, `text`, `d`, `flex`.',
                    ],
                ],
                'required' => ['family'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function dispatch(array $input): string
    {
        $family = trim((string) ($input['family'] ?? ''));

        if ('' === $family) {
            return 'The `family` argument is required — a prefix such as `mt`, `bg` or `d`.';
        }

        $result = $this->vocabulary->utilities($family);

        if ($result['classes'] === []) {
            // Named, not silent. "No such family" is actionable; an empty list reads as "this
            // family has no classes", which would send the model looking for a different prefix
            // when the real answer may be that it should use a field instead.
            return sprintf(
                'No utility class matches `%s`. There are %d in total; check the families listed '
                . 'in the prompt, or use a field on the block instead.',
                $family,
                $result['total']
            );
        }

        $body = sprintf(
            "%d of %d utility classes match `%s`%s:\n%s",
            $result['matched'],
            $result['total'],
            $family,
            $result['matched'] > count($result['classes'])
                ? sprintf(' (first %d shown)', count($result['classes']))
                : '',
            implode(', ', $result['classes'])
        );

        if (!$this->budget->admit('utilities:' . $family, $body)) {
            return 'That family does not fit the remaining token budget. Use a field on the block '
                . 'instead, or report what is missing.';
        }

        $this->served[] = $family;

        return $body;
    }

    /** @return array<int, string> */
    public function served(): array
    {
        return $this->served;
    }
}
