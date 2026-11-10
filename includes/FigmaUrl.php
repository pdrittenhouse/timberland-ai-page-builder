<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A validated Figma reference, parsed from a user-supplied URL.
 *
 * **This class exists to be the thing that runs BEFORE any fetch.** A user-supplied URL plus a
 * server-side fetch is textbook SSRF: paste `http://169.254.169.254/latest/meta-data/` or
 * `http://localhost:9200/_cat/indices` and the server retrieves it from inside the network
 * perimeter and hands back the body. On a multi-tenant install that is a cross-tenant read, which
 * is why FG2 marks it a requirement rather than a nicety.
 *
 * The defence is an **allowlist of hosts, checked before anything is opened** -- not a denylist of
 * bad ones. Denylists lose to `0177.0.0.1`, `[::1]`, `127.1`, decimal IPs, DNS names that resolve
 * to loopback, and redirects. An allowlist of two hostnames has none of those edge cases.
 * `FigmaClient` additionally fetches through `wp_safe_remote_get()`, so WordPress's own private-IP
 * and redirect checks apply as a second layer -- but this one comes first and is the one that must
 * not be removed.
 *
 * Construction is private: the only way to get one is {@see self::parse()}, so an unvalidated
 * instance cannot exist.
 */
final class FigmaUrl
{
    /**
     * Hosts a design URL may name.
     *
     * Exact matches only. No wildcard: `figma.com.evil.test` matches a naive suffix check, and
     * `*.figma.com` would admit any subdomain Figma ever points anywhere.
     */
    private const ALLOWED_HOSTS = ['figma.com', 'www.figma.com'];

    private string $file_key;

    private string $node_id;

    private function __construct(string $file_key, string $node_id)
    {
        $this->file_key = $file_key;
        $this->node_id = $node_id;
    }

    /**
     * Parse and validate, or explain why not.
     *
     * Returns a WP_Error rather than throwing, so a REST handler can surface the reason to the
     * person who pasted the URL. Every failure path returns before any network access.
     *
     * @return self|\WP_Error
     */
    public static function parse(string $url)
    {
        $url = trim($url);

        if ($url === '') {
            return new \WP_Error('figma_url_empty', 'No Figma URL was provided.');
        }

        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return new \WP_Error('figma_url_unparseable', 'That does not look like a URL.');
        }

        // Scheme first: `file://`, `gopher://` and friends reach places http clients should not,
        // and a missing scheme would otherwise be treated as a relative path.
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'https') {
            return new \WP_Error(
                'figma_url_scheme',
                'A Figma URL must use https. Copy the link from Figma\'s Share dialog.'
            );
        }

        // Credentials in the URL are a redirect/confusion vector and Figma never issues them.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return new \WP_Error('figma_url_credentials', 'A Figma URL must not contain credentials.');
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return new \WP_Error('figma_url_port', 'A Figma URL must not specify a port.');
        }

        $host = strtolower((string) $parts['host']);

        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            // Deliberately does not echo the host back into the page. The message says what is
            // allowed, not what was attempted, so the response cannot be used to probe.
            return new \WP_Error(
                'figma_url_host',
                'Only figma.com links are accepted.'
            );
        }

        $path = (string) ($parts['path'] ?? '');

        // `/file/KEY/...`, `/design/KEY/...`, `/proto/KEY/...` are all in circulation.
        if (!preg_match('#^/(?:file|design|proto)/([A-Za-z0-9]{10,64})(?:/|$)#', $path, $m)) {
            return new \WP_Error(
                'figma_url_no_file_key',
                'That Figma link does not contain a file key. Use the link from Figma\'s Share dialog.'
            );
        }

        $file_key = $m[1];

        parse_str((string) ($parts['query'] ?? ''), $query);
        $node_id = self::normalise_node_id((string) ($query['node-id'] ?? ''));

        if ($node_id === '') {
            return new \WP_Error(
                'figma_url_no_node',
                'That Figma link does not name a frame. In Figma, right-click the frame and choose '
                . '"Copy link to selection".'
            );
        }

        return new self($file_key, $node_id);
    }

    /**
     * The share URL and the API disagree about the separator, and nothing tells you.
     *
     * A share URL carries `?node-id=2503-3665`; the REST API wants `2503:3665`. Passing the dashed
     * form through returns **200 with an empty `nodes` object** -- not an error, just nothing, so
     * it reads as "the frame is empty" rather than "you asked wrong".
     */
    public static function normalise_node_id(string $node_id): string
    {
        $node_id = trim($node_id);

        if ($node_id === '') {
            return '';
        }

        // Only the FIRST dash separates the pair; ids are `<int>-<int>`.
        if (!preg_match('/^(\d+)[-:](\d+)$/', $node_id, $m)) {
            return '';
        }

        return $m[1] . ':' . $m[2];
    }

    public function file_key(): string
    {
        return $this->file_key;
    }

    /** API form, colon-separated. */
    public function node_id(): string
    {
        return $this->node_id;
    }
}
