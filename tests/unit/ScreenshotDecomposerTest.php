<?php

declare(strict_types=1);

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\ScreenshotDecomposer;
use TimberlandAIPageBuilder\Tests\Fixture\FakeLLMClient;

/**
 * FG1's reduced-fidelity input: a screenshot, for the client with no Figma file.
 *
 * These pin the two properties that make it a reduced INPUT rather than a second pipeline: the
 * output is the same contract `FigmaDecomposer` emits, so a screenshot enters the same
 * `/decompose -> confirm -> /structure -> /assemble` flow and gets the same operator review; and
 * its provenance is honestly empty, because there is no document and no node to point at.
 */
final class ScreenshotDecomposerTest extends TestCase
{
    private function plan(string $json): array
    {
        return (new ScreenshotDecomposer(new FakeLLMClient([$json])))
            ->from_image('fake-png-bytes', 'image/png');
    }

    public function test_it_emits_the_same_contract_the_figma_path_emits(): void
    {
        // The whole reason it returns a plan instead of markup: a screenshot path that produced
        // blocks directly would be the one route where nobody reviews the plan, and it is the
        // route where review matters most -- the operator can see the picture, the model's
        // reading of it is what is most likely to be wrong.
        $plan = $this->plan('{"overall_intent":"A services page","sections":[{"intent":"hero","content":["Grow your practice"]}]}');

        foreach (['sections', 'overall_intent', 'suggested_pattern_ids', 'figma'] as $key) {
            $this->assertArrayHasKey($key, $plan);
        }

        $this->assertSame('A services page', $plan['overall_intent']);
        $this->assertSame('hero', $plan['sections'][0]['intent']);
        $this->assertSame(['Grow your practice'], $plan['sections'][0]['content']);
    }

    public function test_provenance_is_empty_rather_than_borrowed(): void
    {
        // FG3's rule: a tier that cannot make a claim gets an honest default, not its parent's
        // trace. There is no file and no node here, so saying otherwise would put a trail in the
        // record that leads nowhere.
        $plan = $this->plan('{"sections":[{"intent":"hero","content":["x"]}]}');

        $this->assertSame('', $plan['sections'][0]['figma']['file']);
        $this->assertSame('', $plan['sections'][0]['figma']['node']);
        $this->assertSame(ScreenshotDecomposer::SOURCE, $plan['sections'][0]['source']);
    }

    public function test_reduced_fidelity_travels_in_the_payload_not_just_the_ui(): void
    {
        // A banner can be dismissed; a consumer still has to know what it is holding.
        $plan = $this->plan('{"sections":[{"intent":"hero","content":["x"]}]}');

        $this->assertSame('reduced', $plan['fidelity']);
        $this->assertSame(ScreenshotDecomposer::SOURCE, $plan['source']);
    }

    public function test_a_renamed_field_cannot_break_every_consumer_downstream(): void
    {
        // Normalising rather than trusting the response is the difference between a reduced input
        // and a second contract. A model answering `copy` instead of `content` once would break
        // every consumer in a way that looks like a consumer bug.
        $plan = $this->plan('{"sections":[{"intent":"hero","copy":["ignored"]}]}');

        $this->assertSame([], $plan['sections'][0]['content']);
        $this->assertArrayHasKey('structure', $plan['sections'][0]);
    }

    public function test_a_section_with_no_intent_still_gets_a_label(): void
    {
        $plan = $this->plan('{"sections":[{"content":["x"]},{"content":["y"]}]}');

        $this->assertSame('Section 1', $plan['sections'][0]['intent']);
        $this->assertSame('Section 2', $plan['sections'][1]['intent']);
    }

    public function test_an_unreadable_answer_fails_with_advice_rather_than_an_empty_plan(): void
    {
        // An empty plan would travel all the way to the review panel and show the operator
        // nothing, which reads as "the image was fine and the design was empty".
        foreach (['not json at all', '{"sections":[]}', '{"overall_intent":"x"}'] as $bad) {
            try {
                $this->plan($bad);
                $this->fail("accepted an unusable answer: {$bad}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('layout plan', $e->getMessage());
            }
        }
    }

    public function test_an_unsupported_type_is_refused_before_the_image_is_sent(): void
    {
        $client = new FakeLLMClient(['{"sections":[{"intent":"x","content":["y"]}]}']);

        try {
            (new ScreenshotDecomposer($client))->from_image('bytes', 'image/svg+xml');
            $this->fail('accepted an SVG');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('image/svg+xml', $e->getMessage());
        }

        $this->assertNull($client->last_image, 'the image must not reach the provider');
    }

    public function test_an_oversized_image_is_refused_before_the_request(): void
    {
        // Checked here because the far-side failure is a provider error an operator cannot act on,
        // and because base64 inflates by a third -- a file that fits on disk can still exceed the
        // wire limit.
        $client = new FakeLLMClient(['{"sections":[{"intent":"x","content":["y"]}]}']);

        try {
            (new ScreenshotDecomposer($client))->from_image(
                str_repeat('x', ScreenshotDecomposer::MAX_BYTES + 1),
                'image/png'
            );
            $this->fail('accepted an oversized image');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MB', $e->getMessage());
        }

        $this->assertNull($client->last_image, 'the image must not reach the provider');
    }

    public function test_the_image_is_sent_as_bytes_never_as_a_url(): void
    {
        // A URL would move the fetch onto the provider's network -- the SSRF shape FG2 closed on
        // the Figma side, relocated rather than removed.
        $client = new FakeLLMClient(['{"sections":[{"intent":"x","content":["y"]}]}']);

        (new ScreenshotDecomposer($client))->from_image('real-bytes', 'image/webp');

        $this->assertSame(base64_encode('real-bytes'), $client->last_image['data']);
        $this->assertSame('image/webp', $client->last_image['media_type']);
    }
}
