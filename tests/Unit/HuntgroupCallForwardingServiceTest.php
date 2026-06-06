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
 * because the service makes a *sequence* of calls (login → GET →
 * POST) and the most useful assertions are on the call log, not on
 * individual method invocations.
 */
final class HuntgroupCallForwardingServiceTest extends TestCase
{
    public function test_listRules_returns_rules_with_vm_box_resolved(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        $rules = $service->listRules();

        self::assertCount(4, $rules);
        // Row 1 is the voicemail row — its target_id should be
        // rewritten from 'vm:default' to 'vm:20042' using the
        // voicemail box selected in the meta.
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

        // Login (POST) + a single page GET, regardless of how many
        // read methods we called.
        $gets = array_filter($transport->log, fn($entry) => $entry['method'] === 'GET');
        self::assertCount(1, $gets);
    }

    public function test_saveRule_posts_renumbered_form_body(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        // Read existing rules, then change row 2's label.
        $rules = $service->listRules();
        $edited = $rules[1]->with(['label' => 'Steve C (out)']);
        $service->saveRule($edited);

        // The POST body should include the updated description.
        $posts = array_values(array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update')));
        self::assertCount(1, $posts);
        $decoded = $this->decodeBody($posts[0]['body']);
        self::assertSame('Steve C (out)', $decoded['2_description']);
    }

    public function test_saveRule_throws_on_invalid_rule(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        // A time-window rule with malformed times must fail validation
        // at the service boundary, not be sent upstream.
        $bad = new ForwardingRule([
            'id' => '2',
            'match' => ['type' => 'time_window', 'value' => ['from' => 'noon', 'to' => '5pm', 'days' => []]],
            'target_id' => 'num:0000',
        ]);

        $this->expectException(ForwardingException::class);
        try {
            $service->saveRule($bad);
        } finally {
            // Make sure no POST went out.
            $posts = array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update'));
            self::assertCount(0, $posts);
        }
    }

    public function test_deleteRule_returns_false_when_id_absent(): void
    {
        $transport = new FakeHttpTransport(['default' => $this->fixture()]);
        $service = $this->makeService($transport);

        self::assertFalse($service->deleteRule('999'));
        // No POST should have been issued, since nothing changed.
        $posts = array_filter($transport->log, fn($e) => $e['method'] === 'POST' && str_contains($e['url'], '/huntgroup/update'));
        self::assertCount(0, $posts);
    }

    public function test_commit_is_a_noop_success(): void
    {
        // Tamar applies on each update — there's no separate apply
        // step. Calling commit() shouldn't hit the upstream at all.
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

        $logins = array_filter($transport->log, fn($e) => str_contains($e['url'], '/customer-login/'));
        $gets = array_filter($transport->log, fn($e) => $e['method'] === 'GET');
        self::assertCount(1, $logins);
        self::assertCount(1, $gets);
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

        // No request of any kind should have been made.
        self::assertSame([], $transport->log);
    }

    public function test_testConnection_throws_when_login_is_rejected(): void
    {
        $transport = new FakeHttpTransport([
            'default' => $this->fixture(),
            'login_fails' => true,
        ]);
        $service = $this->makeService($transport);

        // A wrong credential redirects to ?notify=failedlogin. The
        // service must treat that as a failure even though the HTTP
        // status is a normal 302, and must NOT proceed to fetch the
        // hunt-group page.
        try {
            $service->testConnection();
            self::fail('Expected ForwardingException on rejected login.');
        } catch (ForwardingException $e) {
            self::assertStringContainsString('login', strtolower($e->getMessage()));
        }

        $gets = array_filter($transport->log, fn($e) => $e['method'] === 'GET');
        self::assertCount(0, $gets, 'No page GET should happen after a failed login.');
    }

    public function test_listRules_throws_when_login_is_rejected(): void
    {
        $transport = new FakeHttpTransport([
            'default' => $this->fixture(),
            'login_fails' => true,
        ]);
        $service = $this->makeService($transport);

        $this->expectException(ForwardingException::class);
        $service->listRules();
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

        // Login endpoint — the upstream redirects to ?logged_in=1 on
        // success or ?notify=failedlogin on a bad credential. We model
        // that outcome via the Location header, which is what the
        // service inspects.
        if (str_contains($url, '/customer-login/')) {
            if (($this->config['login_fails'] ?? false) === true) {
                return [
                    'status' => 302,
                    'headers' => ['location' => $url . '?notify=failedlogin'],
                    'body' => '',
                ];
            }
            return [
                'status' => 302,
                'headers' => ['location' => $url . '?logged_in=1'],
                'body' => '',
            ];
        }

        // GET the huntgroup page.
        if ($method === 'GET' && str_contains($url, '/phonedivert/huntgroup')) {
            $status = $this->config['override_get_status'] ?? 200;
            return ['status' => $status, 'headers' => [], 'body' => $status === 200 ? $this->config['default'] : ''];
        }

        // POST update.
        if ($method === 'POST' && str_contains($url, '/huntgroup/update')) {
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        return ['status' => 404, 'headers' => [], 'body' => ''];
    }
}
