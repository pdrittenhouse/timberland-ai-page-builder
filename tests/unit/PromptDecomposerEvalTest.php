<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TimberlandAIPageBuilder\PromptDecomposer;
use TimberlandAIPageBuilder\Tests\Fixture\FakeLLMClient;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * `PromptDecomposer` — the last untested builder, and the only one that calls out.
 *
 * It was untestable until now: `decompose()` constructs `ClaudeClient`/`OpenAIClient` internally,
 * so merely calling it threw `Anthropic API key is not configured` before reaching any logic.
 * A client factory is now injectable, and the seam is `LLMClientInterface` rather than the HTTP
 * layer because the SDK clients build their own transports and a mock HTTP client cannot reach
 * them.
 *
 * What is worth testing here is the PARSING and the FALLBACK, not the request. A model's JSON
 * arrives fenced, truncated, half-shaped or not at all, and everything downstream
 * (`StructurePromptBuilder`, the block tree, the assembler) consumes whatever this returns.
 */
final class PromptDecomposerEvalTest extends TestCase {

    use WordPressStubs;

    protected function setUp(): void {
        $this->stubWordPress();
    }

    protected function tearDown(): void {
        $this->unstubWordPress();
    }

    private function decomposer( FakeLLMClient ...$clients ): PromptDecomposer {
        return new PromptDecomposer( static fn(): array => $clients );
    }

    private const GOOD = '{"sections":[{"intent":"Hero","pattern_hint":"hero","content":{"headline":"Hi"}}],"overall_intent":"A landing page","suggested_pattern_ids":[]}';

    public function test_a_well_formed_response_is_parsed(): void {
        $result = $this->decomposer( new FakeLLMClient( array( self::GOOD ) ) )
            ->decompose( 'A landing page', ManifestFixture::manifest() );

        $this->assertSame( 'A landing page', $result['overall_intent'] );
        $this->assertCount( 1, $result['sections'] );
        $this->assertSame( 'Hero', $result['sections'][0]['intent'] );
    }

    public function test_a_fenced_response_is_parsed(): void {
        // The single most common deviation: the model wraps JSON in ```json even when told not
        // to. If this were not stripped, every such response would fall back to an empty plan
        // and the page would be built from nothing, with no error anywhere.
        $client = new FakeLLMClient( array( "```json\n" . self::GOOD . "\n```" ) );

        $result = $this->decomposer( $client )->decompose( 'A landing page', ManifestFixture::manifest() );

        $this->assertCount( 1, $result['sections'] );
    }

    public function test_unparseable_json_degrades_to_an_empty_plan_rather_than_throwing(): void {
        // Truncation mid-object is what a token limit looks like. An empty plan is recoverable
        // by the caller; an exception from inside a builder is not.
        $client = new FakeLLMClient( array( '{"sections":[{"intent":"Hero"' ) );

        $result = $this->decomposer( $client )->decompose( 'A landing page', ManifestFixture::manifest() );

        $this->assertSame( array(), $result['sections'] );
        $this->assertSame( '', $result['overall_intent'] );
    }

    public function test_prose_around_the_json_degrades_to_an_empty_plan(): void {
        $client = new FakeLLMClient( array( "Sure! Here's the plan:\n\n" . self::GOOD ) );

        $result = $this->decomposer( $client )->decompose( 'A landing page', ManifestFixture::manifest() );

        // Recorded as current behaviour, not endorsed: only fences are stripped, so leading prose
        // defeats the parse and the plan is silently empty. Worth knowing before trusting it --
        // the fix belongs with the fence-stripping, which already handles the adjacent case.
        $this->assertSame( array(), $result['sections'] );
    }

    public function test_the_first_client_to_succeed_wins_and_the_rest_are_not_called(): void {
        // The cost-order fallback: cheapest model first, and no further spend once one answers.
        $first = new FakeLLMClient( array( self::GOOD ) );
        $second = new FakeLLMClient( array( self::GOOD ) );

        $this->decomposer( $first, $second )->decompose( 'A landing page', ManifestFixture::manifest() );

        $this->assertSame( 1, $first->callCount() );
        $this->assertSame( 0, $second->callCount(), 'A second model was paid for after the first answered.' );
    }

    public function test_a_failing_client_falls_through_to_the_next(): void {
        $broken = FakeLLMClient::throwing( 'Anthropic API key is not configured.' );
        $working = new FakeLLMClient( array( self::GOOD ) );

        $result = $this->decomposer( $broken, $working )
            ->decompose( 'A landing page', ManifestFixture::manifest() );

        $this->assertCount( 1, $result['sections'] );
        $this->assertSame( 1, $working->callCount() );
    }

    public function test_every_client_failing_throws_with_the_last_error(): void {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Decomposition failed/' );

        $this->decomposer(
            FakeLLMClient::throwing( 'first failed' ),
            FakeLLMClient::throwing( 'second failed' )
        )->decompose( 'A landing page', ManifestFixture::manifest() );
    }

    public function test_the_operators_custom_instructions_reach_the_decomposer_prompt(): void {
        $this->unstubWordPress();
        $this->stubWordPress( array( 'custom_system_prompt' => 'ZZ-DECOMPOSER-MARKER' ) );

        $client = new FakeLLMClient( array( self::GOOD ) );
        $this->decomposer( $client )->decompose( 'A landing page', ManifestFixture::manifest() );

        $this->assertStringContainsString( 'ZZ-DECOMPOSER-MARKER', $client->call( 0 )['system'] );
    }
}
