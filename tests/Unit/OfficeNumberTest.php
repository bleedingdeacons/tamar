<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Psr\Container\ContainerInterface;
use Tamar\Admin\SettingsPage;
use Tamar\Admin\TamarSettings;

/*
 * The office number setting: its default, how a typed value is kept, and
 * its field on the Settings page.
 */

if (!defined('TAMAR_OPTION_KEY')) {
    define('TAMAR_OPTION_KEY', 'tamar_settings');
}

it('defaults to the office number until one is saved', function () {
    expect(TamarSettings::load()['office_number'])->toBe('0117 946 0754')
        ->and(TamarSettings::DEFAULT_OFFICE_NUMBER)->toBe('0117 946 0754');
});

it('keeps a saved office number as typed', function (string $typed, string $stored) {
    TamarSettings::save(['office_number' => $typed]);

    expect(TamarSettings::load()['office_number'])->toBe($stored);
})->with([
    'spaced' => ['0117 496 0000', '0117 496 0000'],
    'international' => ['+44 117 496 0000', '+44 117 496 0000'],
    'trimmed and squeezed' => ['  0117   496 0000 ', '0117 496 0000'],
    'punctuation dropped' => ['(0117) 496-0000', '0117 4960000'],
    'stray plus dropped' => ['0117+496 0000', '0117496 0000'],
]);

it('goes back to the default when the number is cleared', function (string $typed) {
    WpState::$options[TAMAR_OPTION_KEY] = ['office_number' => '0117 496 0000'];

    TamarSettings::save(['office_number' => $typed]);

    expect(TamarSettings::load()['office_number'])->toBe('0117 946 0754');
})->with(['empty' => [''], 'spaces' => ['   '], 'no digits' => ['office']]);

it('keeps the office number when a form without the field is saved', function () {
    WpState::$options[TAMAR_OPTION_KEY] = ['office_number' => '0117 496 0000'];

    TamarSettings::save(['base_url' => 'https://panel.example.test']);
    TamarSettings::saveHuntgroupId('157626');

    expect(TamarSettings::load()['office_number'])->toBe('0117 496 0000');
});

it('offers the office number on the Settings page', function () {
    $page = new SettingsPage(new class implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new \LogicException('Nothing is bound.');
        }

        public function has(string $id): bool
        {
            return false;
        }
    });

    ob_start();
    $page->renderSettings();
    $html = (string) ob_get_clean();

    expect($html)->toContain(
        '<label for="tamar-office-number">Office number</label>',
        'id="tamar-office-number" name="office_number" type="tel" class="regular-text" value="0117 946 0754"',
        'Left empty, it goes back to 0117 946 0754.',
    );
});
