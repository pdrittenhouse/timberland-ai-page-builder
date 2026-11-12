<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Common interface for LLM API clients.
 *
 * All providers must return the same normalized response array.
 */
interface LLMClientInterface
{
    /**
     * Send a generation request.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate(string $system_prompt, string $user_prompt): array;

    /**
     * Send a generation request with a retry that includes validation feedback.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate_with_retry(string $system_prompt, string $user_prompt, array $validation_errors): array;

    /**
     * Send a generation request that includes an image.
     *
     * **FG1: the reduced-fidelity path.** A Figma URL yields structure AND pixels -- frame names,
     * bounding boxes, reading order, text by prominence -- so it is strictly more capable. This
     * exists for the client who sends a screenshot and has no Figma file at all, and what it gets
     * back is a vision model's impression: no frame names, no measured order, no gap reporting.
     * The UI must say so, or the worse results get attributed to the model.
     *
     * `$media_type` is an IANA type the provider accepts (`image/png`, `image/jpeg`,
     * `image/gif`, `image/webp`). `$image_base64` is raw base64 with no data: prefix -- callers
     * pass bytes, not URLs, because a URL would make the provider fetch an arbitrary host on our
     * behalf, which is the SSRF surface FG2 already closed on the Figma side.
     *
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    public function generate_with_image(
        string $system_prompt,
        string $user_prompt,
        string $image_base64,
        string $media_type
    ): array;

    /**
     * Whether this provider's image path has been exercised against a live account.
     *
     * Not "does the provider support vision" -- both do -- but "do we know OURS works". The
     * OpenAI path is written to its API documentation and has never run, because the account has
     * no credits. Shipping it as though it were verified would turn a first failure into an opaque
     * provider error that nobody can tell apart from a billing problem, so callers can warn
     * instead.
     */
    public function vision_is_verified(): bool;

    /**
     * Run a generation that can fetch its own context through tools.
     *
     * **Why this is a client concern.** The turn loop is pure provider shape -- Anthropic answers
     * with `tool_use` content blocks and expects `tool_result` blocks back, OpenAI answers with
     * `tool_calls` and expects `role: tool` messages -- and that is the one thing this interface
     * exists to hide. What a tool actually RETURNS is plugin logic, so it arrives as `$dispatch`
     * and no provider code ever knows what a block is.
     *
     * `$tools` is provider-neutral: each entry is
     * `{name: string, description: string, input_schema: array}` (JSON Schema), which each client
     * translates into its own wire format.
     *
     * `$dispatch` is `fn(string $name, array $input): string` and returns the tool result body.
     * It must not throw for an unknown tool -- returning an error string keeps the model in the
     * loop, where it can correct itself, instead of failing the whole generation.
     *
     * `$max_tool_turns` bounds the loop. It is a hard stop, not a budget: the budget decides what
     * a result CONTAINS, this decides how many times the model may ask. Reaching it is not an
     * error -- the loop stops offering tools and asks for the final answer, so a model that keeps
     * fetching still produces a page.
     *
     * @param array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param callable(string, array<string, mixed>): string $dispatch
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string, tool_turns: int, cache_read_tokens: int, cache_write_tokens: int}
     */
    public function generate_with_tools(
        string $system_prompt,
        string $user_prompt,
        array $tools,
        callable $dispatch,
        int $max_tool_turns = 4
    ): array;
}
