<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Transport\Interfaces\HttpTransport;
use Brain\Monkey\Functions;
use Tamar\Forwarding\PanelSessionStore;
use Tamar\Transport\ResumableSessionTransport;

/*
 * The two halves of a panel session outliving its WordPress request:
 * the transport decorator that sends a stored session, and the store
 * that keeps one. The service's use of both is covered in
 * HuntgroupCallForwardingServiceTest.
 */

/**
 * A transport with Beacon's `cookies()` accessor, recording whether the
 * seeding filter was in place while each request ran.
 */
final class JarTransport implements HttpTransport
{
    /** @var list<bool> */
    public array $filterSeen = [];

    /** @param array<string,string> $jar */
    public function __construct(public array $jar = [])
    {
    }

    /** @return array<string,string> */
    public function cookies(): array
    {
        return $this->jar;
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $this->filterSeen[] = has_filter('http_request_args') !== false;
        return ['status' => 200, 'body' => '', 'headers' => []];
    }
}

/**
 * A transport without the accessor, like any non-Beacon one.
 */
final class OpaqueTransport implements HttpTransport
{
    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        return ['status' => 200, 'body' => '', 'headers' => []];
    }
}

// -- ResumableSessionTransport ------------------------------------------

it('can resume only over a transport that reports its cookies', function () {
    expect((new ResumableSessionTransport(new JarTransport()))->canResume())->toBeTrue()
        ->and((new ResumableSessionTransport(new OpaqueTransport()))->canResume())->toBeFalse();
});

it('reports the seeded cookies overlaid with what the panel has set since', function () {
    $transport = new ResumableSessionTransport(new JarTransport(['PHPSESSID' => 'new']));
    $transport->seed(['PHPSESSID' => 'old', 'loginsession' => 'kept']);

    expect($transport->cookies())->toBe(['PHPSESSID' => 'new', 'loginsession' => 'kept']);

    $transport->forgetSeeded();
    expect($transport->cookies())->toBe(['PHPSESSID' => 'new']);
});

it('adds the seeding filter only around a request that has something to send', function () {
    $inner = new JarTransport();
    $transport = new ResumableSessionTransport($inner);

    $transport->request('GET', 'https://panel.example/a');
    $transport->seed(['PHPSESSID' => 'stored']);
    $transport->request('GET', 'https://panel.example/b');

    expect($inner->filterSeen)->toBe([false, true])
        ->and(has_filter('http_request_args'))->toBeFalse();
});

it('adds each seeded cookie the jar does not already carry', function () {
    $inner = new class implements HttpTransport {
        /** @var array<string,mixed>|null */
        public ?array $args = null;

        public function cookies(): array
        {
            return [];
        }

        public function request(string $method, string $url, array $headers = [], string $body = ''): array
        {
            // What WordPress would do inside wp_remote_request().
            $this->args = $GLOBALS['tamarSeedingFilter'](
                ['cookies' => [new \WP_Http_Cookie(['name' => 'PHPSESSID', 'value' => 'fresh'])]],
                $url,
            );
            return ['status' => 200, 'body' => '', 'headers' => []];
        }
    };
    $transport = new ResumableSessionTransport($inner);
    $GLOBALS['tamarSeedingFilter'] = [$transport, 'addSeededCookies'];
    $transport->seed(['PHPSESSID' => 'stale', 'loginsession' => 'stored']);

    $transport->request('GET', 'https://panel.example/phonedivert/huntgroup');

    $sent = [];
    foreach ($inner->args['cookies'] as $cookie) {
        $sent[] = $cookie->name . '=' . $cookie->value;
    }
    expect($sent)->toBe(['PHPSESSID=fresh', 'loginsession=stored']);

    unset($GLOBALS['tamarSeedingFilter']);
});

it('leaves any other request to the same filter alone', function () {
    $transport = new ResumableSessionTransport(new JarTransport());
    $transport->seed(['PHPSESSID' => 'stored']);

    // Nothing in flight, then something other than the request in flight.
    expect($transport->addSeededCookies(['cookies' => []], 'https://panel.example/'))
        ->toBe(['cookies' => []]);
});

// -- PanelSessionStore --------------------------------------------------

it('round-trips a session for its owner', function () {
    $store = new PanelSessionStore();
    $owner = PanelSessionStore::owner('https://panel.example/', 'demo');

    $store->save($owner, ['PHPSESSID' => 'a', 'loginsession' => 'b'], 1000, 1900);

    expect($store->load($owner))->toBe([
        'cookies' => ['PHPSESSID' => 'a', 'loginsession' => 'b'],
        'created_at' => 1000,
        'expires_at' => 1900,
    ]);
});

it('ignores a session belonging to other settings', function () {
    $store = new PanelSessionStore();
    $store->save(PanelSessionStore::owner('https://panel.example', 'demo'), ['PHPSESSID' => 'a'], 1000, 1900);

    expect($store->load(PanelSessionStore::owner('https://panel.example', 'someone-else')))->toBeNull()
        ->and($store->load(PanelSessionStore::owner('https://other.example', 'demo')))->toBeNull();
});

it('treats the base URL case- and trailing-slash-insensitively', function () {
    expect(PanelSessionStore::owner('https://Panel.example/', 'demo'))
        ->toBe(PanelSessionStore::owner('https://panel.example', 'demo'));
});

it('encrypts the stored cookies', function () {
    if (!defined('AUTH_KEY')) {
        define('AUTH_KEY', 'tamar-test-auth-key');
    }
    $store = new PanelSessionStore();
    $store->save('owner', ['PHPSESSID' => 'secret-session-id'], 1000, 1900);

    $row = get_option(PanelSessionStore::OPTION);
    expect($row['cookies'])->toStartWith('gcm:')
        ->and(json_encode($row))->not->toContain('secret-session-id')
        ->and($store->load('owner')['cookies'])->toBe(['PHPSESSID' => 'secret-session-id']);
});

it('keeps the session out of the autoloaded options', function () {
    Functions\expect('update_option')
        ->once()
        ->with(PanelSessionStore::OPTION, \Mockery::type('array'), false)
        ->andReturn(true);

    (new PanelSessionStore())->save('owner', ['PHPSESSID' => 'a'], 1000, 1900);
});

it('treats a row without an expiry as expired', function () {
    update_option(PanelSessionStore::OPTION, [
        'owner' => 'owner',
        'cookies' => 'plain:' . base64_encode('{"PHPSESSID":"a"}'),
        'created_at' => 1000,
    ]);

    expect((new PanelSessionStore())->load('owner')['expires_at'])->toBe(0);
});

it('clears the stored session', function () {
    $store = new PanelSessionStore();
    $store->save('owner', ['PHPSESSID' => 'a'], 1000, 1900);
    $store->clear();

    expect($store->load('owner'))->toBeNull();
});

it('draws a lifetime between the bounds', function () {
    for ($i = 0; $i < 50; $i++) {
        $lifetime = PanelSessionStore::drawExpiry(1000) - 1000;
        expect($lifetime)->toBeGreaterThanOrEqual(PanelSessionStore::MIN_LIFETIME)
            ->toBeLessThanOrEqual(PanelSessionStore::MAX_LIFETIME);
    }
});
