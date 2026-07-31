<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use BleedingDeacons\WpMocks\TestCase;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;

/**
 * The builder's contract is "encode a parsed state back into a body
 * Tamar's update endpoint will accept". The most important property to
 * lock down is round-trip fidelity: parsing the page, then encoding
 * the unchanged state, must produce a body whose decoded form
 * representation matches what a browser would have submitted.
 */
final class HuntgroupFormBuilderTest extends TestCase
{
    private HuntgroupFormBuilder $builder;
    private HuntgroupPageParser $parser;

    protected function setUp(): void
    {
        $this->builder = new HuntgroupFormBuilder();
        $this->parser = new HuntgroupPageParser();
    }

    public function test_unchanged_round_trip_preserves_all_rows(): void
    {
        $state = $this->parser->parse($this->fixture());
        $body = $this->builder->build($state);
        $decoded = $this->decode($body);

        // Top-level fields survive.
        self::assertSame('New Rota', $decoded['hg-name']);
        self::assertSame('157626', $decoded['hg-id']);
        self::assertSame('inorder', $decoded['hunting']);
        self::assertSame('20042', $decoded['voicemail']);
        self::assertSame('none', $decoded['greeting']);

        // Row 2 — destination preserved verbatim.
        self::assertSame('01454 898476', $decoded['2_destination']);
        self::assertSame('Steve C', $decoded['2_description']);
        self::assertSame('10:00', $decoded['2_start']);
        self::assertSame('14:00', $decoded['2_end']);
        self::assertSame('90', $decoded['2_timeout']);
    }

    public function test_unchecked_checkboxes_are_omitted_not_zeroed(): void
    {
        // HTML forms omit unchecked boxes; Tamar's PHP handler relies
        // on isset(). Emitting "0" would be read as "checked, value 0".
        $state = $this->parser->parse($this->fixture());
        $body = $this->builder->build($state);
        $decoded = $this->decode($body);

        // Row 1: Mon checked, Sun not.
        self::assertArrayHasKey('1_mon', $decoded);
        self::assertArrayNotHasKey('1_sun', $decoded);
        // Row 1: vm checked, q not.
        self::assertArrayHasKey('1_vm', $decoded);
        self::assertArrayNotHasKey('1_q', $decoded);
    }

    public function test_disabled_row_omits_enabled_field(): void
    {
        $state = $this->parser->parse($this->fixture());
        $body = $this->builder->build($state);
        $decoded = $this->decode($body);

        // Row 4 was unchecked in the fixture.
        self::assertArrayNotHasKey('4_enabled', $decoded);
        // But the row itself still posts — its other fields are present.
        self::assertSame('07931 901060', $decoded['4_destination']);
    }

    public function test_unknown_hunting_strategy_falls_back_to_inorder(): void
    {
        // A typo or stale value shouldn't cause the upstream to reject
        // the form — fall back to a safe default.
        $state = $this->parser->parse($this->fixture());
        $state['meta']['hunting'] = 'made-up-strategy';
        $body = $this->builder->build($state);
        $decoded = $this->decode($body);
        self::assertSame('inorder', $decoded['hunting']);
    }

    public function test_applyRuleEdit_updates_matching_row_by_id(): void
    {
        $state = $this->parser->parse($this->fixture());
        $edited = $state['rules'][1]; // row 2 (Steve C)
        $edited['label'] = 'Steve C (covering)';
        $edited['_raw']['description'] = 'Steve C (covering)';

        $next = $this->builder->applyRuleEdit($state, $edited);
        $body = $this->builder->build($next);
        $decoded = $this->decode($body);

        self::assertSame('Steve C (covering)', $decoded['2_description']);
        // Other rows untouched.
        self::assertSame('Alan F', $decoded['3_description']);
    }

    public function test_applyRuleEdit_with_empty_id_appends_new_row(): void
    {
        $state = $this->parser->parse($this->fixture());
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
        $decoded = $this->decode($body);

        // The new row should land at ordinal 5 (one past the existing four).
        self::assertSame('07700 900000', $decoded['5_destination']);
        self::assertSame('Cover row', $decoded['5_description']);
        self::assertSame('30', $decoded['5_timeout']);
        self::assertArrayHasKey('5_sat', $decoded);
    }

    public function test_applyRuleDelete_removes_row_and_renumbers(): void
    {
        $state = $this->parser->parse($this->fixture());
        [$next, $removed] = $this->builder->applyRuleDelete($state, '2');

        self::assertTrue($removed);

        $body = $this->builder->build($next);
        $decoded = $this->decode($body);

        // Former row 3 (Alan F) is now row 2 after renumbering.
        self::assertSame('Alan F', $decoded['2_description']);
        // Former row 4 (Jo W) is now row 3.
        self::assertSame('Jo W', $decoded['3_description']);
        // There is no row 4 any more.
        self::assertArrayNotHasKey('4_destination', $decoded);
    }

    public function test_applyRuleDelete_returns_false_when_id_not_found(): void
    {
        $state = $this->parser->parse($this->fixture());
        [$next, $removed] = $this->builder->applyRuleDelete($state, 'no-such-row');

        self::assertFalse($removed);
        self::assertSame($state['rules'], $next['rules']);
    }

    /**
     * @return array<string,string>
     */
    private function decode(string $body): array
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

    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
    }
}
