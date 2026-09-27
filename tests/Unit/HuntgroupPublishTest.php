<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Transport\Interfaces\HttpTransport;
use BleedingDeacons\WpMocks\WpState;
use Psr\Container\ContainerInterface;
use Tamar\Admin\TamarSettings;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;
use Tamar\Forwarding\HuntgroupPublishFilter;

/*
 * Publishing a whole rota into a named hunt group, and the filter another
 * plugin reaches it through.
 *
 * PublishPanel stands in for the control panel: it keeps a list of hunt
 * groups that its Create form adds to, serves the recorded editor page for
 * the configured group (157626) and an empty one for any other, and
 * records every POST after the login so the body sent to the panel can be
 * read back.
 */

if (!defined('TAMAR_OPTION_KEY')) {
    define('TAMAR_OPTION_KEY', 'tamar_settings');
}

final class PublishPanel implements HttpTransport
{
    /** @var array<string,string> id => name */
    public array $groups = ['157626' => 'New Rota'];

    /** @var list<array{url:string,body:string}> */
    public array $posts = [];

    private int $nextId = 200001;

    public function __construct(private bool $createIsIgnored = false)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $body ??= '';

        if (str_contains($url, '/phonedivert/login')) {
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        if ($method === 'POST') {
            $this->posts[] = ['url' => $url, 'body' => $body];
            if (str_ends_with($url, '/phonedivert/huntgroup/create') && !$this->createIsIgnored) {
                $this->groups[(string) $this->nextId++] = publishDecode($body)['description'] ?? '';
            }
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }

        if (preg_match('/[?&]huntgroup=(\d+)/', $url, $m) === 1) {
            return ['status' => 200, 'headers' => [], 'body' => $this->editor($m[1])];
        }

        return ['status' => 200, 'headers' => [], 'body' => $this->listPage()];
    }

    private function listPage(): string
    {
        $options = '<option value="none" disabled="disabled">Select from list:</option>';
        foreach ($this->groups as $id => $name) {
            $options .= '<option value="' . $id . '">' . htmlspecialchars($name) . '</option>';
        }
        return '<html><body><form><select name="huntgroup" id="huntgroup">' . $options . '</select></form></body></html>';
    }

    private function editor(string $id): string
    {
        $html = (string) file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
        if ($id === '157626') {
            return $html;
        }

        // A new group: no rows, no voicemail box, its own id and name.
        $html = (string) preg_replace('#<tr class="huntdest">.*?</tr>#s', '', $html);
        $html = str_replace(
            ['value="157626"', 'value="New Rota"', '<option value="20042" selected="selected">'],
            ['value="' . $id . '"', 'value="' . htmlspecialchars($this->groups[$id] ?? '') . '"', '<option value="20042">'],
            $html
        );
        return $html;
    }
}

/** @return array<string,string> */
function publishDecode(string $body): array
{
    $out = [];
    foreach (explode('&', $body) as $pair) {
        if ($pair !== '') {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[rawurldecode($k)] = rawurldecode(str_replace('+', ' ', $v));
        }
    }
    return $out;
}

function publishService(PublishPanel $panel, string $huntgroupId = '157626'): HuntgroupCallForwardingService
{
    return new HuntgroupCallForwardingService(
        transport: $panel,
        parser: new HuntgroupPageParser(),
        builder: new HuntgroupFormBuilder(),
        baseUrl: 'https://example.tamartelecommunications.co.uk',
        username: 'demo',
        password: 'pw',
        huntgroupId: $huntgroupId,
    );
}

/** @param string[] $days */
function publishRule(int $priority, string $label, string $targetId, array $days, string $from, string $to): ForwardingRule
{
    return new ForwardingRule([
        'id' => (string) $priority,
        'priority' => $priority,
        'label' => $label,
        'match' => ['type' => 'time_window', 'value' => ['days' => $days, 'from' => $from, 'to' => $to]],
        'target_id' => $targetId,
        'enabled' => true,
    ]);
}

/** @return list<ForwardingRule> */
function weekRules(): array
{
    return [
        publishRule(2, 'Unfilled', 'vm:default', ['mon'], '14:00', '18:00'),
        publishRule(1, 'Anon A', 'num:07700900123', ['mon'], '10:00', '14:00'),
    ];
}

/** @return array<string,string> */
function lastUpdate(PublishPanel $panel): array
{
    $updates = array_values(array_filter($panel->posts, fn (array $p): bool => str_ends_with($p['url'], '/huntgroup/update')));
    expect($updates)->toHaveCount(1);
    return publishDecode($updates[0]['body']);
}

// -- HuntgroupCallForwardingService::publishHuntgroup ------------------------

it('creates a missing hunt group and writes the rota into it', function () {
    $panel = new PublishPanel();

    $id = publishService($panel)->publishHuntgroup('Forward Week 5', weekRules());

    expect($id)->toBe('200001')
        ->and($panel->posts[0]['url'])->toBe('https://example.tamartelecommunications.co.uk/phonedivert/huntgroup/create')
        ->and(publishDecode($panel->posts[0]['body']))->toBe(['description' => 'Forward Week 5']);

    expect(lastUpdate($panel))->toMatchArray([
        'hg-name' => 'Forward Week 5',
        'hg-id' => '200001',
        // Written in priority order, whatever order they arrived in.
        '1_mon' => 'on',
        '1_start' => '10:00',
        '1_end' => '14:00',
        '1_destination' => '07700900123',
        '1_description' => 'Anon A',
        '1_enabled' => 'on',
        '2_vm' => 'on',
        '2_destination' => 'voicemail',
        '2_description' => 'Unfilled',
    ])->not->toHaveKey('3_destination');
});

// A new group has no voicemail box, so its voicemail rows would go nowhere.
it('takes the voicemail box, hunting and ring timeout from the configured group', function () {
    $panel = new PublishPanel();

    publishService($panel)->publishHuntgroup('Forward Week 5', weekRules());

    expect(lastUpdate($panel))->toMatchArray([
        'voicemail' => '20042',
        'hunting' => 'inorder',
        // Three of the configured group's four rows ring for 90 seconds.
        '1_timeout' => '90',
        '2_timeout' => '90',
    ]);
});

it('overwrites a hunt group that already has the name, rather than making another', function () {
    $panel = new PublishPanel();
    $panel->groups['180000'] = 'Forward Week 5';

    $id = publishService($panel)->publishHuntgroup('Forward Week 5', weekRules());

    expect($id)->toBe('180000')
        ->and(array_column($panel->posts, 'url'))->not->toContain('https://example.tamartelecommunications.co.uk/phonedivert/huntgroup/create')
        ->and(lastUpdate($panel)['hg-id'])->toBe('180000');
});

it('replaces every row when publishing into the configured group itself', function () {
    $panel = new PublishPanel();

    publishService($panel)->publishHuntgroup('New Rota', weekRules());

    expect(lastUpdate($panel))->toMatchArray(['hg-id' => '157626', '2_destination' => 'voicemail'])
        ->not->toHaveKey('3_destination');
});

it('falls back to the panel defaults when no hunt group is configured', function () {
    $panel = new PublishPanel();

    publishService($panel, '')->publishHuntgroup('Forward Week 5', weekRules());

    expect(lastUpdate($panel))->toMatchArray(['voicemail' => 'none', '1_timeout' => '20']);
});

it('refuses when the created group never shows up in the list', function () {
    $panel = new PublishPanel(createIsIgnored: true);

    expect(fn () => publishService($panel)->publishHuntgroup('Forward Week 5', weekRules()))
        ->toThrow(ForwardingException::class, 'it is not in the hunt-group list afterwards');

    expect(array_column($panel->posts, 'url'))->each->not->toEndWith('/huntgroup/update');
});

it('refuses a blank name before contacting the panel', function () {
    $panel = new PublishPanel();

    expect(fn () => publishService($panel)->publishHuntgroup('  ', weekRules()))
        ->toThrow(ForwardingException::class, 'A hunt group needs a name.');
    expect($panel->posts)->toBe([]);
});

it('refuses a rule with no destination before contacting the panel', function () {
    $panel = new PublishPanel();

    expect(fn () => publishService($panel)->publishHuntgroup('Forward Week 5', [publishRule(1, 'x', '', ['mon'], '10:00', '14:00')]))
        ->toThrow(ForwardingException::class);
    expect($panel->posts)->toBe([]);
});

// -- the tamar/publish_huntgroup filter --------------------------------------

function publishFilter(?HuntgroupCallForwardingService $service): HuntgroupPublishFilter
{
    return new HuntgroupPublishFilter(new class ($service) implements ContainerInterface {
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

it('registers itself on the tamar/publish_huntgroup filter', function () {
    $filter = publishFilter(null);
    $filter->register();

    expect(has_filter('tamar/publish_huntgroup', [$filter, 'publish']))->toBe(10);
});

it('publishes and makes the new group the configured one', function () {
    WpState::$options[TAMAR_OPTION_KEY] = ['huntgroup_id' => '157626', 'verify_tls' => true];

    $result = publishFilter(publishService(new PublishPanel()))->publish(null, ' Forward Week 5 ', weekRules());

    expect($result)->toBe(['id' => '200001', 'name' => 'Forward Week 5'])
        ->and(TamarSettings::load())->toMatchArray(['huntgroup_id' => '200001', 'verify_tls' => true]);
});

it('refuses a role that cannot manage forwarding, before contacting the panel', function () {
    WpState::$deniedCaps = ['beacon_manage_forwarding'];
    $panel = new PublishPanel();

    expect(fn () => publishFilter(publishService($panel))->publish(null, 'Forward Week 5', weekRules()))
        ->toThrow(ForwardingException::class, 'You do not have permission to publish forwarding to Tamar.');
    expect($panel->posts)->toBe([]);
});

it('refuses anything but a list of forwarding rules', function (mixed $rules) {
    expect(fn () => publishFilter(publishService(new PublishPanel()))->publish(null, 'Forward Week 5', $rules))
        ->toThrow(ForwardingException::class, 'needs a list of forwarding rules');
})->with([
    'not a list' => ['rules'],
    'not rules' => [[['id' => '1']]],
]);

it('refuses a driver that cannot create hunt groups', function () {
    expect(fn () => publishFilter(null)->publish(null, 'Forward Week 5', weekRules()))
        ->toThrow(ForwardingException::class, 'cannot create hunt groups');
});
