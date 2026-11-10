<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\MarkupValidator;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * FG3 — provenance is a LABEL, never a gate.
 *
 * Nothing in the pipeline may refuse to render a component because its design provenance says
 * `custom`, or because it carries deviations. The whole library is `custom` today (41/41, zero
 * deviations recorded), so a gate would reject everything — but the reason is not pragmatic. A
 * gate would make people omit the `## Design Provenance` section, and an omitted section is
 * indistinguishable from an untraced component, which is exactly the state provenance exists to
 * end.
 *
 * This suite exists because the property is currently true by ACCIDENT: no plugin code reads
 * `x-tl-provenance` at all. A future change that starts consulting it would break the rule
 * silently, and nothing else would notice.
 */
class ProvenanceIsNotAGateTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        $this->stubWordPress();
    }

    protected function tearDown(): void
    {
        $this->unstubWordPress();
    }

    /** @param array<string, mixed> $provenance */
    private function validateWithProvenance(array $provenance): array
    {
        $manifest = ManifestFixture::manifest();

        foreach (array_keys($manifest['blocks']) as $name) {
            $manifest['blocks'][$name]['provenance'] = $provenance;
        }

        $markup = '<!-- wp:acf/card ' . json_encode([
            'name' => 'acf/card',
            'data' => ['card_title' => 'Hello', '_card_title' => 'field_aaa111'],
        ]) . ' /-->';

        return (new MarkupValidator($manifest))->validate($markup);
    }

    public function test_a_custom_component_validates_exactly_like_an_approved_one(): void
    {
        $approved = $this->validateWithProvenance([
            'file' => 'aBcDeFgHiJkL',
            'node' => '2503:3665',
            'node_mobile' => '',
            'status' => 'approved',
            'deviations' => [],
        ]);

        $custom = $this->validateWithProvenance([
            'file' => '',
            'node' => '',
            'node_mobile' => '',
            'status' => 'custom',
            'deviations' => [],
        ]);

        $this->assertSame($approved['valid'], $custom['valid']);
        $this->assertSame(count($approved['errors']), count($custom['errors']));
        $this->assertSame($approved['errors'], $custom['errors']);
    }

    public function test_deviations_do_not_produce_errors_or_warnings(): void
    {
        $clean = $this->validateWithProvenance([
            'file' => 'aBcDeFgHiJkL',
            'node' => '2503:3665',
            'node_mobile' => '',
            'status' => 'approved',
            'deviations' => [],
        ]);

        $deviating = $this->validateWithProvenance([
            'file' => 'aBcDeFgHiJkL',
            'node' => '2503:3665',
            'node_mobile' => '',
            'status' => 'custom',
            'deviations' => [
                'card shadow has no token; Figma specifies 0 2px 8px rgba(0,0,0,.08)',
                'the 14px gutter is not on the spacing scale',
            ],
        ]);

        $this->assertSame($clean['valid'], $deviating['valid']);
        $this->assertSame($clean['errors'], $deviating['errors']);
        $this->assertSame($clean['warnings'], $deviating['warnings']);
    }

    public function test_absent_provenance_is_not_an_error(): void
    {
        // The common case today, and it must stay unremarkable.
        $manifest = ManifestFixture::manifest();
        $markup = '<!-- wp:acf/card ' . json_encode([
            'name' => 'acf/card',
            'data' => ['card_title' => 'Hello', '_card_title' => 'field_aaa111'],
        ]) . ' /-->';

        $result = (new MarkupValidator($manifest))->validate($markup);

        // Asserted unconditionally: the first version only checked inside a foreach over the
        // messages, so with no messages it asserted nothing and passed vacuously -- PHPUnit
        // flagged it risky, which is the only reason it was caught.
        $messages = implode(' ', array_merge($result['errors'], $result['warnings']));

        $this->assertStringNotContainsStringIgnoringCase('provenance', $messages);
        $this->assertStringNotContainsStringIgnoringCase('figma', $messages);
        $this->assertTrue($result['valid'], 'a block with no provenance at all must still validate');
    }
}
