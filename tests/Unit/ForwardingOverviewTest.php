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
 */
function renderOverview(array $rules, array $targets = []): string
{
    ob_start();
    (new ForwardingOverview())->render($rules, $targets);
    return (string) ob_get_clean();
}

/**
 * Labels in the order they appear under one day's heading.
 *
 * @return string[]
 */
function labelsUnder(string $html, string $heading): array
{
    $sections = explode('<section class="tamar-day">', $html);
    foreach ($sections as $section) {
        if (str_contains($section, '<h3 class="tamar-day__head">' . $heading . ' ')) {
            preg_match_all('#<div class="tamar-step__head"><strong>([^<]*)</strong>#', $section, $m);
            return $m[1];
        }
    }
    return [];
}

it('renders every day Monday to Sunday, even empty ones', function () {
    $html = renderOverview([overviewRule('Steve C', 1, ['mon'], '10:00', '14:00')]);

    preg_match_all('#<h3 class="tamar-day__head">(\w+)#', $html, $m);

    expect($m[1])->toBe(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])
        ->and($html)->toContain('Nothing forwarded on this day.');
});

it('lists a rule under each day it has ticked', function () {
    $html = renderOverview([overviewRule('Steve C', 1, ['mon', 'thu'], '10:00', '14:00')]);

    expect(labelsUnder($html, 'Monday'))->toBe(['Steve C'])
        ->and(labelsUnder($html, 'Thursday'))->toBe(['Steve C'])
        ->and(labelsUnder($html, 'Tuesday'))->toBe([]);
});

it('orders a day by start time, not hunt order', function () {
    $html = renderOverview([
        overviewRule('Evening', 1, ['mon'], '18:00', '22:00'),
        overviewRule('Morning', 2, ['mon'], '08:00', '12:00'),
    ]);

    expect(labelsUnder($html, 'Monday'))->toBe(['Morning', 'Evening']);
});

it('breaks a tie on start time by hunt order', function () {
    $html = renderOverview([
        overviewRule('Backup', 5, ['tue'], '09:00', '17:00'),
        overviewRule('First', 2, ['tue'], '09:00', '17:00'),
    ]);

    expect(labelsUnder($html, 'Tuesday'))->toBe(['First', 'Backup']);
});

it('shows the time window in the time column', function () {
    $html = renderOverview([overviewRule('Steve C', 1, ['mon'], '10:00', '14:00')]);

    expect($html)->toContain('<span class="tamar-step__time">10:00–14:00</span>');
});

it('puts a catchall under all seven days as all day', function () {
    $html = renderOverview([
        new ForwardingRule(['id' => '9', 'priority' => 9, 'label' => 'Anyone', 'match' => ['type' => 'any'], 'target_id' => 'q:1']),
    ]);

    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
        expect(labelsUnder($html, $day))->toBe(['Anyone']);
    }
    expect($html)->toContain('<span class="tamar-step__time">All day</span>')
        ->and($html)->not->toContain('Not scheduled by day');
});

it('keeps a window with no days ticked out of the week, rather than guessing', function () {
    $html = renderOverview([overviewRule('Nobody', 3, [], '10:00', '14:00')]);

    expect(labelsUnder($html, 'Not scheduled by day'))->toBe(['Nobody'])
        ->and(labelsUnder($html, 'Monday'))->toBe([])
        ->and($html)->toContain('No days ticked · 10:00–14:00');
});

it('keeps a match type that is not about time out of the week', function () {
    $html = renderOverview([
        new ForwardingRule([
            'id' => '4',
            'priority' => 4,
            'label' => 'VIP',
            'match' => ['type' => 'source_number', 'value' => '+441179000000'],
            'target_id' => 'num:4',
        ]),
    ]);

    expect(labelsUnder($html, 'Not scheduled by day'))->toBe(['VIP'])
        ->and($html)->toContain('Match: source_number');
});

it('counts active rules once, however many days they cover', function () {
    $html = renderOverview([
        overviewRule('Steve C', 1, ['mon', 'tue', 'wed'], '10:00', '14:00'),
        overviewRule('Off', 2, ['mon'], '14:00', '18:00', false),
    ]);

    expect($html)->toContain('1 active step');
});

it('still shows the voicemail fall-through once, after the days', function () {
    $html = renderOverview(
        [
            new ForwardingRule([
                'id' => '1',
                'priority' => 1,
                'label' => 'Out of hours',
                'match' => ['type' => 'time_window', 'value' => ['days' => ['sat', 'sun'], 'from' => '00:00', 'to' => '23:59']],
                'target_id' => 'vm:20042',
            ]),
        ],
        [new ForwardingTarget(['id' => 'vm:20042', 'kind' => 'voicemail', 'label' => 'Voice to Email', 'address' => '20042'])]
    );

    expect(substr_count($html, 'tamar-step--tail'))->toBe(1)
        ->and(strpos($html, 'tamar-step--tail'))->toBeGreaterThan(strrpos($html, 'tamar-day__head'));
});
