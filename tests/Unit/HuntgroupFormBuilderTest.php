<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;

/*
 * The builder's contract is "encode a parsed state back into a body
 * Tamar's update endpoint will accept". The most important property to
 * lock down is round-trip fidelity: parsing the page, then encoding
 * the unchanged state, must produce a body whose decoded form
 * representation matches what a browser would have submitted.
 */

/**
 * @return array<string,string>
 */
function decodeBuilderBody(string $body): array
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

function builderFixture(): string
{
    return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
}

beforeEach(function () {
    $this->builder = new HuntgroupFormBuilder();
    $this->parser = new HuntgroupPageParser();
});

it('preserves all rows on an unchanged round trip', function () {
    $state = $this->parser->parse(builderFixture());
    $body = $this->builder->build($state);
    $decoded = decodeBuilderBody($body);

    // Top-level fields survive.
    expect($decoded['hg-name'])->toBe('New Rota')
        ->and($decoded['hg-id'])->toBe('157626')
        ->and($decoded['hunting'])->toBe('inorder')
        ->and($decoded['voicemail'])->toBe('20042')
        ->and($decoded['greeting'])->toBe('none');

    // Row 2 — destination preserved verbatim.
    expect($decoded['2_destination'])->toBe('01454 898476')
        ->and($decoded['2_description'])->toBe('Steve C')
        ->and($decoded['2_start'])->toBe('10:00')
        ->and($decoded['2_end'])->toBe('14:00')
        ->and($decoded['2_timeout'])->toBe('90');
});

it('omits unchecked checkboxes rather than zeroing them', function () {
    // HTML forms omit unchecked boxes; Tamar's PHP handler relies
    // on isset(). Emitting "0" would be read as "checked, value 0".
    $state = $this->parser->parse(builderFixture());
    $body = $this->builder->build($state);
    $decoded = decodeBuilderBody($body);

    // Row 1: Mon checked, Sun not.
    expect($decoded)->toHaveKey('1_mon')
        ->not->toHaveKey('1_sun')
        // Row 1: vm checked, q not.
        ->toHaveKey('1_vm')
        ->not->toHaveKey('1_q');
});

it('omits the enabled field for a disabled row', function () {
    $state = $this->parser->parse(builderFixture());
    $body = $this->builder->build($state);
    $decoded = decodeBuilderBody($body);

    // Row 4 was unchecked in the fixture.
    expect($decoded)->not->toHaveKey('4_enabled');
    // But the row itself still posts — its other fields are present.
    expect($decoded['4_destination'])->toBe('07931 901060');
});

it('falls back to inorder for an unknown hunting strategy', function () {
    // A typo or stale value shouldn't cause the upstream to reject
    // the form — fall back to a safe default.
    $state = $this->parser->parse(builderFixture());
    $state['meta']['hunting'] = 'made-up-strategy';
    $body = $this->builder->build($state);
    $decoded = decodeBuilderBody($body);
    expect($decoded['hunting'])->toBe('inorder');
});

it('updates the matching row by id in applyRuleEdit', function () {
    $state = $this->parser->parse(builderFixture());
    $edited = $state['rules'][1]; // row 2 (Steve C)
    $edited['label'] = 'Steve C (covering)';
    $edited['_raw']['description'] = 'Steve C (covering)';

    $next = $this->builder->applyRuleEdit($state, $edited);
    $body = $this->builder->build($next);
    $decoded = decodeBuilderBody($body);

    expect($decoded['2_description'])->toBe('Steve C (covering)')
        // Other rows untouched.
        ->and($decoded['3_description'])->toBe('Alan F');
});

it('appends a new row from applyRuleEdit when the id is empty', function () {
    $state = $this->parser->parse(builderFixture());
    $new = [
        'id' => '',
        'priority' => 0,
        'label' => 'Cover row',
        'match' => ['type' => 'time_window', 'value' => ['days' => ['sat'], 'from' => '09:00', 'to' => '12:00']],
        'target_id' => 'num:07700900000',
        'enabled' => true,
        '_raw' => ['destination' => '07700 900000', 'timeout' => 30, 'vm' => false, 'q' => false, 'description' => 'Cover row'],
    ];

    $next = $this->builder->applyRuleEdit($state, $new);
    $body = $this->builder->build($next);
    $decoded = decodeBuilderBody($body);

    // The new row should land at ordinal 5 (one past the existing four).
    expect($decoded['5_destination'])->toBe('07700 900000')
        ->and($decoded['5_description'])->toBe('Cover row')
        ->and($decoded['5_timeout'])->toBe('30')
        ->and($decoded)->toHaveKey('5_sat');
});

it('removes the row and renumbers in applyRuleDelete', function () {
    $state = $this->parser->parse(builderFixture());
    [$next, $removed] = $this->builder->applyRuleDelete($state, '2');

    expect($removed)->toBeTrue();

    $body = $this->builder->build($next);
    $decoded = decodeBuilderBody($body);

    // Former row 3 (Alan F) is now row 2 after renumbering.
    expect($decoded['2_description'])->toBe('Alan F')
        // Former row 4 (Jo W) is now row 3.
        ->and($decoded['3_description'])->toBe('Jo W')
        // There is no row 4 any more.
        ->and($decoded)->not->toHaveKey('4_destination');
});

it('returns false from applyRuleDelete when the id is not found', function () {
    $state = $this->parser->parse(builderFixture());
    [$next, $removed] = $this->builder->applyRuleDelete($state, 'no-such-row');

    expect($removed)->toBeFalse()
        ->and($next['rules'])->toBe($state['rules']);
});
