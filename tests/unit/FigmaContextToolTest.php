<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\FigmaContextTool;
use TimberlandAIPageBuilder\FigmaUrl;
use TimberlandAIPageBuilder\PromptBudget;
use TimberlandAIPageBuilder\Redact;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * Exploring a Figma file the way the model explores the block library: list, then fetch.
 *
 * Driven through a stubbed transport returning a trimmed slice of the REAL Natural Rose payload,
 * so the assertions are about shapes the file actually has -- icon litter, `Frame 19` twice, a nav
 * bar repeated on every page -- rather than shapes I would have invented.
 */
class FigmaContextToolTest extends TestCase
{
    use WordPressStubs;

    private int $fetches = 0;

    protected function setUp(): void
    {
        $this->stubWordPress();
        $this->fetches = 0;

        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('is_wp_error')->alias(static fn($t): bool => $t instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(
            fn(array $r): string => (string) ($r['body'] ?? '')
        );
        Functions\when('wp_safe_remote_get')->alias(function () {
            $this->fetches++;

            return ['body' => (string) file_get_contents(__DIR__ . '/../Fixture/figma/canvas-nodes.json')];
        });
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    private function tool(?PromptBudget $budget = null): FigmaContextTool
    {
        $ref = FigmaUrl::parse('https://www.figma.com/design/P7oiVKKoeacO5W2nd8Bpwq/Natural-Rose?node-id=0-1');
        $this->assertInstanceOf(FigmaUrl::class, $ref);

        return new FigmaContextTool(
            $ref,
            $budget ?? new PromptBudget([], 8000),
            new \TimberlandAIPageBuilder\FigmaClient('figd_testtoken_longenough')
        );
    }

    public function test_the_list_names_candidates_with_distinguishing_evidence(): void
    {
        $out = $this->tool()->dispatch(FigmaContextTool::LIST_NAME, []);

        $this->assertStringContainsString('"Home"', $out);
        // Prominence sampling, not position: the nav bar is on every page and identifies none.
        $this->assertStringContainsString('Wellness, rooted in nature', $out);
        $this->assertStringNotContainsString('Shop    Herbs    Journal', $out);
        $this->assertStringContainsString('id=', $out, 'the model needs an id to fetch with');
    }

    public function test_the_list_says_what_it_skipped_rather_than_hiding_it(): void
    {
        $out = $this->tool()->dispatch(FigmaContextTool::LIST_NAME, []);

        $this->assertMatchesRegularExpression('/\d+ small or decorative item\(s\) skipped/', $out);
        $this->assertStringNotContainsString('noun_Ribbon', $out, 'icon litter is not a candidate');
    }

    public function test_a_page_comes_back_as_ordered_bands_with_font_sizes(): void
    {
        $tool = $this->tool();
        $list = $tool->dispatch(FigmaContextTool::LIST_NAME, []);

        preg_match('/id=(\S+)\s+"Home"/', $list, $m);
        $this->assertNotEmpty($m, 'Home must be listed with an id');

        $out = $tool->dispatch(FigmaContextTool::GET_NAME, ['page_id' => $m[1]]);

        $this->assertStringContainsString('top to bottom', $out);
        $this->assertMatchesRegularExpression('/\d+px "/', $out, 'font sizes carry the heading hierarchy');
        $this->assertStringContainsString('Herbs / Teas / Tinctures', $out, 'list separators must survive');
        $this->assertSame([$m[1]], $tool->served());
    }

    public function test_the_payload_is_fetched_once_and_reused(): void
    {
        // 3.9 MB per fetch on the real file, and the model calls list-then-get at minimum.
        $tool = $this->tool();
        $list = $tool->dispatch(FigmaContextTool::LIST_NAME, []);
        preg_match('/id=(\S+)/', $list, $m);
        $tool->dispatch(FigmaContextTool::GET_NAME, ['page_id' => $m[1]]);
        $tool->dispatch(FigmaContextTool::GET_NAME, ['page_id' => $m[1]]);

        $this->assertSame(1, $this->fetches);
    }

    public function test_an_unknown_page_id_sends_the_model_back_to_the_list(): void
    {
        $out = $this->tool()->dispatch(FigmaContextTool::GET_NAME, ['page_id' => '999:999']);

        $this->assertStringContainsString('No such page id', $out);
        $this->assertStringContainsString(FigmaContextTool::LIST_NAME, $out);
    }

    public function test_a_missing_page_id_is_answered_not_thrown(): void
    {
        $out = $this->tool()->dispatch(FigmaContextTool::GET_NAME, []);

        $this->assertStringContainsString('required', $out);
    }

    public function test_an_unaffordable_page_is_declared_not_silently_omitted(): void
    {
        $budget = new PromptBudget([], 60);
        $tool = $this->tool($budget);
        $list = $tool->dispatch(FigmaContextTool::LIST_NAME, []);

        // Even the list does not fit, and that must be said rather than returned empty.
        $this->assertStringContainsString('budget', $list);
        $this->assertSame([], $tool->served());
    }

    public function test_a_fetch_failure_is_reported_with_the_credential_redacted(): void
    {
        Functions\when('wp_safe_remote_get')->alias(
            static fn() => new \WP_Error('http', 'Bad token figd_realsecrettokenvalue123456 rejected')
        );

        $out = $this->tool()->dispatch(FigmaContextTool::LIST_NAME, []);

        $this->assertStringNotContainsString('figd_realsecrettokenvalue123456', $out);
        $this->assertStringContainsString('<redacted>', $out);
        $this->assertStringContainsString('may have expired', $out, 'the likely cause is actionable');
    }

    public function test_redact_covers_both_provider_shapes(): void
    {
        $text = 'sk-ant-api03-abcdefghijklmnop and figd_abcdefghijklmnop leaked';
        $clean = Redact::credentials($text);

        $this->assertStringNotContainsString('sk-ant-api03-abcdefghijklmnop', $clean);
        $this->assertStringNotContainsString('figd_abcdefghijklmnop', $clean);
        $this->assertStringContainsString('leaked', $clean, 'only the credential is removed');
    }
}
