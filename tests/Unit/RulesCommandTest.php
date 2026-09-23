<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Core\BeaconContainer;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;
use Brain\Monkey\Functions;
use Tamar\Cli\RulesCommand;

/**
 * @param string[] $days
 */
function cliRule(string $label, int $priority, array $days, string $from, string $to, bool $enabled = true): ForwardingRule
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
function cliCommand(array $rules, array $targets = []): RulesCommand
{
    $service = \Mockery::mock(CallForwardingService::class);
    $service->allows('listRules')->andReturn($rules);
    $service->allows('listTargets')->andReturn($targets);

    $container = new BeaconContainer();
    $container->set(CallForwardingService::class, $service);

    return new RulesCommand($container);
}

/**
 * @param array<int, array<string, int|string>> $rows
 * @return string[]
 */
function labels(array $rows): array
{
    return array_column($rows, 'label');
}

it('lists every rule in hunt order', function () {
    $rows = cliCommand([])->rows([
        cliRule('Evening', 3, ['mon'], '18:00', '22:00'),
        cliRule('Morning', 1, ['tue'], '08:00', '12:00'),
        cliRule('Midday', 2, ['wed'], '12:00', '14:00'),
    ], []);

    expect(labels($rows))->toBe(['Morning', 'Midday', 'Evening']);
});

it('filters to a day and orders it by start time, hunt order breaking ties', function () {
    $rows = cliCommand([])->rows([
        cliRule('Evening', 1, ['mon'], '18:00', '22:00'),
        cliRule('Backup', 4, ['mon'], '08:00', '12:00'),
        cliRule('Morning', 2, ['mon'], '08:00', '12:00'),
        cliRule('Tuesday only', 3, ['tue'], '08:00', '12:00'),
    ], [], 'mon');

    expect(labels($rows))->toBe(['Morning', 'Backup', 'Evening']);
});

it('counts a catchall on every day and a window with no days ticked on none', function () {
    $catchall = new ForwardingRule(['id' => '9', 'priority' => 9, 'label' => 'Anyone', 'match' => ['type' => 'any'], 'target_id' => 'q:1']);
    $unticked = cliRule('Nobody', 3, [], '10:00', '14:00');

    $rows = cliCommand([])->rows([$catchall, $unticked], [], 'sun');

    expect(labels($rows))->toBe(['Anyone']);
});

it('flattens a rule into a row, resolving its target', function () {
    $rows = cliCommand([])->rows(
        [cliRule('Steve C', 2, ['thu', 'mon'], '10:00', '14:00', false)],
        [new ForwardingTarget(['id' => 'num:2', 'kind' => 'number', 'label' => 'Steve C', 'address' => '01454 898476'])]
    );

    expect($rows[0])->toBe([
        'id' => '2',
        'priority' => 2,
        'label' => 'Steve C',
        'match' => 'time_window',
        'days' => 'mon,thu',
        'from' => '10:00',
        'to' => '14:00',
        'target' => 'Steve C',
        'target_id' => 'num:2',
        'kind' => 'number',
        'address' => '01454 898476',
        'telephone' => '01454 898476',
        'enabled' => 'no',
    ]);
});

it('falls back to the raw target id when the target is unknown', function () {
    $rows = cliCommand([])->rows([cliRule('Ghost', 1, ['mon'], '10:00', '14:00')], []);

    expect($rows[0]['target'])->toBe('num:1')
        ->and($rows[0]['kind'])->toBe('');
});

it('prints the day\'s rows with the default fields', function () {
    $printed = null;
    Functions\when('WP_CLI\Utils\format_items')->alias(function ($format, $items, $fields) use (&$printed): void {
        $printed = [$format, $items, $fields];
    });

    cliCommand([
        cliRule('Monday', 1, ['mon'], '10:00', '14:00'),
        cliRule('Tuesday', 2, ['tue'], '10:00', '14:00'),
    ])->listRules([], ['day' => 'TUE']);

    expect($printed[0])->toBe('table')
        ->and(labels($printed[1]))->toBe(['Tuesday'])
        ->and($printed[2])->toBe(['priority', 'label', 'days', 'from', 'to', 'target', 'telephone', 'enabled']);
});

it('splits --fields and passes --format through', function () {
    $printed = null;
    Functions\when('WP_CLI\Utils\format_items')->alias(function ($format, $items, $fields) use (&$printed): void {
        $printed = [$format, $fields];
    });

    cliCommand([cliRule('Steve C', 1, ['mon'], '10:00', '14:00')])
        ->listRules([], ['format' => 'csv', 'fields' => 'id, label,address']);

    expect($printed)->toBe(['csv', ['id', 'label', 'address']]);
});

it('still prints an empty list for json, so a pipe gets []', function () {
    $printed = null;
    Functions\when('WP_CLI\Utils\format_items')->alias(function ($format, $items) use (&$printed): void {
        $printed = $items;
    });

    cliCommand([])->listRules([], ['format' => 'json']);

    expect($printed)->toBe([]);
});

it('refuses an unknown day', function () {
    cliCommand([])->listRules([], ['day' => 'funday']);
})->throws(\RuntimeException::class, 'Unknown day "funday"');

it('reports an upstream failure as a CLI error', function () {
    $service = \Mockery::mock(CallForwardingService::class);
    $service->allows('listRules')->andThrow(new ForwardingException('Login failed'));
    $container = new BeaconContainer();
    $container->set(CallForwardingService::class, $service);

    (new RulesCommand($container))->listRules([], []);
})->throws(\RuntimeException::class, 'Login failed');

it('leaves telephone empty for a target that is not a number', function () {
    $rows = cliCommand([])->rows(
        [cliRule('Out of hours', 1, ['sun'], '22:00', '23:59'), cliRule('Ghost', 2, ['sun'], '10:00', '14:00')],
        [new ForwardingTarget(['id' => 'num:1', 'kind' => 'voicemail', 'label' => 'Voice to Email', 'address' => '20042'])]
    );

    expect(array_column($rows, 'telephone'))->toBe(['', ''])
        ->and($rows[0]['address'])->toBe('20042');
});
