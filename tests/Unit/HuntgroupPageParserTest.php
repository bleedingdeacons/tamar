<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Interfaces\ForwardingException;
use Tamar\Forwarding\HuntgroupPageParser;

/*
 * Parser tests run against a trimmed but structurally faithful copy of
 * the real hunt-group edit page (tests/Fixtures/huntgroup_157626.html).
 *
 * The fixture is a real page; if Tamar changes their HTML, regenerate
 * it from a fresh control-panel response rather than hand-editing the
 * file. Hand-edits drift from reality and tests pass against a page
 * that no longer matches production.
 */

function parserFixture(): string
{
    return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
}

function parserListFixture(): string
{
    return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_list.html');
}

beforeEach(function () {
    $this->parser = new HuntgroupPageParser();
});

it('throws on empty html', function () {
    $this->parser->parse('');
})->throws(ForwardingException::class);

it('throws on a login redirect', function () {
    // A login-page response won't contain the auto-form or
    // huntingconfig table.
    $this->parser->parse('<html><body><form action="/login"><input name="username"></form></body></html>');
})->throws(ForwardingException::class);

it('extracts the top-level meta', function () {
    $result = $this->parser->parse(parserFixture());
    $meta = $result['meta'];

    expect($meta['huntgroup_id'])->toBe('157626')
        ->and($meta['name'])->toBe('New Rota')
        ->and($meta['voicemail'])->toBe('20042')
        ->and($meta['hunting'])->toBe('inorder')
        // The greeting select has no `selected` attribute, so the
        // parser should fall back to the first option's value.
        ->and($meta['greeting'])->toBe('none');
});

it('extracts the available voicemails and greetings', function () {
    $result = $this->parser->parse(parserFixture());
    $meta = $result['meta'];

    // Voicemails include both the "None" sentinel and real boxes —
    // the parser exposes the raw <option> list; the synthesiser
    // filters "none" out when building targets.
    expect($meta['voicemails_available'])->toBe([
        ['id' => 'none', 'label' => 'None'],
        ['id' => '20042', 'label' => 'Voice to Email'],
    ]);

    expect($meta['greetings_available'])->toHaveCount(4)
        ->and($meta['greetings_available'][0]['id'])->toBe('none');
});

it('extracts all huntdest rows', function () {
    $result = $this->parser->parse(parserFixture());
    expect($result['rules'])->toHaveCount(4);
});

it('resolves the voicemail row to a vm target', function () {
    $result = $this->parser->parse(parserFixture());
    $first = $result['rules'][0];

    expect($first['id'])->toBe('1')
        ->and($first['priority'])->toBe(1)
        ->and($first['match']['value']['days'])->toBe(['mon'])
        ->and($first['match']['value']['from'])->toBe('00:00')
        ->and($first['match']['value']['to'])->toBe('10:00')
        ->and($first['target_id'])->toBe('vm:default')
        ->and($first['_raw']['vm'])->toBeTrue()
        ->and($first['_raw']['destination'])->toBe('voicemail')
        ->and($first['enabled'])->toBeTrue();
});

it('normalises a number row id by digits only', function () {
    $result = $this->parser->parse(parserFixture());
    $second = $result['rules'][1];

    expect($second['target_id'])->toBe('num:01454898476')
        // The raw destination preserves the original formatting so a
        // round-trip POST sends back exactly what the operator typed.
        ->and($second['_raw']['destination'])->toBe('01454 898476')
        ->and($second['label'])->toBe('Steve C')
        ->and($second['_raw']['timeout'])->toBe(90);
});

it('carries enabled false on a disabled row', function () {
    $result = $this->parser->parse(parserFixture());
    $fourth = $result['rules'][3];

    expect($fourth['enabled'])->toBeFalse()
        ->and($fourth['match']['value']['days'])->toBe(['thu'])
        ->and($fourth['_raw']['timeout'])->toBe(60);
});

it('includes the voicemail box and distinct numbers in targets', function () {
    $result = $this->parser->parse(parserFixture());
    $targets = $result['targets'];

    $ids = array_map(fn($t) => $t['id'], $targets);
    expect($ids)->toContain('vm:20042')
        ->toContain('num:01454898476')
        ->toContain('num:07775513635')
        ->toContain('num:07931901060')
        // The "None" voicemail option must NOT appear as a target.
        ->not->toContain('vm:none');
});

it('leaves csrf empty because the upstream uses session cookies', function () {
    $result = $this->parser->parse(parserFixture());
    expect($result['csrf'])->toBe('');
});

// -- hunt-group list page --------------------------------------------

describe('hunt-group list page', function () {
    it('parses the huntgroup list into id/name pairs', function () {
        $groups = $this->parser->parseHuntgroupList(parserListFixture());

        expect($groups)->toBe([['id' => '157626', 'name' => 'New Rota']]);
    });

    it('skips the disabled placeholder option', function () {
        $groups = $this->parser->parseHuntgroupList(parserListFixture());

        // The "Select from list:" placeholder (value="none", disabled)
        // must never surface as a selectable hunt group.
        $ids = array_map(static fn(array $g): string => $g['id'], $groups);
        expect($ids)->not->toContain('none');
    });

    it('returns empty when the account has no groups', function () {
        // The select is present but holds only the placeholder — a valid
        // "no hunt groups yet" state, not an error.
        $html = '<select name="huntgroup"><option value="none" disabled>Select from list:</option></select>';
        expect($this->parser->parseHuntgroupList($html))->toBe([]);
    });

    it('throws on empty html', function () {
        $this->parser->parseHuntgroupList('');
    })->throws(ForwardingException::class);

    it('throws when the chooser select is absent', function () {
        // A login-page (or any non-list) response has no huntgroup select.
        $this->parser->parseHuntgroupList('<html><body><form action="/login"><input name="username"></form></body></html>');
    })->throws(ForwardingException::class);
});
