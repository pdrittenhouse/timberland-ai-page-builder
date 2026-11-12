<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FG1's reduced-fidelity input: a screenshot, for the client who has no Figma file.
 *
 * ## What this cannot do, and why saying so matters
 *
 * A Figma URL yields structure AND pixels: frame names that are semantic hints (`Hero Band`,
 * `Logo Strip`), `absoluteBoundingBox` giving order and proportion, text sampled by font size, and
 * repeated-group detection that tells a six-up card grid from a split hero. All of it measured,
 * none of it guessed, at ~2,000:1 compression.
 *
 * A screenshot yields a vision model's impression. There are no frame names, no measured reading
 * order, no node ids -- so {@see FidelityReport}'s image signal has nothing to check against, and
 * FG3 provenance degrades to `screenshot` because there is no document to point at. The results
 * are worse, and the UI says so: an operator who is not told will attribute the difference to the
 * model and go tuning prompts.
 *
 * ## Why it still emits a decomposition
 *
 * Because the alternative is a second pipeline. `FigmaDecomposer` emits the shape that enters
 * `/decompose -> confirm -> /structure -> /assemble`, so a Figma page gets the operator review
 * step for free; a screenshot path that produced block markup directly would be the one route
 * where nobody checks the plan, and it is the route where checking matters MOST -- the operator
 * can see the picture and the model's reading of it is the thing most likely to be wrong.
 *
 * So this returns the same contract, with `figma.file` empty and `source` marking how it was
 * derived. Every consumer downstream already handles a section whose provenance is not a node id,
 * because FG3 settled that a tier which says nothing gets an honest default rather than a borrowed
 * claim.
 */
final class ScreenshotDecomposer
{
    /** Marks a decomposition as derived from pixels. Consumers use it to label fidelity. */
    public const SOURCE = 'screenshot';

    /** Providers accept these; anything else is refused before an upload is read. */
    public const ALLOWED_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Bytes, not pixels: 5 MB is Anthropic's documented per-image cap.
     *
     * Checked before the request rather than after, because the failure on the far side is a
     * provider error an operator cannot act on, and because base64 inflates by a third -- a file
     * that fits locally can still exceed the wire limit.
     */
    public const MAX_BYTES = 5242880;

    private LLMClientInterface $client;

    public function __construct(LLMClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Read a screenshot into the decomposition contract.
     *
     * @param  string $bytes     raw image bytes
     * @param  string $media_type an entry from self::ALLOWED_TYPES
     * @return array{sections: array<int, array<string, mixed>>, overall_intent: string, suggested_pattern_ids: array<int, string>, figma: array<string, mixed>, source: string, fidelity: string}
     *
     * @throws \RuntimeException when the image is unusable or the model's answer is not a plan
     */
    public function from_image(string $bytes, string $media_type): array
    {
        if (!in_array($media_type, self::ALLOWED_TYPES, true)) {
            throw new \RuntimeException(sprintf(
                'Unsupported image type `%s`. Use PNG, JPEG, GIF or WebP.',
                $media_type
            ));
        }

        if ('' === $bytes) {
            throw new \RuntimeException('The image is empty.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException(sprintf(
                'The image is %s MB; the limit is %s MB. Export it at a smaller scale.',
                number_format(strlen($bytes) / 1048576, 1),
                number_format(self::MAX_BYTES / 1048576, 0)
            ));
        }

        $response = $this->client->generate_with_image(
            self::system_prompt(),
            self::user_prompt(),
            base64_encode($bytes),
            $media_type
        );

        $plan = LlmJson::decode((string) ($response['content'] ?? ''));

        if (!is_array($plan) || !is_array($plan['sections'] ?? null) || [] === $plan['sections']) {
            throw new \RuntimeException(
                'The model did not return a readable layout plan for this image. '
                . 'A full-page screenshot works better than a cropped one.'
            );
        }

        return self::normalise($plan);
    }

    /**
     * Force the model's answer into the same shape `FigmaDecomposer` emits.
     *
     * Normalising HERE rather than trusting the response is the difference between a reduced input
     * and a second contract: every consumer downstream reads `sections[].content[]`, and a model
     * that returned `copy` instead of `content` once would break them all in a way that looks like
     * a consumer bug.
     *
     * @param  array<string, mixed> $plan
     * @return array{sections: array<int, array<string, mixed>>, overall_intent: string, suggested_pattern_ids: array<int, string>, figma: array<string, mixed>, source: string, fidelity: string}
     */
    private static function normalise(array $plan): array
    {
        $sections = [];

        foreach ($plan['sections'] as $index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $content = [];

            foreach ((array) ($section['content'] ?? []) as $line) {
                $line = trim((string) $line);

                if ('' !== $line) {
                    $content[] = $line;
                }
            }

            $sections[] = [
                'intent' => trim((string) ($section['intent'] ?? sprintf('Section %d', $index + 1))),
                // Left empty for the same reason the Figma path leaves it empty: matching happens
                // later, with the block catalogue in front of it. A hint guessed from pixels would
                // compete with a catalogue the model can actually read.
                'pattern_hint' => '',
                'pattern_id' => '',
                'content' => $content,
                'structure' => [],
                /*
                 * FG3: an honest empty provenance rather than a borrowed one. There is no document
                 * and no node, so claiming either would put a trail in the record that leads
                 * nowhere -- the same reason a restyled tier does not inherit its parent's trace.
                 */
                'figma' => ['file' => '', 'node' => '', 'width' => 0, 'height' => 0],
                'source' => self::SOURCE,
            ];
        }

        return [
            'sections' => $sections,
            'overall_intent' => trim((string) ($plan['overall_intent'] ?? '')),
            'suggested_pattern_ids' => [],
            'figma' => ['file' => '', 'page' => '', 'node' => ''],
            'source' => self::SOURCE,
            // Carried in the payload, not only in the UI: an operator can dismiss a banner, and
            // this is the field that lets a fidelity report say what it was comparing against.
            'fidelity' => 'reduced',
        ];
    }

    private static function system_prompt(): string
    {
        return <<<'PROMPT'
            You read a screenshot of a web page design and describe its vertical sections.

            You are NOT recreating the design. Something downstream matches your description against
            a fixed library of components, so describe what each band of the page IS and what it
            SAYS, not how it looks.

            Rules:
            - Top to bottom, in reading order. One entry per horizontal band.
            - Include the header and the footer as sections if they are visible. Do not omit page
              chrome -- label it. Something later decides whether to keep it.
            - `content` is the text you can actually read in that band, verbatim, one string per
              distinct piece. Do not invent copy. If text is illegible, leave it out rather than
              guessing.
            - `intent` is one short phrase naming the band's job, for example "hero with headline
              and call to action" or "three-up service cards".
            - Do not name components, CSS classes or block types. You cannot see the library.

            Answer with JSON only, no prose and no code fence:
            {"overall_intent": "...", "sections": [{"intent": "...", "content": ["..."]}]}
            PROMPT;
    }

    private static function user_prompt(): string
    {
        return 'Describe this page design as vertical sections, in reading order.';
    }
}
