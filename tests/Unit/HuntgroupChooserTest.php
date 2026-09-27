<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Psr\Container\ContainerInterface;
use Tamar\Admin\SettingsPage;
use Tamar\Admin\TamarSettings;
use Tamar\Forwarding\PanelSessionStore;

/*
 * The hunt-group chooser lives on the Overview, above the call flow it
 * scopes, and saves through its own action rather than the Settings form.
 *
 * With no credentials configured the chooser never tries a login, so these
 * render the numeric-id fallback; the container binds no driver, so the
 * state panel below it says so and reaches nothing upstream either.
 */

if (!defined('TAMAR_OPTION_KEY')) {
    define('TAMAR_OPTION_KEY', 'tamar_settings');
}

function chooserPage(): SettingsPage
{
    return new SettingsPage(new class implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new \LogicException('Nothing is bound.');
        }

        public function has(string $id): bool
        {
            return false;
        }
    });
}

function capture(callable $render): string
{
    ob_start();
    $render();
    return (string) ob_get_clean();
}

it('draws the chooser on the Overview, ahead of the call flow', function () {
    WpState::$options[TAMAR_OPTION_KEY] = ['huntgroup_id' => '157626'];

    $html = capture(fn () => chooserPage()->renderOverview());

    expect($html)->toContain(
        'name="action" value="tamar_select_huntgroup"',
        'id="tamar-huntgroup-id" name="huntgroup_id" type="text" class="regular-text" value="157626"',
        '>Save</button>',
        'href="https://example.test/wp-admin/admin.php?page=tamar-overview" class="button">Refresh</a>',
    )->and(strpos($html, 'tamar_select_huntgroup'))
        ->toBeLessThan(strpos($html, 'Current forwarding state'));
});

it('shows a viewer the hunt group and Refresh, but nothing to save with', function () {
    WpState::$deniedCaps = ['beacon_manage_forwarding'];

    $html = capture(fn () => chooserPage()->renderOverview());

    expect($html)->toContain('name="huntgroup_id" type="text" class="regular-text" value="" disabled>', '>Refresh</a>')
        ->and($html)->not->toContain('>Save</button>');
});

it('no longer offers the hunt group on the Settings page', function () {
    $html = capture(fn () => chooserPage()->renderSettings());

    expect($html)->toContain('name="base_url"')
        ->and($html)->not->toContain('huntgroup_id', 'Refresh');
});

it('refuses a hunt-group change from a role that cannot manage forwarding', function () {
    WpState::$deniedCaps = ['beacon_manage_forwarding'];
    WpState::$options[TAMAR_OPTION_KEY] = ['huntgroup_id' => '157626'];
    $_POST = ['huntgroup_id' => '999'];

    try {
        chooserPage()->handleSelectHuntgroup();
    } finally {
        $_POST = [];
        expect(TamarSettings::load()['huntgroup_id'])->toBe('157626');
    }
})->throws(WpDieException::class, 'You do not have permission to change Tamar settings.');

it('changes only the hunt group, keeping TLS verification and the panel session', function () {
    WpState::$options[TAMAR_OPTION_KEY] = [
        'base_url' => 'https://panel.example.test',
        'username' => 'demo',
        'password_cipher' => 'plain:cHc=',
        'huntgroup_id' => '157626',
        'verify_tls' => true,
        'timeout' => 30,
    ];
    WpState::$options[PanelSessionStore::OPTION] = ['kept' => true];

    TamarSettings::saveHuntgroupId(' #20042 ');

    expect(TamarSettings::load())->toMatchArray([
        'base_url' => 'https://panel.example.test',
        'username' => 'demo',
        'password_cipher' => 'plain:cHc=',
        'huntgroup_id' => '20042',
        'verify_tls' => true,
        'timeout' => 30,
    ])->and(WpState::$options[PanelSessionStore::OPTION])->toBe(['kept' => true]);
});
