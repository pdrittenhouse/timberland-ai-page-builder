<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\MarkupGenerator;
use TimberlandAIPageBuilder\Tests\Fixture\FakeLLMClient;
use TimberlandAIPageBuilder\Tests\Fixture\FakeManifestStore;
use TimberlandAIPageBuilder\Tests\Fixture\FakeRateLimiter;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * The retry-with-validation-feedback loop, end to end.
 *
 * This is the mechanism that makes validation worth having. `MarkupGenerator` validates the
 * model's output and, if it failed, calls back once with the errors as feedback — so the
 * validator's precision decides whether the second attempt is better or just different. It had
 * never been exercised: the generator constructed its own API client, so reaching this code
 * required a key, a network call and a charge.
 *
 * Driven through `LLMClientInterface`, not HTTP. The SDK clients build their own transports.
 */
final class GenerationRetryEvalTest extends TestCase {

    use WordPressStubs;

    protected function setUp(): void {
        $this->stubWordPress();
        $this->stubThemeDirs();
        $this->stubGenerationPipeline();
    }

    protected function tearDown(): void {
        $this->unstubWordPress();
    }

    private function generator( FakeLLMClient $client, ?FakeRateLimiter $limiter = null ): MarkupGenerator {
        return new MarkupGenerator(
            new FakeManifestStore( $this->manifest() ),
            $limiter ?? new FakeRateLimiter(),
            static fn( ?string $model ) => $client
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array {
        $manifest = ManifestFixture::manifest();
        $manifest['blocks']['acf/card']['field_key_map'] = array(
            'card_title' => 'field_aaa111',
            'card_body'  => 'field_bbb222',
        );
        $manifest['blocks']['acf/card']['field_type_map'] = array(
            'card_title' => 'text',
            'card_body'  => 'wysiwyg',
        );

        return $manifest;
    }

    private function block( array $data ): string {
        return '<!-- wp:acf/card ' . json_encode(
            array( 'name' => 'acf/card', 'data' => $data, 'mode' => 'preview' )
        ) . ' /-->';
    }

    public function test_valid_output_is_returned_without_a_retry(): void {
        $client = new FakeLLMClient( array( $this->block( array( 'card_title' => 'Hello' ) ) ) );

        $result = $this->generator( $client )->generate( 'a card' );

        $this->assertSame( 1, $client->callCount(), 'A retry was spent on output that already validated.' );
        $this->assertStringContainsString( 'acf/card', $result['markup'] );
    }

    public function test_invalid_output_triggers_exactly_one_retry(): void {
        // `card_subtitel` does not exist on the block. Before schema validation this passed
        // silently; now it is an error, which is what makes the retry fire at all.
        $client = new FakeLLMClient(
            array(
                $this->block( array( 'card_subtitel' => 'typo' ) ),
                $this->block( array( 'card_title' => 'Corrected' ) ),
            )
        );

        $result = $this->generator( $client )->generate( 'a card' );

        $this->assertSame( 2, $client->callCount() );
        $this->assertStringContainsString( 'Corrected', $result['markup'] );
        $this->assertSame( array(), $result['validation']['errors'] );
    }

    public function test_the_retry_is_told_what_was_wrong(): void {
        // The whole point of the loop. A retry that resends the original prompt with no feedback
        // is just a second roll of the dice; this asserts the errors actually travel.
        $client = new FakeLLMClient(
            array(
                $this->block( array( 'card_subtitel' => 'typo' ) ),
                $this->block( array( 'card_title' => 'Corrected' ) ),
            )
        );

        $this->generator( $client )->generate( 'a card' );

        $feedback = $client->call( 1 )['errors'];
        $this->assertNotEmpty( $feedback, 'The retry carried no validation feedback.' );
        $this->assertStringContainsString( 'card_subtitel', implode( ' ', $feedback ) );
    }

    public function test_a_worse_retry_is_discarded_and_the_first_attempt_kept(): void {
        // The guard against making things worse. The retry here breaks two fields instead of
        // one, so the original must survive.
        $client = new FakeLLMClient(
            array(
                $this->block( array( 'card_subtitel' => 'one typo' ) ),
                $this->block( array( 'card_subtitel' => 'still wrong', 'card_boddy' => 'and another' ) ),
            )
        );

        $result = $this->generator( $client )->generate( 'a card' );

        $this->assertSame( 2, $client->callCount() );
        $this->assertStringContainsString( 'one typo', $result['markup'] );
    }

    public function test_rate_limit_usage_is_recorded_once_per_generation(): void {
        $limiter = new FakeRateLimiter();
        $client = new FakeLLMClient(
            array(
                $this->block( array( 'card_subtitel' => 'typo' ) ),
                $this->block( array( 'card_title' => 'Corrected' ) ),
            )
        );

        $this->generator( $client, $limiter )->generate( 'a card' );

        // One generation, one charge against the user's allowance -- a retry is the product's
        // own correction, not a second request the user made.
        $this->assertSame( 1, $limiter->recorded );
    }

    public function test_the_model_is_never_sent_acf_field_keys(): void {
        // Asserted here as well as in the budget suite, because this is the path that actually
        // reaches an API: 97% of the per-block cost, discarded on arrival.
        $client = new FakeLLMClient( array( $this->block( array( 'card_title' => 'Hello' ) ) ) );

        $this->generator( $client )->generate( 'a card' );

        $this->assertStringNotContainsString( 'field_aaa111', $client->call( 0 )['system'] );
    }
}
