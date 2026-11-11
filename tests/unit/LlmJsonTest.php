<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\LlmJson;

/**
 * The replies that used to be thrown away.
 *
 * Every "real reply" case below decoded to null under the old leading-fence-only stripper, and the
 * callers reported them as the model returning something invalid. The model had returned a valid
 * document each time; the parser could not see past the first character.
 */
class LlmJsonTest extends TestCase
{
    public function test_a_bare_document_still_works(): void
    {
        $this->assertSame(['blocks' => [1]], LlmJson::decode('{"blocks":[1]}'));
    }

    public function test_a_leading_fence_still_works(): void
    {
        // The one case the old code handled. Pinned so the rewrite cannot regress it.
        $this->assertSame(['blocks' => [1]], LlmJson::decode("```json\n{\"blocks\":[1]}\n```"));
    }

    /**
     * @dataProvider repliesThatUsedToFail
     */
    public function test_prose_around_the_document_is_forgiven(string $raw): void
    {
        $this->assertSame(['blocks' => [1]], LlmJson::decode($raw));
    }

    /** @return array<string, array{0: string}> */
    public static function repliesThatUsedToFail(): array
    {
        return [
            'a courteous preamble' => ["Here is the block tree:\n```json\n{\"blocks\":[1]}\n```"],
            'a trailing remark' => ["```json\n{\"blocks\":[1]}\n```\nLet me know if you want changes."],
            'prose on both sides' => ["Sure.\n```json\n{\"blocks\":[1]}\n```\nDone."],
            'no fence at all' => ['Here you go: {"blocks":[1]}'],
            'an unlabelled fence' => ["```\n{\"blocks\":[1]}\n```"],
            'leading blank lines' => ["\n\n\n{\"blocks\":[1]}"],
        ];
    }

    public function test_the_last_fenced_block_wins(): void
    {
        // A reply that thinks in one block and answers in another puts the answer last. Taking the
        // first would return the draft and call it the result.
        $raw = "Draft:\n```json\n{\"blocks\":[]}\n```\nFinal:\n```json\n{\"blocks\":[1]}\n```";

        $this->assertSame(['blocks' => [1]], LlmJson::decode($raw));
    }

    public function test_a_truncated_document_is_null_not_a_partial(): void
    {
        // A cut-off reply must NOT half-decode into something a caller treats as complete. Null is
        // the honest answer; the caller then reports the token cap, which is the real cause.
        $this->assertNull(LlmJson::decode('{"blocks":[{"block":"acf/section","data":{"a":'));
    }

    /**
     * @dataProvider nothingUsable
     */
    public function test_nothing_usable_is_null(string $raw): void
    {
        $this->assertNull(LlmJson::decode($raw));
    }

    /** @return array<string, array{0: string}> */
    public static function nothingUsable(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["  \n\t "],
            'pure prose' => ['I cannot build that page.'],
            'prose with braces' => ['Use the {section} block please'],
            'a JSON scalar' => ['"just a string"'],
        ];
    }

    public function test_an_array_document_is_accepted(): void
    {
        // parse_block_tree() supports a bare list as well as {"blocks": [...]}, so the extractor
        // must not quietly privilege objects.
        $this->assertSame([['block' => 'acf/section']], LlmJson::decode('[{"block":"acf/section"}]'));
    }
}
