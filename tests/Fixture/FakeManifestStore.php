<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Fixture;

use TimberlandAIPageBuilder\ManifestStore;

/**
 * A `ManifestStore` that serves a fixture instead of building and caching one.
 *
 * Subclassed rather than mocked because `ManifestStore` takes a concrete `ManifestBuilder`, and
 * a real builder would reach WordPress's block registry and ACF. The override is `get()` only —
 * the one method the generation pipeline calls.
 */
final class FakeManifestStore extends ManifestStore {

    /** @var array<string, mixed> */
    private array $manifest;

    /**
     * @param array<string, mixed>|null $manifest
     */
    public function __construct( ?array $manifest = null ) {
        // Deliberately NOT calling parent::__construct(): it wants a ManifestBuilder, which
        // wants WordPress. Nothing here touches the parent's state.
        $this->manifest = $manifest ?? ManifestFixture::manifest();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array {
        return $this->manifest;
    }
}
