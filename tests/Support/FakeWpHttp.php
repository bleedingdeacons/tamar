<?php

declare(strict_types=1);

namespace Tamar\Tests\Support;

/**
 * Scriptable in-memory backend for the WP HTTP API shims defined in
 * tests/bootstrap.php.
 *
 * The shimmed `wp_remote_request()` delegates here: each call pops the
 * next queued response and records the URL + args it was handed, so a
 * test can assert on *what the transport sent* (cookies replayed,
 * headers merged, body attached) as well as on what it returned.
 *
 * A queued response is either:
 *   - an array shaped like a WP HTTP response:
 *       [
 *         'response' => ['code' => 200],
 *         'body'     => '…',
 *         'headers'  => ['content-type' => 'text/html'],  // array or getAll() object
 *         'cookies'  => [ new \WP_Http_Cookie(['name'=>…,'value'=>…]) ],
 *       ]
 *   - a \WP_Error, to simulate a network-layer failure.
 *
 * Call reset() in setUp() so state never leaks between tests.
 */
final class FakeWpHttp
{
    /** @var array<int,array<string,mixed>|\WP_Error> */
    public static array $queue = [];

    /** @var array<int,array{url:string,args:array<string,mixed>}> */
    public static array $sent = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$sent = [];
    }

    /**
     * Queue the next response wp_remote_request() should return.
     *
     * @param array<string,mixed>|\WP_Error $response
     */
    public static function push($response): void
    {
        self::$queue[] = $response;
    }

    /**
     * Convenience: queue a normal HTTP response.
     *
     * @param array<string,string>|object   $headers
     * @param array<int,\WP_Http_Cookie>    $cookies
     * @return void
     */
    public static function pushResponse(int $status, string $body = '', $headers = [], array $cookies = []): void
    {
        self::$queue[] = [
            'response' => ['code' => $status],
            'body'     => $body,
            'headers'  => $headers,
            'cookies'  => $cookies,
        ];
    }

    /**
     * The shimmed wp_remote_request() entry point. Records the call and
     * returns the next scripted response, or a WP_Error if the queue is
     * empty (an unscripted call is almost always a test bug, and a
     * WP_Error surfaces it loudly via the transport's exception path).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function dispatch(string $url, array $args)
    {
        self::$sent[] = ['url' => $url, 'args' => $args];

        if (self::$queue === []) {
            return new \WP_Error('fake_http_no_response', 'FakeWpHttp: no scripted response for ' . $url);
        }

        return array_shift(self::$queue);
    }

    /**
     * The args the Nth request (0-based) was sent with.
     *
     * @return array<string,mixed>
     */
    public static function sentArgs(int $index): array
    {
        return self::$sent[$index]['args'] ?? [];
    }

    public static function callCount(): int
    {
        return count(self::$sent);
    }
}
