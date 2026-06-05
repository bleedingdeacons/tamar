<?php

declare(strict_types=1);

namespace Tamar\Transport;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\TransportException;

/**
 * WordPress HTTP API implementation of Beacon's {@see HttpTransport}.
 *
 * Tamar's upstream has no API — it's a session-cookie-authenticated
 * HTML admin. A single logical operation is therefore a *sequence* of
 * requests against the same host:
 *
 *   POST /phonedivert/login/         → sets a session cookie
 *   GET  /phonedivert/huntgroup?…    → must carry that cookie
 *   POST /phonedivert/huntgroup/update → same cookie again
 *
 * The WP HTTP API does not keep a cookie jar across `wp_remote_*`
 * calls, so this transport maintains one itself: every response's
 * `Set-Cookie` headers are parsed (by WP, via
 * `wp_remote_retrieve_cookies()`) into {@see \WP_Http_Cookie} objects
 * and merged into an in-instance jar keyed by cookie name; every
 * subsequent request replays the jar via the `cookies` arg. Because
 * the container builds a fresh transport per request (see
 * TamarServiceProvider), the jar's lifetime is exactly one WordPress
 * request — login state never leaks between page loads or between
 * users.
 *
 * We use WP's HTTP API rather than a raw cURL handle deliberately: it
 * honours host-level proxy configuration, the site's CA bundle, and
 * any `http_request_args` filters an operator relies on.
 *
 * Failure-mode contract (from {@see HttpTransport}):
 *  - Network-layer failures (timeout, DNS, TLS handshake, connection
 *    refused) surface as a {@see \WP_Error} from `wp_remote_request()`
 *    and are re-thrown as {@see TransportException}.
 *  - HTTP status codes — including 3xx, 4xx and 5xx — are NOT errors
 *    here. They come back in the response array and the driver decides
 *    what to do with them.
 *
 * Redirects ARE followed (WP default depth). A login POST that
 * answers 302→dashboard still ends up establishing the session, and
 * cookies set anywhere along the redirect chain are captured from the
 * final response. A driver that needs to inspect a raw 3xx can
 * construct the transport with `maxRedirects: 0`.
 */
final class WpHttpTransport implements HttpTransport
{
    /**
     * Session cookie jar, keyed by cookie name so a later Set-Cookie
     * for the same name overrides the earlier value.
     *
     * @var array<string,\WP_Http_Cookie>
     */
    private array $cookies = [];

    public function __construct(
        private readonly bool $verifyTls = true,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxRedirects = 5,
        private readonly string $userAgent = 'Tamar/Beacon (WordPress call-forwarding driver)',
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     *
     * @throws TransportException
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $args = [
            'method'      => strtoupper($method),
            'timeout'     => $this->timeoutSeconds,
            'redirection' => $this->maxRedirects,
            'sslverify'   => $this->verifyTls,
            'httpversion' => '1.1',
            'user-agent'  => $this->userAgent,
            'headers'     => $this->prepareRequestHeaders($headers),
            'cookies'     => array_values($this->cookies),
        ];

        // Only attach a body when there is one. A GET/HEAD/DELETE with
        // an empty string body is the common case and some servers
        // behave oddly if handed an empty entity body.
        if ($body !== '') {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Network-layer failure — DNS, TLS, timeout, refused. The
            // WP_Error code (e.g. 'http_request_failed') is preserved
            // in the message so the operator gets a usable diagnostic.
            throw new TransportException(
                'HTTP request to ' . $url . ' failed: ' . $response->get_error_message()
            );
        }

        // Capture any cookies this response set BEFORE returning, so
        // the next call in the sequence is authenticated.
        $this->captureCookies($response);

        return [
            'status'  => (int) wp_remote_retrieve_response_code($response),
            'body'    => (string) wp_remote_retrieve_body($response),
            'headers' => $this->normaliseResponseHeaders($response),
        ];
    }

    /**
     * Read-only view of the current jar as name → value pairs. Exposed
     * for diagnostics and tests — the request flow uses the
     * {@see \WP_Http_Cookie} objects directly so path/domain/expiry are
     * preserved.
     *
     * @return array<string,string>
     */
    public function cookies(): array
    {
        $out = [];
        foreach ($this->cookies as $name => $cookie) {
            $out[$name] = (string) $cookie->value;
        }
        return $out;
    }

    // -- internals --------------------------------------------------------

    /**
     * Merge caller headers over our defaults. We supply an `Accept`
     * default so the upstream serves HTML rather than negotiating
     * something exotic, but the caller wins on any header it sets
     * (the driver sets `Content-Type` for its form POSTs, for
     * instance).
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private function prepareRequestHeaders(array $headers): array
    {
        $defaults = [
            'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
        ];

        // Caller headers take precedence. Case differences (Accept vs
        // accept) are harmless — WP's HTTP layer treats header names
        // case-insensitively on send.
        return array_merge($defaults, $headers);
    }

    /**
     * Parse this response's Set-Cookie headers (via WP, which handles
     * the multi-cookie and attribute-parsing edge cases) and fold them
     * into the jar. A cookie with an empty name is ignored.
     *
     * @param array<string,mixed>|\WP_Error $response
     */
    private function captureCookies($response): void
    {
        $cookies = wp_remote_retrieve_cookies($response);
        if (!is_array($cookies)) {
            return;
        }
        foreach ($cookies as $cookie) {
            if (!$cookie instanceof \WP_Http_Cookie) {
                continue;
            }
            $name = (string) $cookie->name;
            if ($name === '') {
                continue;
            }
            $this->cookies[$name] = $cookie;
        }
    }

    /**
     * Coerce WP's case-insensitive header dictionary into the plain
     * `array<string,string>` the contract promises, with lower-cased
     * keys. Multi-value headers (which WP may hand back as an array)
     * are joined with ", " so the value stays a string; cookie
     * continuity does not depend on this map — it is informational for
     * the caller.
     *
     * @param array<string,mixed>|\WP_Error $response
     * @return array<string,string>
     */
    private function normaliseResponseHeaders($response): array
    {
        $headers = wp_remote_retrieve_headers($response);

        // wp_remote_retrieve_headers() returns a case-insensitive
        // dictionary object on success and '' if headers are absent.
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        if (!is_array($headers)) {
            return [];
        }

        $out = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            $out[$key] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        return $out;
    }
}
