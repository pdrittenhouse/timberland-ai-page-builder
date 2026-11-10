<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How a generation reports that the library cannot express part of the request.
 *
 * **Why a tool and not prose.** A gap has to be a structured result alongside the composition,
 * not something a reader infers from the model's wording. Parsing "I couldn't find a block for
 * X" out of free text is a guess that fails silently in both directions. A tool call is the
 * model stating it, in fields, and it costs nothing when unused.
 *
 * **Why it is not an error.** The generation still returns the markup it could produce. The bad
 * outcome this exists to prevent is the opposite one: the model picks the nearest block, the page
 * renders, nothing validates as wrong, and the operator concludes the product is low quality
 * rather than that their request was out of scope. A gap reported next to a partial page is
 * useful; a page that silently approximates is not.
 *
 * **Why it is also a product input.** The accumulated log answers "which component should we
 * build next" with evidence rather than opinion -- which is the half of this that pays for
 * itself, since the safety half ideally never fires.
 */
class GapTool
{
    public const NAME = 'report_gap';

    /** @var array<int, array{need: string, why: string, closest: string}> */
    private array $gaps = [];

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' =>
                'Report that part of the request cannot be expressed by any available block. '
                . 'Call this INSTEAD of substituting the nearest block. You may still produce '
                . 'markup for the parts that are expressible -- reporting a gap is not giving up, '
                . 'and it is never wrong to report one.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'need' => [
                        'type' => 'string',
                        'description' => 'What the request asked for, in the requester\'s terms.',
                    ],
                    'why' => [
                        'type' => 'string',
                        'description' => 'Why no available block expresses it.',
                    ],
                    'closest' => [
                        'type' => 'string',
                        'description' => 'The nearest block slug, if any, and why it is not adequate. Optional.',
                    ],
                ],
                'required' => ['need', 'why'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function dispatch(array $input): string
    {
        $need = trim((string) ($input['need'] ?? ''));
        $why = trim((string) ($input['why'] ?? ''));

        if ($need === '' || $why === '') {
            return 'Both `need` and `why` are required. Say what was asked for and why no block expresses it.';
        }

        $this->gaps[] = [
            'need' => $need,
            'why' => $why,
            'closest' => trim((string) ($input['closest'] ?? '')),
        ];

        return 'Gap recorded. Continue and produce markup for the parts of the request that ARE '
            . 'expressible, leaving out the part you just reported.';
    }

    /** @return array<int, array{need: string, why: string, closest: string}> */
    public function gaps(): array
    {
        return $this->gaps;
    }

    public function has_gaps(): bool
    {
        return $this->gaps !== [];
    }
}
