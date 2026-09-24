<?php

declare(strict_types=1);

namespace Tamar\Transport;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Transport\Interfaces\HttpTransport;

/**
 * Lets a panel session outlive the WordPress request that logged in.
 *
 * Beacon's WpHttpTransport keeps its cookie jar for exactly one
 * instance — one WordPress request — by design, so on its own every
 * request that touches forwarding logs in to the panel afresh. A login
 * on every page load is the pattern a bot detector looks for. This
 * decorator supplies the other half: it can be *seeded* with the
 * cookies an earlier request stored, sends them with every request,
 * and reports the combined jar back so the caller can store it again.
 *
 * Seeding goes through WordPress's `http_request_args` filter, added
 * around each call and removed straight after, because the wrapped
 * transport's jar is private and Beacon is a library shared with
 * Trusted: widening its API would need both plugins' vendored copies
 * to move together. The filter only touches the one URL being
 * requested, so an unrelated HTTP call made from inside that request
 * never sees the panel's cookies. A seeded cookie is only added when
 * the wrapped jar has none of that name — anything the panel set
 * during this request is newer and wins.
 *
 * Reading the jar back needs the wrapped transport's `cookies()`
 * accessor, which Beacon's WpHttpTransport has. For anything else
 * {@see canResume()} is false, and the caller logs in every request as
 * it always did.
 */
final class ResumableSessionTransport implements HttpTransport
{
    /** @var array<string,string> */
    private array $seeded = [];

    /** The URL of the request in flight, so the filter touches nothing else. */
    private ?string $inFlightUrl = null;

    public function __construct(private readonly HttpTransport $inner)
    {
    }

    public function canResume(): bool
    {
        return method_exists($this->inner, 'cookies');
    }

    /**
     * Cookies from an earlier request, sent with every request from now on.
     *
     * @param array<string,string> $cookies Name → value.
     */
    public function seed(array $cookies): void
    {
        $this->seeded = $cookies;
    }

    /**
     * Stop sending the seeded cookies — the panel no longer accepts them.
     * Whatever the panel has set during this request is kept.
     */
    public function forgetSeeded(): void
    {
        $this->seeded = [];
    }

    /**
     * The session as the next request would send it: the seeded cookies
     * overlaid with whatever the panel has set since.
     *
     * @return array<string,string> Name → value.
     */
    public function cookies(): array
    {
        $live = [];
        if (method_exists($this->inner, 'cookies')) {
            $jar = $this->inner->cookies();
            if (is_array($jar)) {
                foreach ($jar as $name => $value) {
                    if (is_string($name) && is_scalar($value)) {
                        $live[$name] = (string) $value;
                    }
                }
            }
        }
        return array_merge($this->seeded, $live);
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        if ($this->seeded === []) {
            return $this->inner->request($method, $url, $headers, $body);
        }

        $this->inFlightUrl = $url;
        add_filter('http_request_args', [$this, 'addSeededCookies'], 10, 2);
        try {
            return $this->inner->request($method, $url, $headers, $body);
        } finally {
            remove_filter('http_request_args', [$this, 'addSeededCookies'], 10);
            $this->inFlightUrl = null;
        }
    }

    /**
     * `http_request_args` callback: add each seeded cookie the wrapped
     * jar does not already carry. Public only because WordPress calls it.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function addSeededCookies(array $args, string $url = ''): array
    {
        if ($this->inFlightUrl === null || $url !== $this->inFlightUrl) {
            return $args;
        }

        $cookies = is_array($args['cookies'] ?? null) ? $args['cookies'] : [];
        $present = [];
        foreach ($cookies as $key => $cookie) {
            $present[] = $cookie instanceof \WP_Http_Cookie ? (string) $cookie->name : (string) $key;
        }

        foreach ($this->seeded as $name => $value) {
            if (!in_array($name, $present, true)) {
                $cookies[] = new \WP_Http_Cookie(['name' => $name, 'value' => $value]);
            }
        }

        $args['cookies'] = $cookies;
        return $args;
    }
}
