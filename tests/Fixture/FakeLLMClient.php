<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Fixture;

use RuntimeException;
use TimberlandAIPageBuilder\LLMClientInterface;

/**
 * A scripted `LLMClientInterface` — the seam the eval suite drives the pipeline through.
 *
 * Not an HTTP mock, deliberately. `ClaudeClient` builds `new Anthropic\Client(apiKey: ...)` in
 * its constructor and `OpenAIClient` does the equivalent, so there is no transport to intercept;
 * a `MockHttpClient` could not reach either. `LLMClientInterface` is the actual boundary and
 * both real clients already implement it, so a fake here exercises everything downstream of the
 * API call — validation, the retry, post-processing, assembly — without a key, a network call or
 * a charge.
 *
 * It RECORDS what it was asked, which is half the value: the prompts a generation sends are
 * otherwise invisible, and the retry's feedback message is the thing most likely to be wrong.
 */
final class FakeLLMClient implements LLMClientInterface {

    /** @var array<int, string> responses handed out in order, one per call */
    private array $script;

    /** @var array<int, array{system: string, user: string, errors: array<int, string>}> */
    private array $calls = array();

    /** @var array<int, array{name: string, input: array<string, mixed>, result: string}> */
    private array $tool_calls = array();

    private ?string $throw = null;

    /**
     * @param array<int, string> $script markup to return, one entry per expected call.
     */
    public function __construct( array $script = array() ) {
        $this->script = $script;
    }

    /** Make the next call throw, for the no-key and API-error paths. */
    public static function throwing( string $message ): self {
        $client = new self();
        $client->throw = $message;

        return $client;
    }

    public function generate( string $system_prompt, string $user_prompt ): array {
        return $this->respond( $system_prompt, $user_prompt, array() );
    }

    public function generate_with_retry( string $system_prompt, string $user_prompt, array $validation_errors ): array {
        return $this->respond( $system_prompt, $user_prompt, $validation_errors );
    }

    /**
     * Replay a scripted tool loop.
     *
     * A script entry is either a string (the model's final text) or an array of tool calls:
     *
     *     new FakeLLMClient( array(
     *         array( array( 'name' => 'get_block_fields', 'input' => array( 'slugs' => array( 'hero-unit' ) ) ) ),
     *         '<!-- wp:acf/hero-unit ... /-->',
     *     ) )
     *
     * Every dispatched result is RECORDED, which is the point: the budget's withholding and the
     * "UNAVAILABLE, do not write this block" wording are only observable here, and they are
     * exactly the strings a real model would have to act on.
     *
     * `$max_tool_turns` is honoured, so the stop condition is covered by the same fake -- a
     * script that keeps asking must stop asking rather than loop forever.
     */
    public function generate_with_tools(
        string $system_prompt,
        string $user_prompt,
        array $tools,
        callable $dispatch,
        int $max_tool_turns = 4
    ): array {
        $this->calls[] = array( 'system' => $system_prompt, 'user' => $user_prompt, 'errors' => array() );

        if ( null !== $this->throw ) {
            throw new RuntimeException( $this->throw );
        }

        $turns = 0;

        while ( true ) {
            if ( array() === $this->script ) {
                throw new RuntimeException(
                    sprintf( 'FakeLLMClient: tool loop ran out of script after %d turn(s).', $turns )
                );
            }

            $next = array_shift( $this->script );

            if ( is_string( $next ) ) {
                return $this->toolResult( $next, $turns, 'end_turn' );
            }

            ++$turns;

            foreach ( (array) $next as $call ) {
                $name   = (string) ( $call['name'] ?? '' );
                $input  = (array) ( $call['input'] ?? array() );
                $result = (string) $dispatch( $name, $input );

                $this->tool_calls[] = array(
                    'name'   => $name,
                    'input'  => $input,
                    'result' => $result,
                );
            }

            // The cap is a stop, not an error: tools stop being offered and the model is asked
            // for its final answer, so a page still comes out.
            if ( $turns >= $max_tool_turns ) {
                $final = array() === $this->script ? '' : array_shift( $this->script );

                return $this->toolResult( is_string( $final ) ? $final : '', $turns, 'max_tool_turns' );
            }
        }
    }

    /**
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string, tool_turns: int, cache_read_tokens: int, cache_write_tokens: int}
     */
    private function toolResult( string $content, int $turns, string $stop_reason ): array {
        return array(
            'content'            => $content,
            'input_tokens'       => 100,
            'output_tokens'      => 200,
            'model'              => 'fake-model',
            'stop_reason'        => $stop_reason,
            'tool_turns'         => $turns,
            'cache_read_tokens'  => 0,
            'cache_write_tokens' => 0,
        );
    }

    /** @return array<int, array{name: string, input: array<string, mixed>, result: string}> */
    public function toolCalls(): array {
        return $this->tool_calls;
    }

    /**
     * @param array<int, string> $errors
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string, stop_reason: string}
     */
    private function respond( string $system, string $user, array $errors ): array {
        $this->calls[] = array( 'system' => $system, 'user' => $user, 'errors' => $errors );

        if ( $this->throw !== null ) {
            throw new RuntimeException( $this->throw );
        }

        // Running out of script is a test bug, not a pass. Returning '' would look like a model
        // that produced nothing and send the assertion somewhere misleading.
        if ( $this->script === array() ) {
            throw new RuntimeException(
                sprintf( 'FakeLLMClient: call %d has no scripted response.', count( $this->calls ) )
            );
        }

        return array(
            'content'       => array_shift( $this->script ),
            'input_tokens'  => 100,
            'output_tokens' => 200,
            'model'         => 'fake-model',
            'stop_reason'   => 'end_turn',
        );
    }

    public function callCount(): int {
        return count( $this->calls );
    }

    /** @return array{system: string, user: string, errors: array<int, string>} */
    public function call( int $index ): array {
        if ( ! isset( $this->calls[ $index ] ) ) {
            throw new RuntimeException( "FakeLLMClient: no call at index {$index}." );
        }

        return $this->calls[ $index ];
    }
}
