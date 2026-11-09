<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Fixture;

/**
 * A representative plugin manifest, shaped exactly like `ManifestBuilder::build()` returns.
 *
 * Hand-built rather than captured from a live install, deliberately. A captured manifest would
 * be ~48 blocks of real content, which makes a characterisation diff unreadable and couples the
 * tests to whichever site happened to be running. This carries one block of each shape the
 * prompt builders branch on instead:
 *
 *   - a container that accepts children (`is_container`, appears in `containers`)
 *   - a leaf with fields (`field_key_map` / `field_type_map` populated)
 *   - a child restricted to one parent (`parent`), which is what `parent_only` means
 *   - a block with `usage_notes`, the slot UC1/UC2's `best_for`/`avoid_when` fills
 *
 * The per-block keys are taken from `ManifestBuilder::collect_blocks()` verbatim, including
 * `usage_notes`, which that method currently hardcodes to `''` for every block. The fixture
 * populates it on exactly one block so a test can show that the prompt HAS a slot for the
 * curated prose — and that nothing fills it today.
 */
final class ManifestFixture {

    /**
     * @param  array<string, mixed> $overrides merged over the defaults, top level only.
     * @return array<string, mixed>
     */
    public static function manifest( array $overrides = array() ): array {
        $blocks = array(
            'acf/section' => self::block(
                'acf/section',
                'Section',
                'A full-width section wrapper.',
                array( 'container', 'row', 'wrapper' ),
                array( 'is_container' => true )
            ),
            'acf/card' => self::block(
                'acf/card',
                'Card',
                'A card with a title, body and optional image.',
                array( 'teaser', 'tile' ),
                array(
                    'field_key_map'  => array(
                        'card_title' => 'field_aaa111',
                        'card_body'  => 'field_bbb222',
                    ),
                    'field_type_map' => array(
                        'card_title' => 'text',
                        'card_body'  => 'wysiwyg',
                    ),
                    'field_count'    => 2,
                    // The only block carrying curated prose. See the class docblock.
                    'usage_notes'    => 'Best for: a short teaser linking to a longer page.',
                )
            ),
            'acf/accordion-item' => self::block(
                'acf/accordion-item',
                'Accordion Item',
                'One panel inside an accordion.',
                array( 'panel' ),
                array( 'parent' => array( 'acf/accordion' ) )
            ),
        );

        $manifest = array(
            'version'       => 'test',
            'generated_at'  => '2026-01-01T00:00:00+00:00',
            'blocks'        => $blocks,
            'layouts'       => array(),
            'patterns'      => array(),
            'post_types'    => array(
                'page' => array(
                    'name'       => 'page',
                    'label'      => 'Pages',
                    'supports'   => array( 'title', 'editor' ),
                    'taxonomies' => array(),
                ),
            ),
            'taxonomies'    => array(),
            'nesting_rules' => array(
                'containers'  => array( 'acf/section' ),
                'leaf_blocks' => array( 'acf/card' ),
                'children_of' => array( 'acf/accordion' => array( 'acf/accordion-item' ) ),
            ),
        );

        return array_merge( $manifest, $overrides );
    }

    /**
     * @param  array<int, string>   $keywords
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function block(
        string $name,
        string $title,
        string $description,
        array $keywords = array(),
        array $overrides = array()
    ): array {
        return array_merge(
            array(
                'name'             => $name,
                'title'            => $title,
                'description'      => $description,
                'category'         => 'timberland',
                'keywords'         => $keywords,
                'is_container'     => false,
                'parent'           => null,
                'provides_context' => false,
                'uses_context'     => false,
                'supports'         => array(),
                'field_key_map'    => array(),
                'field_type_map'   => array(),
                'field_count'      => 0,
                'usage_notes'      => '',
            ),
            $overrides
        );
    }
}
