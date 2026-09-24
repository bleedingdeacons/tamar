<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\TransportException;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;
use Tamar\Forwarding\PanelSessionStore;
use Tamar\Transport\ResumableSessionTransport;

/*
 * Tests around the service's externally visible behaviour.
 *
 * We use an in-memory fake transport rather than mocks-as-doubles
 * because the service makes a *sequence* of calls (login GET → login
 * POST → page GET → update POST) and the most useful assertions are on
 * the call log, not on individual method invocations.
 */

// -- helpers ----------------------------------------------------------

function makeHuntgroupService(FakeHttpTransport $transport): HuntgroupCallForwardingService
{
    return new HuntgroupCallForwardingService(
        transport: $transport,
        parser: new HuntgroupPageParser(),
        builder: new HuntgroupFormBuilder(),
        baseUrl: 'https://example.tamartelecommunications.co.uk',
        username: 'demo',
        password: 'pw',
        huntgroupId: '157626',
    );
}

function serviceFixture(): string
{
    return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
}

function serviceListFixture(): string
{
    return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_list.html');
}

/**
 * @return array<string,string>
 */
function decodeServiceBody(string $body): array
{
    $out = [];
    foreach (explode('&', $body) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $out[rawurldecode($k)] = rawurldecode($v);
    }
    return $out;
}

it('returns listRules with the voicemail box resolved', function () {
    $service = makeHuntgroupService(new FakeHttpTransport(['default' => serviceFixture()]));

    $rules = $service->listRules();

    expect($rules)->toHaveCount(4)
        // Row 1 is the voicemail row — its target_id should be rewritten
        // from 'vm:default' to 'vm:20042' using the selected voicemail box.
        ->and($rules[0]->getTargetId())->toBe('vm:20042')
        // Row 2 keeps the synthetic number target unchanged.
        ->and($rules[1]->getTargetId())->toBe('num:01454898476');
});

it('caches listRules within a request', function () {
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    $service = makeHuntgroupService($transport);

    $service->listRules();
    $service->listRules();
    $service->listTargets();

    // A single huntgroup-page GET, regardless of how many read
    // methods we called.
    $gets = array_filter(
        $transport->log,
        fn($e) => $e['method'] === 'GET' && str_contains($e['url'], '/phonedivert/huntgroup')
    );
    expect($gets)->toHaveCount(1);
});

it('logs in with a GET then a credential POST', function () {
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    $service = makeHuntgroupService($transport);

    $service->listRules();

    // GET the login page (session cookie), then POST the credentials
    // to login.php — username/password under those literal names.
    $loginGet = array_values(array_filter(
        $transport->log,
        fn($e) => $e['method'] === 'GET' && str_ends_with($e['url'], '/phonedivert/login')
    ));
    $loginPost = array_values(array_filter(
        $transport->log,
        fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/phonedivert/login.php')
    ));

    expect($loginGet)->toHaveCount(1)
        ->and($loginPost)->toHaveCount(1);

    $decoded = decodeServiceBody($loginPost[0]['body']);
    expect($decoded['username'])->toBe('demo')
        ->and($decoded['password'])->toBe('pw');
});

it('posts a renumbered form body from saveRule', function () {
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    $service = makeHuntgroupService($transport);

    $rules = $service->listRules();
    $edited = $rules[1]->with(['label' => 'Steve C (out)']);
    $service->saveRule($edited);

    $posts = array_values(array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update')));
    expect($posts)->toHaveCount(1);
    $decoded = decodeServiceBody($posts[0]['body']);
    expect($decoded['2_description'])->toBe('Steve C (out)');
});

it('throws from saveRule on an invalid rule', function () {
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    $service = makeHuntgroupService($transport);

    // A time-window rule with malformed times must fail validation at
    // the service boundary, not be sent upstream.
    $bad = new ForwardingRule([
        'id' => '2',
        'match' => ['type' => 'time_window', 'value' => ['from' => 'noon', 'to' => '5pm', 'days' => []]],
        'target_id' => 'num:0000',
    ]);

    expect(fn () => $service->saveRule($bad))->toThrow(ForwardingException::class);

    $posts = array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update'));
    expect($posts)->toHaveCount(0);
});

it('returns false from deleteRule when the id is absent', function () {
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    $service = makeHuntgroupService($transport);

    expect($service->deleteRule('999'))->toBeFalse();
    $posts = array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update'));
    expect($posts)->toHaveCount(0);
});

it('treats commit as a no-op success', function () {
    // Tamar applies on each update — there's no separate apply step.
    // Calling commit() shouldn't hit the upstream at all.
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    $service = makeHuntgroupService($transport);

    expect($service->commit())->toBeTrue()
        ->and($transport->log)->toBe([]);
});

it('logs in and fetches once on testConnection', function () {
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    $service = makeHuntgroupService($transport);

    expect($service->testConnection())->toBeTrue();

    $pageGets = array_filter($transport->log, fn($e) => $e['method'] === 'GET' && str_contains($e['url'], '/phonedivert/huntgroup'));
    expect($pageGets)->toHaveCount(1);
});

it('aborts login when credentials are missing', function () {
    $transport = new FakeHttpTransport(['default' => serviceFixture()]);
    // Empty password — login should be refused before any HTTP call.
    $service = new HuntgroupCallForwardingService(
        transport: $transport,
        parser: new HuntgroupPageParser(),
        builder: new HuntgroupFormBuilder(),
        baseUrl: 'https://example.tamartelecommunications.co.uk',
        username: 'demo',
        password: '',
        huntgroupId: '157626',
    );

    expect(fn () => $service->testConnection())
        ->toThrow(ForwardingException::class, 'not configured');

    expect($transport->log)->toBe([]);
});

it('throws from testConnection when the login is rejected', function () {
    // A rejected login leaves the session unauthenticated, so the
    // huntgroup GET returns the login page rather than the editor and
    // the parser throws. The service surfaces that as a failure.
    $transport = new FakeHttpTransport([
        'default' => serviceFixture(),
        'login_fails' => true,
    ]);
    $service = makeHuntgroupService($transport);

    $service->testConnection();
})->throws(ForwardingException::class);

it('throws from listRules when the upstream returns a 4xx', function () {
    $transport = new FakeHttpTransport([
        'default' => serviceFixture(),
        'override_get_status' => 403,
    ]);
    $service = makeHuntgroupService($transport);

    $service->listRules();
})->throws(ForwardingException::class);

it('logs in and reads the chooser for listHuntgroups', function () {
    $transport = new FakeHttpTransport([
        'default' => serviceFixture(),
        'list' => serviceListFixture(),
    ]);
    $service = makeHuntgroupService($transport);

    $groups = $service->listHuntgroups();

    expect($groups)->toBe([['id' => '157626', 'name' => 'New Rota']]);

    // The chooser is fetched from the huntgroup endpoint with NO
    // ?huntgroup= query — that's what makes the upstream render the
    // list rather than a pre-scoped editor.
    $listGets = array_values(array_filter(
        $transport->log,
        fn($e) => $e['method'] === 'GET'
            && str_contains($e['url'], '/phonedivert/huntgroup')
            && !str_contains($e['url'], 'huntgroup=')
    ));
    expect($listGets)->toHaveCount(1);
});

// -- stored session ---------------------------------------------------

function makeSessionService(ResumableSessionTransport $transport): HuntgroupCallForwardingService
{
    return new HuntgroupCallForwardingService(
        transport: $transport,
        parser: new HuntgroupPageParser(),
        builder: new HuntgroupFormBuilder(),
        baseUrl: 'https://example.tamartelecommunications.co.uk',
        username: 'demo',
        password: 'pw',
        huntgroupId: '157626',
        sessions: new PanelSessionStore(),
    );
}

function sessionOwner(): string
{
    return PanelSessionStore::owner('https://example.tamartelecommunications.co.uk', 'demo');
}

/**
 * @param array<string,string> $cookies
 */
function storeSession(array $cookies, int $createdAgo = 60, int $expiresIn = 600): void
{
    (new PanelSessionStore())->save(sessionOwner(), $cookies, time() - $createdAgo, time() + $expiresIn);
}

/**
 * @return list<array{method:string,url:string}>
 */
function loginRequests(FakeHttpTransport $transport): array
{
    return array_values(array_filter(
        $transport->log,
        fn($e) => str_contains($e['url'], '/phonedivert/login')
    ));
}

it('stores the session once a page proves the login', function () {
    $service = makeSessionService(new ResumableSessionTransport(new FakeHttpTransport(['default' => serviceFixture()])));

    $service->listRules();

    $stored = (new PanelSessionStore())->load(sessionOwner());
    expect($stored)->not->toBeNull()
        ->and($stored['cookies'])->toBe(['PHPSESSID' => 'abc123'])
        ->and($stored['expires_at'] - $stored['created_at'])
            ->toBeGreaterThanOrEqual(PanelSessionStore::MIN_LIFETIME)
            ->toBeLessThanOrEqual(PanelSessionStore::MAX_LIFETIME);
});

it('does not store a session the page did not accept', function () {
    $service = makeSessionService(new ResumableSessionTransport(new FakeHttpTransport([
        'default' => serviceFixture(),
        'login_fails' => true,
    ])));

    expect(fn () => $service->listRules())->toThrow(ForwardingException::class);
    expect(get_option(PanelSessionStore::OPTION))->toBeFalse();
});

it('reuses a stored session instead of logging in', function () {
    storeSession(['PHPSESSID' => 'stored']);
    $fake = new FakeHttpTransport(['default' => serviceFixture()]);
    $transport = new ResumableSessionTransport($fake);

    expect(makeSessionService($transport)->listRules())->toHaveCount(4)
        ->and(loginRequests($fake))->toBe([])
        ->and($transport->cookies())->toBe(['PHPSESSID' => 'stored']);
});

it('logs in again, once, when the panel refuses the stored session', function () {
    storeSession(['PHPSESSID' => 'stale']);
    $fake = new FakeHttpTransport(['default' => serviceFixture(), 'stored_session_rejected' => true]);

    expect(makeSessionService(new ResumableSessionTransport($fake))->listRules())->toHaveCount(4);

    $pageGets = array_filter($fake->log, fn($e) => str_contains($e['url'], 'huntgroup=157626'));
    expect($pageGets)->toHaveCount(2)
        ->and(loginRequests($fake))->toHaveCount(2);

    // The refused session is replaced by the one just logged in.
    $stored = (new PanelSessionStore())->load(sessionOwner());
    expect($stored['cookies'])->toBe(['PHPSESSID' => 'abc123'])
        ->and($stored['created_at'])->toBeGreaterThanOrEqual(time() - 5);
});

it('retires a stored session that has reached its lifetime', function () {
    storeSession(['PHPSESSID' => 'old'], createdAgo: 1900, expiresIn: -100);
    $fake = new FakeHttpTransport(['default' => serviceFixture()]);
    $transport = new ResumableSessionTransport($fake);

    makeSessionService($transport)->listRules();

    // Logged in afresh, and never sent the retired cookie.
    expect(loginRequests($fake))->toHaveCount(2)
        ->and($transport->cookies())->toBe(['PHPSESSID' => 'abc123'])
        ->and((new PanelSessionStore())->load(sessionOwner())['cookies'])->toBe(['PHPSESSID' => 'abc123']);
});

it('ignores a session stored for other settings', function () {
    (new PanelSessionStore())->save(
        PanelSessionStore::owner('https://elsewhere.example', 'demo'),
        ['PHPSESSID' => 'theirs'],
        time(),
        time() + 600,
    );
    $fake = new FakeHttpTransport(['default' => serviceFixture()]);
    $transport = new ResumableSessionTransport($fake);

    makeSessionService($transport)->listRules();

    expect(loginRequests($fake))->toHaveCount(2)
        ->and($transport->cookies())->not->toHaveKey('PHPSESSID', 'theirs');
});

it('proves the credentials with a real login on testConnection', function () {
    storeSession(['PHPSESSID' => 'stored']);
    $fake = new FakeHttpTransport(['default' => serviceFixture()]);

    makeSessionService(new ResumableSessionTransport($fake))->testConnection();

    expect(loginRequests($fake))->toHaveCount(2);
});

it('does not log in again when the panel cannot be reached', function () {
    storeSession(['PHPSESSID' => 'stored']);
    $fake = new FakeHttpTransport(['default' => serviceFixture(), 'network_down' => true]);

    expect(fn () => makeSessionService(new ResumableSessionTransport($fake))->listRules())
        ->toThrow(ForwardingException::class, 'Could not reach');
    expect(loginRequests($fake))->toBe([])
        // A network failure says nothing about the session, so it is kept.
        ->and((new PanelSessionStore())->load(sessionOwner()))->not->toBeNull();
});

it('reuses a stored session to list hunt groups', function () {
    storeSession(['PHPSESSID' => 'stored']);
    $fake = new FakeHttpTransport(['default' => serviceFixture(), 'list' => serviceListFixture()]);

    expect(makeSessionService(new ResumableSessionTransport($fake))->listHuntgroups())->toHaveCount(1)
        ->and(loginRequests($fake))->toBe([]);
});

/**
 * In-memory HTTP transport double. Records every call to `log` and
 * returns a configurable canned response.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $log = [];

    /** Whether the login page has set the session cookie on this transport. */
    private bool $sessionIssued = false;

    /** Whether credentials have been posted on this transport. */
    private bool $credentialsPosted = false;

    /**
     * @param array{default:string, override_get_status?:int, login_fails?:bool, list?:string, stored_session_rejected?:bool, network_down?:bool} $config
     */
    public function __construct(private array $config)
    {
    }

    /**
     * What this transport's panel has set, as Beacon's WpHttpTransport
     * reports it.
     *
     * @return array<string,string>
     */
    public function cookies(): array
    {
        return $this->sessionIssued ? ['PHPSESSID' => 'abc123'] : [];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->log[] = compact('method', 'url', 'headers', 'body');

        if (($this->config['network_down'] ?? false) === true) {
            throw new TransportException('HTTP request to ' . $url . ' failed: cURL error 28');
        }

        // POST the credentials to the login handler. We don't analyse the
        // response — a bad credential is caught later when the huntgroup
        // GET yields the login page instead of the editor.
        if (str_contains($url, '/phonedivert/login.php')) {
            $this->credentialsPosted = true;
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        // GET the login page (sets the session cookie).
        if (str_contains($url, '/phonedivert/login')) {
            $this->sessionIssued = true;
            return [
                'status' => 200,
                'headers' => ['set-cookie' => 'PHPSESSID=abc123; path=/'],
                'body' => $this->loginPage(),
            ];
        }

        // GET the huntgroup page. When login_fails is set we model an
        // unauthenticated session by serving the login page here, which
        // the parser rejects.
        if ($method === 'GET' && str_contains($url, '/phonedivert/huntgroup')) {
            $status = $this->config['override_get_status'] ?? 200;
            if ($status !== 200) {
                return ['status' => $status, 'headers' => [], 'body' => ''];
            }
            // A stored session the panel has expired: the login page,
            // until credentials are posted on this transport.
            $rejected = ($this->config['stored_session_rejected'] ?? false) === true && !$this->credentialsPosted;
            if (($this->config['login_fails'] ?? false) === true || $rejected) {
                return ['status' => 200, 'headers' => [], 'body' => $this->loginPage()];
            }
            // The chooser (list) page is the same endpoint with NO
            // ?huntgroup= query; the editor page carries the query.
            if (!str_contains($url, 'huntgroup=') && isset($this->config['list'])) {
                return ['status' => 200, 'headers' => [], 'body' => $this->config['list']];
            }
            return ['status' => 200, 'headers' => [], 'body' => $this->config['default']];
        }

        // POST the rota update.
        if ($method === 'POST' && str_contains($url, '/huntgroup/update')) {
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        return ['status' => 404, 'headers' => [], 'body' => ''];
    }

    private function loginPage(): string
    {
        return '<html><body><form action="/phonedivert/login.php" method="post">'
            . '<input type="text" name="username" />'
            . '<input type="password" name="password" />'
            . '</form></body></html>';
    }
}
