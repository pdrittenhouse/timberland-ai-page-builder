<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The tool a generation uses to fetch block field detail on demand.
 *
 * **The problem it replaces.** The prompt used to guess which blocks a request needed by keyword
 * and inline every field of each. Measured on "Add a hero with a headline": 9 blocks, 4,194
 * tokens, 69% of the prompt -- and 2,471 of those tokens were blocks the request could not use
 * (`promo`, `jumbotron`, `table-head`, `table-cell`). The model now asks for what it needs, so
 * nothing is spent on a guess.
 *
 * **Plural on purpose.** `slugs` is an array so one call covers a whole page. The round-trip count
 * was the main cost of letting the model fetch, and batching removes it: a seven-block page is one
 * fetch turn, not seven.
 *
 * **Withholding is reported, never silent.** When the budget cannot afford a block's fields, the
 * result says so BY NAME and the block is removed from what the model may use. Leaving it in play
 * with no field list is the exact failure that produced `jumbotron_container` and
 * `jumbotron_fluid` -- field names invented for a block the model had never been shown. A model
 * told "this block is unavailable" can report a gap; a model shown nothing invents.
 */
class BlockContextTool
{
    public const NAME = 'get_block_fields';

    /** @var array<string, mixed> */
    private array $manifest;

    private PromptBudget $budget;

    private PromptBuilder $prompt_builder;

    /** @var array<int, string> Blocks the model asked for and was given. */
    private array $served = [];

    /** @var array<int, string> Blocks the model asked for that do not exist. */
    private array $unknown = [];

    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(array $manifest, PromptBudget $budget, PromptBuilder $prompt_builder)
    {
        $this->manifest = $manifest;
        $this->budget = $budget;
        $this->prompt_builder = $prompt_builder;
    }

    /**
     * The provider-neutral tool definition.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' =>
                'Get the exact field names and accepted values for one or more blocks. '
                . 'Call this ONCE with every block you intend to use, before writing any markup. '
                . 'You may only write fields this tool has returned for a block -- a field name '
                . 'that looks plausible but was not returned will be rejected.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slugs' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Block slugs from the catalog, with or without the `acf/` prefix.',
                    ],
                ],
                'required' => ['slugs'],
            ],
        ];
    }

    /**
     * Answer a tool call.
     *
     * Never throws. An unknown tool name or a bad argument comes back as text the model can act
     * on, because a model that can see its mistake corrects it on the next turn, while an
     * exception ends the generation.
     *
     * @param array<string, mixed> $input
     */
    public function dispatch(string $name, array $input): string
    {
        if ($name !== self::NAME) {
            return "Unknown tool `{$name}`. The only available tool is `" . self::NAME . '`.';
        }

        $slugs = $input['slugs'] ?? null;

        if (!is_array($slugs) || $slugs === []) {
            return 'The `slugs` argument is required and must be a non-empty array of block slugs.';
        }

        $sections = [];

        foreach ($slugs as $slug) {
            $block_name = $this->normalise_slug((string) $slug);

            if ($block_name === null) {
                $this->unknown[] = (string) $slug;
                $sections[] = "### `{$slug}` — NOT A BLOCK ON THIS SITE. Do not use it.";
                continue;
            }

            if (in_array($block_name, $this->served, true)) {
                continue;
            }

            $section = $this->prompt_builder->describe_block($block_name);

            if ($section === '') {
                $this->unknown[] = $block_name;
                $sections[] = "### `{$block_name}` — no field detail available. Do not use it.";
                continue;
            }

            if (!$this->budget->admit($block_name, $section)) {
                $sections[] =
                    "### `{$block_name}` — UNAVAILABLE: the token budget for this generation is "
                    . "exhausted, so this block's fields cannot be sent. Do NOT write this block. "
                    . 'If the request needs it, say so in your response instead of guessing its fields.';
                continue;
            }

            $this->served[] = $block_name;
            $sections[] = $section;
        }

        return implode("\n\n", $sections);
    }

    /** @return array<int, string> */
    public function served(): array
    {
        return $this->served;
    }

    /** @return array<int, string> */
    public function unknown(): array
    {
        return $this->unknown;
    }

    /**
     * Blocks the budget refused, by name -- the input to a gap report.
     *
     * @return array<int, string>
     */
    public function withheld(): array
    {
        $names = [];

        foreach ($this->budget->report()['withheld'] as $item) {
            $names[] = (string) $item['what'];
        }

        return $names;
    }

    /**
     * Accept `hero-unit`, `acf/hero-unit` and `Hero Unit`-ish input alike.
     *
     * The catalog the model is shown lists bare slugs, but a model that has seen block markup
     * tends to echo the `acf/` form back. Rejecting one spelling of a real block would send it
     * looking for a different block.
     */
    private function normalise_slug(string $slug): ?string
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        $candidates = str_starts_with($slug, 'acf/')
            ? [$slug]
            : ['acf/' . $slug, $slug];

        foreach ($candidates as $candidate) {
            if (isset($this->manifest['blocks'][$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
