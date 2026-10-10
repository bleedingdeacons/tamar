<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;
use Tamar\Admin\ForwardingOverview;

/**
 * @param string[] $days
 */
function overviewRule(string $label, int $priority, array $days, string $from, string $to, bool $enabled = true): ForwardingRule
{
    return new ForwardingRule([
        'id' => (string) $priority,
        'priority' => $priority,
        'label' => $label,
        'match' => ['type' => 'time_window', 'value' => ['days' => $days, 'from' => $from, 'to' => $to]],
        'target_id' => 'num:' . $priority,
        'enabled' => $enabled,
    ]);
}

/**
 * @param ForwardingRule[]   $rules
 * @param ForwardingTarget[] $targets
 * @param array{name: string, greeting: string, voicemail: string, hunting: string, timeouts: array<string, int>}|null $huntgroup
 */
function renderOverview(array $rules, array $targets = [], ?array $huntgroup = null): string
{
    ob_start();
    (new ForwardingOverview())->render($rules, $targets, $huntgroup);
    return (string) ob_get_clean();
}

/**
 * @param array<string, int> $timeouts
 * @return array{name: string, greeting: string, voicemail: string, hunting: string, timeouts: array<string, int>}
 */
function overviewSummary(string $greeting = '', string $voicemail = '', array $timeouts = []): array
{
    return ['name' => 'New Rota', 'greeting' => $greeting, 'voicemail' => $voicemail, 'hunting' => 'Hunt in-order', 'timeouts' => $timeouts];
}

/**
 * Each body row's cells, as text, in table order.
 *
 * @return list<list<string>>
 */
function overviewRows(string $html): array
{
    preg_match_all('#<tr class="tamar-row[^"]*">(.*?)</tr>#s', $html, $rows);
    $out = [];
    foreach ($rows[1] as $row) {
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $row, $cells);
        $out[] = array_map(static function (string $cell): string {
            if (str_contains($cell, 'tamar-tick')) {
                return str_contains($cell, 'tamar-tick--on') ? 'x' : '.';
            }
            return trim(html_entity_decode(strip_tags($cell)));
        }, $cells[1]);
    }
    return $out;
}

it('lays a row out in the panel\'s column order', function () {
    $html = renderOverview(
        [overviewRule('Steve C', 2, ['mon', 'thu'], '10:00', '14:00')],
        [new ForwardingTarget(['id' => 'num:2', 'kind' => 'number', 'label' => 'Steve C', 'address' => '01454 898476'])],
        overviewSummary(timeouts: ['2' => 90]),
    );

    // order, Su Mo Tu We Th Fr Sa, start, end, VM, destination, comment, timeout, active
    expect(overviewRows($html))->toBe([
        ['1', '.', 'x', '.', '.', 'x', '.', '.', '10:00', '14:00', '.', '01454 898476', 'Steve C', '90', 'x'],
    ]);
});

it('lists rows in hunt order and numbers them from one', function () {
    $html = renderOverview([
        overviewRule('Second', 7, ['mon'], '08:00', '12:00'),
        overviewRule('First', 3, ['mon'], '18:00', '22:00'),
    ]);

    $rows = overviewRows($html);
    expect(array_column($rows, 0))->toBe(['1', '2'])
        ->and(array_column($rows, 12))->toBe(['First', 'Second']);
});

it('ticks VM and says voicemail for a row that diverts to the voicemail box', function () {
    $html = renderOverview(
        [new ForwardingRule([
            'id' => '1',
            'priority' => 1,
            'label' => '',
            'match' => ['type' => 'time_window', 'value' => ['days' => ['mon'], 'from' => '00:00', 'to' => '10:00']],
            'target_id' => 'vm:20042',
        ])],
        [new ForwardingTarget(['id' => 'vm:20042', 'kind' => 'voicemail', 'label' => 'Voice to Email', 'address' => '20042'])],
    );

    $row = overviewRows($html)[0];
    expect($row[10])->toBe('x')
        ->and($row[11])->toBe('voicemail')
        // An empty comment shows the panel's placeholder, greyed.
        ->and($row[12])->toBe('Description or comment')
        ->and($html)->toContain('tamar-pill tamar-pill--comment tamar-pill--empty');
});

it('dims an inactive row and leaves Active unticked', function () {
    $html = renderOverview([overviewRule('Jo W', 4, ['thu'], '18:00', '22:00', false)]);

    expect($html)->toContain('<tr class="tamar-row tamar-row--off">')
        ->and(overviewRows($html)[0][14])->toBe('.');
});

it('ticks every day of a catchall, all day', function () {
    $html = renderOverview([
        new ForwardingRule(['id' => '9', 'priority' => 9, 'label' => 'Anyone', 'match' => ['type' => 'any'], 'target_id' => 'q:1']),
    ]);

    expect(array_slice(overviewRows($html)[0], 1, 9))
        ->toBe(['x', 'x', 'x', 'x', 'x', 'x', 'x', '00:00', '23:59']);
});

it('ticks no days and shows no times for a match that is not about time', function () {
    $html = renderOverview([
        new ForwardingRule([
            'id' => '4',
            'priority' => 4,
            'label' => 'VIP',
            'match' => ['type' => 'source_number', 'value' => '+441179000000'],
            'target_id' => 'num:4',
        ]),
    ]);

    expect(array_slice(overviewRows($html)[0], 1, 9))
        ->toBe(['.', '.', '.', '.', '.', '.', '.', '—', '—']);
});

it('falls back to the raw target id when the target is unknown', function () {
    $html = renderOverview([overviewRule('Steve C', 1, ['mon'], '10:00', '14:00')]);

    expect(overviewRows($html)[0][11])->toBe('num:1');
});

it('shows the group settings across the top', function () {
    $html = renderOverview([overviewRule('Steve C', 1, ['mon'], '10:00', '14:00')], [], overviewSummary(voicemail: 'Voice to Email'));

    expect($html)->toContain('<legend class="tamar-card__legend">Hunt Group New Rota</legend>')
        ->and($html)->toContain('<p>Announcement</p><span class="tamar-pill">None</span>')
        ->and($html)->toContain('<p>Voicemail</p><span class="tamar-pill">Voice to Email</span>')
        ->and($html)->toContain('<p>Hunting type</p><span class="tamar-pill">Hunt in-order</span>');
});

it('puts the announcement notice above the rows and the voicemail notice below', function () {
    $html = renderOverview(
        [overviewRule('Steve C', 1, ['mon'], '10:00', '14:00')],
        [],
        overviewSummary(greeting: 'AI-Answerphone Message', voicemail: 'Voice to Email'),
    );

    $row = strpos($html, '<tr class="tamar-row');
    expect(strpos($html, 'Announcement will be played'))->toBeLessThan($row)
        ->and(strpos($html, 'Voicemail is enabled if calls are not answered.'))->toBeGreaterThan($row);
});

it('leaves out both notices when there is no announcement or voicemail', function () {
    $html = renderOverview([overviewRule('Steve C', 1, ['mon'], '10:00', '14:00')], [], overviewSummary());

    expect($html)->not->toContain('tamar-notice');
});

it('still renders the table without a summary, for a driver that cannot give one', function () {
    $html = renderOverview([overviewRule('Steve C', 1, ['mon'], '10:00', '14:00')]);

    expect($html)->not->toContain('tamar-settings')
        ->and($html)->not->toContain('tamar-notice')
        ->and($html)->toContain('<legend class="tamar-card__legend">Hunt Group</legend>')
        ->and(overviewRows($html)[0][13])->toBe('—');
});

it('says so when there are no rules', function () {
    expect(renderOverview([]))->toContain('No forwarding rules configured')
        ->and(renderOverview([]))->not->toContain('<table');
});
