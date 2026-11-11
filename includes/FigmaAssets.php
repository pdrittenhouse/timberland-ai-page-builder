<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bring a Figma frame's own images into the media library, so a generated page can use them.
 *
 * **Why this exists.** The fidelity measure showed a generated Home page with 10 image slots
 * described and 0 set, and that turned out to be *unreachable* rather than ignored: the plan said
 * "image" and gave the model nothing to reference, while the prompt requires a URL, a filename or a
 * description. No amount of prompting fixes that. The asset has to exist locally first.
 *
 * **The original uploads, not a render.** `/v1/images` rasterises a node, which flattens a whole
 * band into one picture; `/v1/files/{key}/images` returns the files a designer actually placed.
 * Measured on the Natural Rose file: 168 pairs, and all 76 `imageRef` values in the node tree
 * resolve. The refs are already in the payload `fetch_node()` returns, so nothing extra is fetched
 * to discover them.
 *
 * **This writes to the media library, which is why it is explicit and idempotent.**
 *
 *  - Keyed on Figma's `imageRef`, stored in attachment meta. Re-importing the same frame finds the
 *    existing attachment instead of making a second copy; a design iterated five times leaves five
 *    attachments only if the designer actually replaced five images.
 *  - Every attachment records the file key and ref it came from, so an operator can see where a
 *    picture came from and delete it deliberately. FG3's provenance rule, applied to pixels.
 *  - Nothing is imported speculatively. `/figma/inspect` lists 17 candidate pages for one file, and
 *    importing on inspection would drop the whole file's imagery into the library before anyone had
 *    chosen a page.
 *
 * Downloads reuse {@see FigmaClient::fetch_image_bytes}, which already enforces https, an anchored
 * host-suffix allowlist, no credential on the S3 host, and a cap on the bytes that ARRIVED rather
 * than on a Content-Length that can lie.
 */
class FigmaAssets
{
    /** Attachment meta: the Figma image this file came from. Also the dedupe key. */
    public const REF_META = '_taipb_figma_image_ref';

    /** Attachment meta: which Figma file it came from, for provenance. */
    public const FILE_META = '_taipb_figma_file_key';

    /**
     * How many assets one import may bring in.
     *
     * A page band can reference a dozen images and a whole file 168. This is a per-call ceiling so
     * a single confirm cannot fill the library; the cap being hit is reported rather than silently
     * truncating, because a page missing half its imagery with no explanation is the failure this
     * class exists to end.
     */
    public const MAX_PER_IMPORT = 40;

    /**
     * Byte cap for an imported asset, deliberately higher than the preview cap.
     *
     * A preview is a half-scale render this plugin requests; an asset is whatever the designer
     * uploaded. Measured: 6 of the 24 images on the Natural Rose Home page are over the preview's
     * 8 MB, and a skipped hero photograph is the failure importing assets exists to fix. 32 MB
     * covers a full-bleed photograph while still refusing something that is not a web asset at all,
     * and WordPress scales large images down on ingest anyway.
     */
    public const MAX_ASSET_BYTES = 33554432;

    /** @var array<int, string> mime types WordPress will be asked to accept */
    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];

    private FigmaClient $client;

    public function __construct(?FigmaClient $client = null)
    {
        $this->client = $client ?? new FigmaClient();
    }

    /**
     * Import the given refs, returning `imageRef => attachment ID`.
     *
     * @param array<int, string> $refs
     *
     * @return array{
     *     imported: array<string, int>,
     *     reused: array<string, int>,
     *     failed: array<string, string>,
     *     truncated: int
     * }|\WP_Error
     */
    public function import(FigmaUrl $ref, array $refs)
    {
        $refs = array_values(array_unique(array_filter($refs, 'is_string')));

        if ($refs === []) {
            return ['imported' => [], 'reused' => [], 'failed' => [], 'truncated' => 0];
        }

        $truncated = 0;

        if (count($refs) > self::MAX_PER_IMPORT) {
            $truncated = count($refs) - self::MAX_PER_IMPORT;
            $refs = array_slice($refs, 0, self::MAX_PER_IMPORT);
        }

        $reused = [];
        $wanted = [];

        foreach ($refs as $image_ref) {
            $existing = self::find_existing($image_ref);

            if (null !== $existing) {
                $reused[$image_ref] = $existing;
                continue;
            }

            $wanted[] = $image_ref;
        }

        // Nothing new: do not spend a Figma call. The URL map is one request per FILE, and on a
        // cost-based limit measured in days that is worth not making.
        if ($wanted === []) {
            return ['imported' => [], 'reused' => $reused, 'failed' => [], 'truncated' => $truncated];
        }

        $urls = $this->client->fetch_asset_urls($ref);

        if (is_wp_error($urls)) {
            return $urls;
        }

        $imported = [];
        $failed = [];

        foreach ($wanted as $image_ref) {
            if (!isset($urls[$image_ref])) {
                // A ref in the node tree with no entry in the file's image list. Reported, not
                // skipped: it means the design has a picture this page cannot get.
                $failed[$image_ref] = 'Figma did not return a URL for this image.';
                continue;
            }

            $id = $this->sideload($ref, $image_ref, (string) $urls[$image_ref]);

            if (is_wp_error($id)) {
                $failed[$image_ref] = $id->get_error_message();
                continue;
            }

            $imported[$image_ref] = $id;
        }

        return [
            'imported' => $imported,
            'reused' => $reused,
            'failed' => $failed,
            'truncated' => $truncated,
        ];
    }

    /**
     * An attachment already imported for this ref, or null.
     *
     * Queried by meta rather than by filename: Figma's refs are content hashes, so the same picture
     * placed twice is one ref, while two pictures that happen to share a name are two.
     */
    private static function find_existing(string $image_ref): ?int
    {
        $found = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => self::REF_META,
            'meta_value' => $image_ref,
        ]);

        return $found !== [] ? (int) $found[0] : null;
    }

    /**
     * Download one asset and register it as an attachment.
     *
     * @return int|\WP_Error attachment ID
     */
    private function sideload(FigmaUrl $ref, string $image_ref, string $url)
    {
        $fetched = $this->client->fetch_image_bytes($url, self::MAX_ASSET_BYTES);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        $mime = strtolower(trim(explode(';', $fetched['content_type'])[0]));

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            // What the SERVER said it sent, checked before anything is written. A download whose
            // type we do not recognise does not become a file on disk first and a question later.
            return new \WP_Error(
                'figma_asset_type',
                sprintf('Figma returned an unexpected content type (%s).', '' !== $mime ? $mime : 'none')
            );
        }

        $extension = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ][$mime];

        // Named so an operator can see what it is and where it came from in the media library.
        $filename = sprintf('figma-%s-%s.%s', substr($ref->file_key(), 0, 8), substr($image_ref, 0, 12), $extension);

        $upload = wp_upload_bits($filename, null, $fetched['bytes']);

        if (!empty($upload['error'])) {
            return new \WP_Error('figma_asset_write', (string) $upload['error']);
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $mime,
                'post_title' => sprintf('Figma %s', substr($image_ref, 0, 12)),
                'post_content' => '',
                'post_status' => 'inherit',
            ],
            $upload['file']
        );

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        if (0 === $attachment_id) {
            return new \WP_Error('figma_asset_attach', 'WordPress did not create an attachment.');
        }

        // Guarded rather than unconditional, which is the standard pattern and also the honest one:
        // on a REST request this file is often already loaded, and requiring it blind made every
        // unit test of this class fail on a missing wp-admin path rather than on its behaviour.
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata($attachment_id, $upload['file'])
        );

        // Provenance, and the dedupe key. Written AFTER the attachment exists, so a half-written
        // import cannot be found and reused as though it were complete.
        update_post_meta($attachment_id, self::REF_META, $image_ref);
        update_post_meta($attachment_id, self::FILE_META, $ref->file_key());

        return (int) $attachment_id;
    }
}
