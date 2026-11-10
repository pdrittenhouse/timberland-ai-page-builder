<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How a generation explores a Figma file: list the candidate pages, then fetch one.
 *
 * **The same division as the block catalogue.** Enumerate mechanically, choose semantically. The
 * server cannot decide what a page design is -- names can be `Frame 19` twice and geometry cannot
 * separate a 683x582 design from a 683x582 mood board -- so it surfaces candidates with their
 * evidence and the model picks, exactly as it picks blocks from the catalogue and then calls
 * `get_block_fields`.
 *
 * **One fetch, reused.** The payload for a single canvas is 3.9 MB and the model will call `list`
 * then `get` at least once each; fetching twice would double a slow, rate-limited, metered call
 * for no gain. The response is held for the life of the tool.
 *
 * Both results are charged against the same {@see PromptBudget} as block context, because they
 * compete for the same prompt. A page that cannot be afforded is declared unavailable by name
 * rather than silently omitted -- the same rule as a withheld block, for the same reason.
 */
class FigmaContextTool
{
    public const LIST_NAME = 'list_figma_pages';
    public const GET_NAME = 'get_figma_page';

    private FigmaClient $client;

    private FigmaUrl $ref;

    private PromptBudget $budget;

    /** @var array<string, mixed>|null the fetched payload, or null before the first fetch */
    private ?array $payload = null;

    private ?string $fetch_error = null;

    /** @var array<int, string> page ids the model has been given bands for */
    private array $served = [];

    public function __construct(FigmaUrl $ref, PromptBudget $budget, ?FigmaClient $client = null)
    {
        $this->ref = $ref;
        $this->budget = $budget;
        $this->client = $client ?? new FigmaClient();
    }

    /**
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => self::LIST_NAME,
                'description' =>
                    'List the candidate page designs in the linked Figma file, with each one\'s '
                    . 'dimensions, band count and most prominent text. Call this FIRST. The list is '
                    . 'not filtered to real pages -- it includes wrappers and anything large enough '
                    . 'to be a design -- so choose using the evidence. If none of them looks like a '
                    . 'page design, say so rather than picking the largest.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => self::GET_NAME,
                'description' =>
                    'Get one page\'s ordered bands: for each, its type, dimensions and the text it '
                    . 'contains with font sizes. Band order is top-to-bottom. Use the font sizes to '
                    . 'tell a heading from body copy and from a link list.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_id' => [
                            'type' => 'string',
                            'description' => 'An `id` from ' . self::LIST_NAME . '.',
                        ],
                    ],
                    'required' => ['page_id'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function dispatch(string $name, array $input): string
    {
        if ($name === self::LIST_NAME) {
            return $this->list_pages();
        }

        if ($name === self::GET_NAME) {
            return $this->get_page(trim((string) ($input['page_id'] ?? '')));
        }

        return "Unknown tool `{$name}`.";
    }

    private function list_pages(): string
    {
        $payload = $this->fetch();

        if ($payload === null) {
            return (string) $this->fetch_error;
        }

        $found = FigmaStructure::pages($payload);

        if ($found['pages'] === []) {
            return 'That Figma node contains nothing large enough to be a page design. '
                . $found['skipped'] . ' smaller items were skipped. Report this rather than guessing.';
        }

        $lines = [sprintf(
            '%d candidate(s) in a %s. %d small or decorative item(s) skipped%s.',
            count($found['pages']),
            $found['source'] !== '' ? strtolower($found['source']) : 'node',
            $found['skipped'],
            $found['truncated'] > 0 ? ", {$found['truncated']} further candidate(s) not listed" : ''
        )];

        foreach ($found['pages'] as $page) {
            $sample = [];

            foreach ($page['sample'] as $text) {
                $sample[] = $text['size'] . 'px "' . $text['text'] . '"';
            }

            $lines[] = sprintf(
                '- id=%s  %s  %dx%d  bands=%d  depth=%d%s  %s',
                $page['id'],
                $page['name'] !== '' ? '"' . $page['name'] . '"' : '(no meaningful name)',
                $page['width'],
                $page['height'],
                $page['bands'],
                $page['depth'],
                $page['parent'] !== '' ? ' in "' . $page['parent'] . '"' : '',
                $sample !== [] ? implode(' | ', $sample) : '(no text)'
            );
        }

        $body = implode("\n", $lines);

        if (!$this->budget->admit('figma:page-list', $body)) {
            return 'The candidate list does not fit the remaining token budget. Ask for a specific '
                . 'page by id if you know one, or report that the file could not be read within budget.';
        }

        return $body;
    }

    private function get_page(string $page_id): string
    {
        if ($page_id === '') {
            return 'The `page_id` argument is required. Call ' . self::LIST_NAME . ' first.';
        }

        $payload = $this->fetch();

        if ($payload === null) {
            return (string) $this->fetch_error;
        }

        $result = FigmaStructure::bands($payload, $page_id);

        if (is_wp_error($result)) {
            return 'No such page id in this file. Use an `id` from ' . self::LIST_NAME . '.';
        }

        $lines = [sprintf(
            'Page %s (%dx%d), %d band(s), top to bottom:',
            $result['page'] !== '' ? '"' . $result['page'] . '"' : '(unnamed)',
            $result['width'],
            $result['height'],
            count($result['bands'])
        )];

        foreach ($result['bands'] as $i => $band) {
            $texts = [];

            foreach ($band['texts'] as $text) {
                $texts[] = $text['size'] . 'px "' . $text['text'] . '"';
            }

            $lines[] = sprintf(
                '%d. %s %dx%d%s — %s',
                $i + 1,
                strtolower($band['type']),
                $band['width'],
                $band['height'],
                $band['name'] !== '' ? ' "' . $band['name'] . '"' : '',
                $texts !== [] ? implode(' | ', $texts) : '(no text — likely an image or decorative band)'
            );
        }

        $body = implode("\n", $lines);

        if (!$this->budget->admit('figma:' . $page_id, $body)) {
            return 'This page does not fit the remaining token budget. Build what you can from the '
                . 'pages already fetched, and report the rest as not assessed.';
        }

        $this->served[] = $page_id;

        return $body;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(): ?array
    {
        if ($this->payload !== null || $this->fetch_error !== null) {
            return $this->payload;
        }

        $result = $this->client->fetch_node($this->ref);

        if (is_wp_error($result)) {
            // Redacted, and phrased so the model can act: a dead token is the most likely cause on
            // a short-lived PAT, and "ask the operator" is a better move than retrying.
            $this->fetch_error = 'Could not read the Figma file: '
                . Redact::credentials($result->get_error_message())
                . ' The access token may have expired. Do not retry; report this.';

            return null;
        }

        $this->payload = $result;

        return $this->payload;
    }

    /** @return array<int, string> */
    public function served(): array
    {
        return $this->served;
    }
}
