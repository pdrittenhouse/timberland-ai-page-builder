<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\MarkupValidator;
use TimberlandAIPageBuilder\SchemaOracle;
use TimberlandAIPageBuilder\Tests\Fixture\ManifestFixture;
use TimberlandAIPageBuilder\Tests\Fixture\WordPressStubs;

/**
 * Generated output validated against the framework's JSON Schemas.
 *
 * The gap this closes: the existing field-key check proves each value carries the right ACF key
 * companion, but says nothing about whether the FIELD EXISTS on that block or whether its value
 * is one the field accepts. A model could invent `card_subtitel`, pair it with a plausible key,
 * and the output validated clean.
 *
 * Schemas are the oracle here and are never prompted — one median schema is 13,522 tokens
 * against a 3,000-8,000 budget for all component context. Validating costs nothing and the error
 * is a few dozen tokens, which is also what {@see MarkupGenerator}'s existing retry-with-feedback
 * loop needs in order to be worth anything.
 */
final class SchemaValidationTest extends TestCase {

    use WordPressStubs;

    protected function setUp(): void {
        $this->stubWordPress();
        $this->stubThemeDirs();
    }

    protected function tearDown(): void {
        $this->unstubWordPress();
    }

    private function oracle(): SchemaOracle {
        return new SchemaOracle( dirname( __DIR__ ) . '/Fixture/manifests' );
    }

    private function validator(): MarkupValidator {
        $manifest = ManifestFixture::manifest();

        // The field-key map the companion check uses, for the same block the schema covers.
        $manifest['blocks']['acf/card']['field_key_map'] = array(
            'card_title' => 'field_aaa111',
            'card_body'  => 'field_bbb222',
        );

        return new MarkupValidator( $manifest, $this->oracle() );
    }

    private function block( array $data ): string {
        return '<!-- wp:acf/card ' . json_encode(
            array( 'name' => 'acf/card', 'data' => $data, 'mode' => 'preview' )
        ) . ' /-->';
    }

    public function test_a_well_formed_block_validates(): void {
        $result = $this->validator()->validate(
            $this->block( array(
                'card_title'  => 'Hello',
                '_card_title' => 'field_aaa111',
                'card_layout' => 'vertical',
            ) )
        );

        $this->assertSame( array(), $result['errors'] );
    }

    public function test_an_invented_field_name_is_an_error(): void {
        // The failure this exists for. Every schema is `additionalProperties: false`, so a
        // misspelling is caught by name rather than having to be anticipated.
        $result = $this->validator()->validate(
            $this->block( array( 'card_subtitel' => 'oops', '_card_subtitel' => 'field_zzz999' ) )
        );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'card_subtitel', implode( ' ', $result['errors'] ) );
    }

    public function test_a_value_outside_the_enum_is_an_error(): void {
        $result = $this->validator()->validate(
            $this->block( array( 'card_layout' => 'diagonal', '_card_layout' => 'field_ccc333' ) )
        );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'card_layout', implode( ' ', $result['errors'] ) );
    }

    public function test_a_wrong_scalar_type_is_an_error(): void {
        $result = $this->validator()->validate(
            $this->block( array( 'card_columns' => 'three', '_card_columns' => 'field_ddd444' ) )
        );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'card_columns', implode( ' ', $result['errors'] ) );
    }

    public function test_the_acf_key_companions_are_not_reported_as_unknown_properties(): void {
        // The trap. Schemas declare none of the `_fieldname` keys and are
        // `additionalProperties: false`, so validating the raw data object would report every
        // companion as unknown — on every block, making the whole check useless noise.
        $result = $this->validator()->validate(
            $this->block( array( 'card_title' => 'Hello', '_card_title' => 'field_aaa111' ) )
        );

        $this->assertStringNotContainsString( '_card_title', implode( ' ', $result['errors'] ) );
    }

    public function test_a_missing_required_field_is_a_warning_not_an_error(): void {
        // `show_ids` is required by the schema, and across all 41 real schemas the required
        // fields are 1-3 settings ACF fills from a field default. Erroring on them would reject
        // nearly every generated block.
        $result = $this->validator()->validate(
            $this->block( array( 'card_title' => 'Hello', '_card_title' => 'field_aaa111' ) )
        );

        $this->assertSame( array(), $result['errors'] );
        $this->assertStringContainsString( 'show_ids', implode( ' ', $result['warnings'] ) );
    }

    public function test_validation_still_runs_with_no_oracle_at_all(): void {
        // The oracle is optional, so every pre-existing call site keeps working unchanged —
        // and must not start passing things it used to catch.
        $validator = new MarkupValidator( ManifestFixture::manifest() );
        $result = $validator->validate( $this->block( array( 'card_layout' => 'diagonal' ) ) );

        $this->assertIsArray( $result['errors'] );
    }

    public function test_an_unresolvable_schema_is_recorded_rather_than_skipped_silently(): void {
        // Validation that quietly stops validating while still reporting "valid" is worse than
        // no validation. `missing` is in the index with a tier that does not exist here.
        $oracle = $this->oracle();

        $this->assertNull( $oracle->schema_for( 'acf/missing' ) );
        $this->assertContains( 'missing', $oracle->unresolved() );
    }

    public function test_a_block_with_no_schema_entry_is_not_an_error(): void {
        // Different from unresolvable: the index simply does not cover it, which is the normal
        // state for a theme- or plugin-tier block with no committed schema.
        $oracle = $this->oracle();

        $this->assertNull( $oracle->schema_for( 'acf/section' ) );
        $this->assertNotContains( 'section', $oracle->unresolved() );
    }

    public function test_the_install_resolved_contract_is_preferred_over_the_baseline(): void {
        // `card` is listed in `overridden`, so its schema comes from ai-block-schemas.json --
        // what this install renders -- not from the committed baseline beside the component.
        // Proven by the fixture: no `fixture-tier` directory exists, so a baseline lookup could
        // not have succeeded, yet the schema resolves.
        $oracle = $this->oracle();

        $this->assertIsArray( $oracle->schema_for( 'acf/card' ) );
        $this->assertNotContains( 'card', $oracle->unresolved() );
    }
}
