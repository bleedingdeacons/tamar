<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Transport\Interfaces\HttpTransport;
use BleedingDeacons\WpMocks\WpState;
use Psr\Container\ContainerInterface;
use Tamar\Admin\SettingsPage;
use Tamar\Admin\TamarSettings;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Forwarding\HuntgroupDefaults;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;

/*
 * The hunt-group settings: ring timeout, announcement, voicemail box and
 * hunting type, given to every hunt group written from a rota. Their
 * defaults, how a saved value is kept, how they are applied to a page,
 * and their section on the Settings page.
 *
 * OptionsPanel serves the recorded editor page for any hunt group and a
 * list with one group, and counts every request after the login.
 */

if (!defined('TAMAR_OPTION_KEY')) {
    define('TAMAR_OPTION_KEY', 'tamar_settings');
}

final class OptionsPanel implements HttpTransport
{
    /** @var list<string> */
    public array $requests = [];

    public function __construct(private bool $hasGroups = true)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (str_contains($url, '/phonedivert/login')) {
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }
        $this->requests[] = $method . ' ' . $url;

        if (str_contains($url, '?huntgroup=')) {
            return ['status' => 200, 'headers' => [], 'body' => (string) file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html')];
        }

        $options = '<option value="none" disabled="disabled">Select from list:</option>'
            . ($this->hasGroups ? '<option value="180000">Forward Week 5</option>' : '');
        return ['status' => 200, 'headers' => [], 'body' => '<html><body><select name="huntgroup">' . $options . '</select></body></html>'];
    }
}

function optionsService(HttpTransport $panel, string $huntgroupId = '157626'): HuntgroupCallForwardingService
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

/** @return array<string,mixed> */
function pageMeta(): array
{
    return [
        'greetings_available' => [['id' => 'none', 'label' => 'None'], ['id' => 'abc-1', 'label' => 'Out of hours']],
        'voicemails_available' => [['id' => 'none', 'label' => 'None'], ['id' => '20042', 'label' => ' voice to email ']],
    ];
}

function renderSettingsWith(?object $service): string
{
    $page = new SettingsPage(new class ($service) implements ContainerInterface {
        public function __construct(private ?object $service)
        {
        }

        public function get(string $id): mixed
        {
            return $this->service ?? throw new \LogicException('Nothing is bound.');
        }

        public function has(string $id): bool
        {
            return $id === CallForwardingService::class && $this->service !== null;
        }
    });

    ob_start();
    $page->renderSettings();
    return (string) ob_get_clean();
}

// -- the settings ----------------------------------------------------------------

it('defaults to a 90-second ring, no announcement, Voice to Email and hunt in-order', function () {
    expect(TamarSettings::load())->toMatchArray([
        'ring_timeout' => 90,
        'greeting' => 'none',
        'voicemail' => '',
        'hunting' => 'inorder',
    ]);

    $defaults = TamarSettings::huntgroupDefaults();
    expect($defaults->ringTimeout)->toBe(90)
        ->and($defaults->greeting)->toBe('none')
        ->and($defaults->voicemail)->toBe('')
        ->and($defaults->hunting)->toBe('inorder');
});

it('keeps saved hunt-group settings', function () {
    TamarSettings::save(['ring_timeout' => '45', 'greeting' => 'dcbc6a61-b256-11eb-8b58-ac281572e7f5', 'voicemail' => '20042', 'hunting' => 'rotary']);

    expect(TamarSettings::load())->toMatchArray([
        'ring_timeout' => 45,
        'greeting' => 'dcbc6a61-b256-11eb-8b58-ac281572e7f5',
        'voicemail' => '20042',
        'hunting' => 'rotary',
    ]);
});

it('keeps the ring timeout within what the panel accepts', function (mixed $typed, int $stored) {
    TamarSettings::save(['ring_timeout' => $typed]);

    expect(TamarSettings::load()['ring_timeout'])->toBe($stored);
})->with([
    'too short' => ['2', 5],
    'too long' => ['600', 180],
    'not a number' => ['soon', 90],
]);

it('drops a value that is not a panel option', function () {
    TamarSettings::save(['greeting' => '<script>', 'voicemail' => 'a b', 'hunting' => 'loudest']);

    expect(TamarSettings::load())->toMatchArray(['greeting' => 'none', 'voicemail' => '', 'hunting' => 'inorder']);
});

it('keeps the hunt-group settings when a form without them is saved', function () {
    WpState::$options[TAMAR_OPTION_KEY] = ['ring_timeout' => 30, 'voicemail' => '20042', 'hunting' => 'random'];

    TamarSettings::save(['base_url' => 'https://panel.example.test']);
    TamarSettings::saveHuntgroupId('157626');

    expect(TamarSettings::load())->toMatchArray(['ring_timeout' => 30, 'voicemail' => '20042', 'hunting' => 'random']);
});

// -- applying them -----------------------------------------------------------------

it('finds the default voicemail box by its name', function () {
    expect((new HuntgroupDefaults())->applyTo(pageMeta()))
        ->toMatchArray(['greeting' => 'none', 'voicemail' => '20042', 'hunting' => 'inorder']);
});

it('refuses when the panel has no box called Voice to Email', function () {
    $meta = pageMeta();
    $meta['voicemails_available'] = [['id' => 'none', 'label' => 'None']];

    expect(fn () => (new HuntgroupDefaults())->applyTo($meta))
        ->toThrow(ForwardingException::class, 'The control panel has no voicemail box called "Voice to Email". Choose one under Tamar → Settings.');
});

it('writes "none" without needing the panel to list it', function () {
    expect((new HuntgroupDefaults(voicemail: 'none'))->applyTo([])['voicemail'])->toBe('none');
});

// -- the panel's options -----------------------------------------------------------

it('reads the announcements and voicemail boxes from the configured group', function () {
    $panel = new OptionsPanel();

    $options = optionsService($panel)->panelOptions();

    expect($options['greetings'])->toContain(['id' => 'none', 'label' => 'None'], ['id' => 'dcbc6a61-b256-11eb-8b58-ac281572e7f5', 'label' => 'CW - Western Service Office'])
        ->and($options['voicemails'])->toBe([['id' => 'none', 'label' => 'None'], ['id' => '20042', 'label' => 'Voice to Email']])
        ->and($panel->requests)->toBe(['GET https://example.tamartelecommunications.co.uk/phonedivert/huntgroup?huntgroup=157626']);
});

it('reads them from the first group when none is configured', function () {
    $panel = new OptionsPanel();

    expect(optionsService($panel, '')->panelOptions()['voicemails'])->toHaveCount(2)
        ->and(end($panel->requests))->toEndWith('?huntgroup=180000');
});

it('has nothing to offer for an account with no hunt groups', function () {
    expect(optionsService(new OptionsPanel(hasGroups: false), '')->panelOptions())
        ->toBe(['greetings' => [], 'voicemails' => []]);
});

// -- the Settings page -------------------------------------------------------------

it('offers the hunt-group settings at their defaults before the panel can be reached', function () {
    $html = renderSettingsWith(null);

    expect($html)->toContain(
        '<h2>Hunt group settings</h2>',
        'Once Tamar can reach the control panel, the announcements and voicemail boxes it offers are listed here.',
        'id="tamar-ring-timeout" name="ring_timeout" type="number" min="5" max="180" value="90"',
        '<select id="tamar-greeting" name="greeting"><option value="none" selected="selected">None</option></select>',
        '<option value="" selected="selected">Voice to Email</option>',
        '<option value="inorder" selected="selected">Hunt in-order</option>',
    );
});

it('lists the panel\'s announcements and voicemail boxes, and selects Voice to Email by name', function () {
    TamarSettings::save(['base_url' => 'https://example.tamartelecommunications.co.uk', 'username' => 'demo', 'password_plaintext' => 'pw']);

    $html = renderSettingsWith(optionsService(new OptionsPanel()));

    expect($html)->toContain(
        '<option value="dcbc6a61-b256-11eb-8b58-ac281572e7f5">CW - Western Service Office</option>',
        '<option value="20042" selected="selected">Voice to Email</option>',
    )->not->toContain('Once Tamar can reach the control panel');
});

it('keeps a saved box the panel no longer lists, rather than losing it on save', function () {
    TamarSettings::save(['base_url' => 'https://example.tamartelecommunications.co.uk', 'username' => 'demo', 'password_plaintext' => 'pw', 'voicemail' => '31337']);

    expect(renderSettingsWith(optionsService(new OptionsPanel())))
        ->toContain('<option value="31337" selected>Saved value 31337 (not in the control panel)</option>');
});
