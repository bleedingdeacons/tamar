<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Transport\Interfaces\HttpTransport;
use PHPUnit\Framework\TestCase;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;

/**
 * Tests around the service's externally visible behaviour.
 *
 * We use an in-memory fake transport rather than mocks-as-doubles
 * because the service makes a *sequence* of calls (login GET → login
 * POST → page GET → update POST) and the most useful assertions are on
 * the call log, not on individual method invocations.
 */
final class HuntgroupCallForwardingServiceTest extends TestCase
{
    public function test_listRules_returns_rules_with_vm_box_resolved(): void
    {
        $service = $this->makeService(new FakeHttpTransport(['default' => $this->fixture()]));

        $rules = $service->listRules();

        self::assertCount(4, $rules);
        // Row 1 is the voicemail row — its target_id should be rewritten
        // from 'vm:default' to 'vm:20042' using the selected voicemail box.
        self::assertSame('vm:20042', $rules[0]->getTargetId());
        // Row 2 keeps the synthetic number target unchanged.
        self::assertSame('num:01454898476', $rules[1]->getTargetId());
    }

    public function test_listRules_caches_within_request(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        $service->listRules();
        $service->listRules();
        $service->listTargets();

        // A single huntgroup-page GET, regardless of how many read
        // methods we called.
        $gets = array_filter(
            $transport->log,
            fn($e) => $e['method'] === 'GET' && str_contains($e['url'], '/phonedivert/huntgroup')
        );
        self::assertCount(1, $gets);
    }

    public function test_login_is_a_get_then_credential_post(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

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

        self::assertCount(1, $loginGet);
        self::assertCount(1, $loginPost);

        $decoded = $this->decodeBody($loginPost[0]['body']);
        self::assertSame('demo', $decoded['username']);
        self::assertSame('pw', $decoded['password']);
    }

    public function test_saveRule_posts_renumbered_form_body(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        $rules = $service->listRules();
        $edited = $rules[1]->with(['label' => 'Steve C (out)']);
        $service->saveRule($edited);

        $posts = array_values(array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update')));
        self::assertCount(1, $posts);
        $decoded = $this->decodeBody($posts[0]['body']);
        self::assertSame('Steve C (out)', $decoded['2_description']);
    }

    public function test_saveRule_throws_on_invalid_rule(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        // A time-window rule with malformed times must fail validation at
        // the service boundary, not be sent upstream.
        $bad = new ForwardingRule([
            'id' => '2',
            'match' => ['type' => 'time_window', 'value' => ['from' => 'noon', 'to' => '5pm', 'days' => []]],
            'target_id' => 'num:0000',
        ]);

        $this->expectException(ForwardingException::class);
        try {
            $service->saveRule($bad);
        } finally {
            $posts = array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update'));
            self::assertCount(0, $posts);
        }
    }

    public function test_deleteRule_returns_false_when_id_absent(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        self::assertFalse($service->deleteRule('999'));
        $posts = array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update'));
        self::assertCount(0, $posts);
    }

    public function test_commit_is_a_noop_success(): void
    {
        // Tamar applies on each update — there's no separate apply step.
        // Calling commit() shouldn't hit the upstream at all.
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        self::assertTrue($service->commit());
        self::assertSame([], $transport->log);
    }

    public function test_testConnection_logs_in_and_fetches_once(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        self::assertTrue($service->testConnection());

        $pageGets = array_filter($transport->log, fn($e) => $e['method'] === 'GET' && str_contains($e['url'], '/phonedivert/huntgroup'));
        self::assertCount(1, $pageGets);
    }

    public function test_login_aborts_when_credentials_missing(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
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

        try {
            $service->testConnection();
            self::fail('Expected ForwardingException when credentials are missing.');
        } catch (ForwardingException $e) {
            self::assertStringContainsString('not configured', $e->getMessage());
        }

        self::assertSame([], $transport->log);
    }

    public function test_testConnection_throws_when_login_is_rejected(): void
    {
        // A rejected login leaves the session unauthenticated, so the
        // huntgroup GET returns the login page rather than the editor and
        // the parser throws. The service surfaces that as a failure.
        $transport = new FakeHttpTransport([
            'default' => $this->fixture(),
            'login_fails' => true,
        ]);
        $service = $this->makeService($transport);

        $this->expectException(ForwardingException::class);
        $service->testConnection();
    }

    public function test_listRules_throws_when_upstream_returns_4xx(): void
    {
        $transport = new FakeHttpTransport([
            'default' => $this->fixture(),
            'override_get_status' => 403,
        ]);
        $service = $this->makeService($transport);

        $this->expectException(ForwardingException::class);
        $service->listRules();
    }

    public function test_listHuntgroups_logs_in_and_reads_the_chooser(): void
    {
        $transport = new FakeHttpTransport([
            'default' => $this->fixture(),
            'list' => $this->listFixture(),
        ]);
        $service = $this->makeService($transport);

        $groups = $service->listHuntgroups();

        self::assertSame([['id' => '157626', 'name' => 'New Rota']], $groups);

        // The chooser is fetched from the huntgroup endpoint with NO
        // ?huntgroup= query — that's what makes the upstream render the
        // list rather than a pre-scoped editor.
        $listGets = array_values(array_filter(
            $transport->log,
            fn($e) => $e['method'] === 'GET'
                && str_contains($e['url'], '/phonedivert/huntgroup')
                && !str_contains($e['url'], 'huntgroup=')
        ));
        self::assertCount(1, $listGets);
    }

    // -- helpers ----------------------------------------------------------

    private function makeService(FakeHttpTransport $transport): HuntgroupCallForwardingService
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

    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
    }

    private function listFixture(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_list.html');
    }

    /**
     * @return array<string,string>
     */
    private function decodeBody(string $body): array
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
}

/**
 * In-memory HTTP transport double. Records every call to `log` and
 * returns a configurable canned response.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $log = [];

    /**
     * @param array{default:string, override_get_status?:int, login_fails?:bool} $config
     */
    public function __construct(private array $config)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->log[] = compact('method', 'url', 'headers', 'body');

        // POST the credentials to the login handler. We don't analyse the
        // response — a bad credential is caught later when the huntgroup
        // GET yields the login page instead of the editor.
        if (str_contains($url, '/phonedivert/login.php')) {
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        // GET the login page (sets the session cookie).
        if (str_contains($url, '/phonedivert/login')) {
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
            if (($this->config['login_fails'] ?? false) === true) {
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
