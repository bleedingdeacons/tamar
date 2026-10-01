<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Transport\Interfaces\HttpTransport;
use BleedingDeacons\WpMocks\WpState;
use Psr\Container\ContainerInterface;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Forwarding\HuntgroupFindFilter;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;

/*
 * Reading a named hunt group back without changing it, and the filter
 * another plugin reaches that through.
 *
 * FindPanel stands in for the control panel: a hunt-group list, the
 * recorded editor page served for whichever group is asked for, and a
 * record of every request after the login, so a test can show that
 * nothing was written.
 */

final class FindPanel implements HttpTransport
{
    /** @var array<string,string> id => name */
    public array $groups = ['157626' => 'New Rota', '180000' => 'Forward Week 5'];

    /** @var list<array{method:string,url:string}> */
    public array $requests = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (str_contains($url, '/phonedivert/login')) {
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        $this->requests[] = ['method' => $method, 'url' => $url];

        if (preg_match('/[?&]huntgroup=(\d+)/', $url, $m) === 1) {
            $html = (string) file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
            return ['status' => 200, 'headers' => [], 'body' => str_replace('value="157626"', 'value="' . $m[1] . '"', $html)];
        }

        $options = '<option value="none" disabled="disabled">Select from list:</option>';
        foreach ($this->groups as $id => $name) {
            $options .= '<option value="' . $id . '">' . htmlspecialchars($name) . '</option>';
        }
        return ['status' => 200, 'headers' => [], 'body' => '<html><body><form><select name="huntgroup">' . $options . '</select></form></body></html>'];
    }
}

function findService(HttpTransport $panel): HuntgroupCallForwardingService
{
    return new HuntgroupCallForwardingService(
        transport: $panel,
        parser: new HuntgroupPageParser(),
        builder: new HuntgroupFormBuilder(),
        baseUrl: 'https://example.tamartelecommunications.co.uk',
        username: 'demo',
        password: 'pw',
        huntgroupId: '157626',
    );
}

// -- HuntgroupCallForwardingService::findHuntgroup ---------------------------

it('reads the rules of the hunt group with that name', function () {
    $panel = new FindPanel();

    $group = findService($panel)->findHuntgroup(' Forward Week 5 ');

    expect($group)->not->toBeNull()
        ->and($group['id'])->toBe('180000')
        ->and($group['name'])->toBe('Forward Week 5')
        ->and($group['rules'])->toHaveCount(4)
        ->and($group['rules'])->each->toBeInstanceOf(ForwardingRule::class);

    $second = $group['rules'][1];
    expect($second->getTargetId())->toBe('num:01454898476')
        ->and($second->getLabel())->toBe('Steve C')
        ->and($second->getMatch()['value'])->toBe(['days' => ['mon'], 'from' => '10:00', 'to' => '14:00']);

    // The group was read, not the configured one.
    expect(array_column($panel->requests, 'url'))
        ->toContain('https://example.tamartelecommunications.co.uk/phonedivert/huntgroup?huntgroup=180000')
        ->not->toContain('https://example.tamartelecommunications.co.uk/phonedivert/huntgroup?huntgroup=157626');
});

it('names the voicemail box a voicemail row forwards to', function () {
    $group = findService(new FindPanel())->findHuntgroup('Forward Week 5');

    expect($group['rules'][0]->getTargetId())->toBe('vm:20042');
});

it('returns null when the account has no hunt group by that name', function () {
    $panel = new FindPanel();

    expect(findService($panel)->findHuntgroup('Forward Week 6'))->toBeNull()
        ->and($panel->requests)->toHaveCount(1);
});

it('only ever reads from the panel', function () {
    $panel = new FindPanel();
    $service = findService($panel);

    $service->findHuntgroup('Forward Week 5');
    $service->findHuntgroup('Forward Week 6');

    expect(array_column($panel->requests, 'method'))->each->toBe('GET');
});

it('refuses a blank name before contacting the panel', function () {
    $panel = new FindPanel();

    expect(fn () => findService($panel)->findHuntgroup('  '))
        ->toThrow(ForwardingException::class, 'A hunt group needs a name.');
    expect($panel->requests)->toBe([]);
});

it('fails loudly rather than reporting a missing group when the panel cannot be read', function () {
    $panel = new class implements HttpTransport {
        public function request(string $method, string $url, array $headers = [], ?string $body = null): array
        {
            return ['status' => str_contains($url, '/login') ? 200 : 503, 'headers' => [], 'body' => ''];
        }
    };

    expect(fn () => findService($panel)->findHuntgroup('Forward Week 5'))
        ->toThrow(ForwardingException::class, 'status 503');
});

// -- the tamar/find_huntgroup filter -----------------------------------------

function findFilter(?HuntgroupCallForwardingService $service): HuntgroupFindFilter
{
    return new HuntgroupFindFilter(new class ($service) implements ContainerInterface {
        public function __construct(private ?object $service)
        {
        }

        public function get(string $id): mixed
        {
            return $this->service;
        }

        public function has(string $id): bool
        {
            return $id === CallForwardingService::class && $this->service !== null;
        }
    });
}

it('registers itself on the tamar/find_huntgroup filter', function () {
    $filter = findFilter(null);
    $filter->register();

    expect(has_filter('tamar/find_huntgroup', [$filter, 'find']))->toBe(10);
});

it('hands back the group, or null for a name the account does not have', function () {
    $filter = findFilter(findService(new FindPanel()));

    expect($filter->find(null, 'Forward Week 5'))->toMatchArray(['id' => '180000', 'name' => 'Forward Week 5'])
        ->and($filter->find(null, 'Forward Week 6'))->toBeNull();
});

it('refuses a role that cannot view forwarding, before contacting the panel', function () {
    WpState::$deniedCaps = ['beacon_view_forwarding'];
    $panel = new FindPanel();

    expect(fn () => findFilter(findService($panel))->find(null, 'Forward Week 5'))
        ->toThrow(ForwardingException::class, 'You do not have permission to read forwarding from Tamar.');
    expect($panel->requests)->toBe([]);
});

it('refuses a driver that cannot look hunt groups up by name', function () {
    expect(fn () => findFilter(null)->find(null, 'Forward Week 5'))
        ->toThrow(ForwardingException::class, 'cannot look up hunt groups by name');
});
