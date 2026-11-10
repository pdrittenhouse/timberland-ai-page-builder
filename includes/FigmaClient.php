<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Talks to Figma: the node tree for structure, a rendered preview for pixels.
 *
 * Four details that are easy to get wrong and silent when you do -- all four are FG2:
 *
 * 1. **node-id separator.** Handled in {@see FigmaUrl::normalise_node_id()}. The dashed form the
 *    share URL carries returns 200 with an empty `nodes` object, so the mistake looks like an
 *    empty frame.
 * 2. **Host validation before any fetch.** {@see FigmaUrl::parse()} does it, and this class will
 *    not accept a raw string -- only an already-parsed {@see FigmaUrl}. That is the type system
 *    carrying the security requirement so a future caller cannot skip it.
 * 3. **scale=0.5 and a byte cap.** A full-resolution board export is tens of megabytes and the
 *    model cannot use the extra pixels. The cap is enforced on the RESPONSE, not just requested.
 * 4. **The image request carries no token.** The preview comes from a signed S3 URL; the
 *    signature is the authorisation. Sending the PAT there would hand an account-wide credential
 *    to a third-party host that has no use for it.
 */
class FigmaClient
{
    private const API_HOST = 'api.figma.com';

    /**
     * Hosts a rendered preview may be served from.
     *
     * Figma returns an S3 URL, so this is a second, narrower allowlist for a URL that came from
     * the API rather than from the user. Still checked: an API response is not a trusted input
     * when the thing it steers is a server-side fetch.
     */
    private const IMAGE_HOST_SUFFIXES = ['.figma.com', '.s3.amazonaws.com', '.s3-us-west-2.amazonaws.com'];

    /** Half scale, as FG2 specifies. */
    private const PREVIEW_SCALE = '0.5';

    /** 8 MB. A 0.5-scale frame export is far below this; a board export is not. */
    private const MAX_IMAGE_BYTES = 8388608;

    private string $token;

    private int $timeout;

    public function __construct(?string $token = null, int $timeout = 20)
    {
        $this->token = $token ?? FigmaCredentials::get();
        $this->timeout = $timeout;
    }

    /**
     * The node tree for one frame.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function fetch_node(FigmaUrl $ref)
    {
        if ($this->token === '') {
            return new \WP_Error('figma_no_token', 'No Figma access token is configured.');
        }

        $url = sprintf(
            'https://%s/v1/files/%s/nodes?ids=%s',
            self::API_HOST,
            rawurlencode($ref->file_key()),
            rawurlencode($ref->node_id())
        );

        $response = wp_safe_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['X-Figma-Token' => $this->token],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 429) {
            // Distinguished from every other non-200 because the operator's correct response is
            // different: wait, do not re-enter the token. Measured -- five inspections of one file
            // inside a minute is enough to hit it, so this is the ordinary path during a review
            // session, not an edge case. Folded into `figma_api_error` it reads as a dead token,
            // which sends somebody to regenerate a PAT that was working fine.
            return new \WP_Error(
                'figma_rate_limited',
                'Figma is rate-limiting this token. Wait about a minute and try again '
                . '— the token itself is fine.'
            );
        }

        if ($code === 403 || $code === 401) {
            return new \WP_Error(
                'figma_forbidden',
                sprintf(
                    'Figma refused the request (HTTP %d). The access token may have expired, or it '
                    . 'may not have access to this file.',
                    $code
                )
            );
        }

        if ($code !== 200) {
            return new \WP_Error('figma_api_error', sprintf('Figma returned HTTP %d.', $code));
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($decoded)) {
            return new \WP_Error('figma_api_bad_json', 'Figma returned a response that could not be read.');
        }

        // An empty `nodes` map is the signature of a mis-formatted node id -- and it arrives as a
        // 200, so it has to be detected here or it reads as an empty frame.
        $nodes = $decoded['nodes'] ?? [];

        if (!is_array($nodes) || $nodes === []) {
            return new \WP_Error(
                'figma_node_not_found',
                'Figma returned no node for that frame. Check the link points at a frame you can access.'
            );
        }

        return $decoded;
    }

    /**
     * A rendered preview of the frame, as raw bytes.
     *
     * Two requests: one to the API (authenticated) for a signed URL, one to that URL
     * (UNauthenticated) for the bytes.
     *
     * @return array{bytes: string, content_type: string, url_host: string}|\WP_Error
     */
    public function fetch_preview(FigmaUrl $ref)
    {
        if ($this->token === '') {
            return new \WP_Error('figma_no_token', 'No Figma access token is configured.');
        }

        $url = sprintf(
            'https://%s/v1/images/%s?ids=%s&scale=%s&format=png',
            self::API_HOST,
            rawurlencode($ref->file_key()),
            rawurlencode($ref->node_id()),
            self::PREVIEW_SCALE
        );

        $response = wp_safe_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['X-Figma-Token' => $this->token],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $images = is_array($decoded) ? ($decoded['images'] ?? []) : [];
        $image_url = is_array($images) ? (string) reset($images) : '';

        if ($image_url === '') {
            return new \WP_Error('figma_no_preview', 'Figma did not return a preview for that frame.');
        }

        return $this->fetch_image_bytes($image_url);
    }

    /**
     * Fetch the signed preview, with NO token and a hard byte cap.
     *
     * @return array{bytes: string, content_type: string, url_host: string}|\WP_Error
     */
    private function fetch_image_bytes(string $image_url)
    {
        $parts = wp_parse_url($image_url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '') {
            return new \WP_Error('figma_image_scheme', 'Figma returned a preview URL that is not https.');
        }

        $allowed = false;

        foreach (self::IMAGE_HOST_SUFFIXES as $suffix) {
            // Anchored at the END and including the leading dot, so `evil-figma.com` and
            // `figma.com.attacker.test` both fail where a naive `str_contains` would pass.
            if (str_ends_with($host, $suffix)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return new \WP_Error('figma_image_host', 'Figma returned a preview URL from an unexpected host.');
        }

        // NO X-Figma-Token here. The signature in the URL is the authorisation, and this host has
        // no business holding an account-wide credential.
        $response = wp_safe_remote_get($image_url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'image/png'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = (string) wp_remote_retrieve_body($response);

        // Enforced on what arrived, not only requested. A Content-Length can lie or be absent,
        // and a cap that trusts it is not a cap.
        if (strlen($body) > self::MAX_IMAGE_BYTES) {
            return new \WP_Error(
                'figma_image_too_large',
                sprintf('The Figma preview exceeded the %d byte limit.', self::MAX_IMAGE_BYTES)
            );
        }

        if ($body === '') {
            return new \WP_Error('figma_image_empty', 'The Figma preview came back empty.');
        }

        return [
            'bytes' => $body,
            'content_type' => (string) wp_remote_retrieve_header($response, 'content-type'),
            'url_host' => $host,
        ];
    }
}
