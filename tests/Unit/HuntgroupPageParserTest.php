<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\Interfaces\ForwardingException;
use BleedingDeacons\WpMocks\TestCase;
use Tamar\Forwarding\HuntgroupPageParser;

/**
 * Parser tests run against a trimmed but structurally faithful copy of
 * the real hunt-group edit page (tests/Fixtures/huntgroup_157626.html).
 *
 * The fixture is a real page; if Tamar changes their HTML, regenerate
 * it from a fresh control-panel response rather than hand-editing the
 * file. Hand-edits drift from reality and tests pass against a page
 * that no longer matches production.
 */
final class HuntgroupPageParserTest extends TestCase
{
    private HuntgroupPageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HuntgroupPageParser();
    }

    public function test_throws_on_empty_html(): void
    {
        $this->expectException(ForwardingException::class);
        $this->parser->parse('');
    }

    public function test_throws_on_login_redirect(): void
    {
        // A login-page response won't contain the auto-form or
        // huntingconfig table.
        $this->expectException(ForwardingException::class);
        $this->parser->parse('<html><body><form action="/login"><input name="username"></form></body></html>');
    }

    public function test_extracts_top_level_meta(): void
    {
        $result = $this->parser->parse($this->fixture());
        $meta = $result['meta'];

        self::assertSame('157626', $meta['huntgroup_id']);
        self::assertSame('New Rota', $meta['name']);
        self::assertSame('20042', $meta['voicemail']);
        self::assertSame('inorder', $meta['hunting']);
        // The greeting select has no `selected` attribute, so the
        // parser should fall back to the first option's value.
        self::assertSame('none', $meta['greeting']);
    }

    public function test_extracts_available_voicemails_and_greetings(): void
    {
        $result = $this->parser->parse($this->fixture());
        $meta = $result['meta'];

        // Voicemails include both the "None" sentinel and real boxes —
        // the parser exposes the raw <option> list; the synthesiser
        // filters "none" out when building targets.
        self::assertSame(
            [
                ['id' => 'none', 'label' => 'None'],
                ['id' => '20042', 'label' => 'Voice to Email'],
            ],
            $meta['voicemails_available']
        );

        self::assertCount(4, $meta['greetings_available']);
        self::assertSame('none', $meta['greetings_available'][0]['id']);
    }

    public function test_extracts_all_huntdest_rows(): void
    {
        $result = $this->parser->parse($this->fixture());
        self::assertCount(4, $result['rules']);
    }

    public function test_voicemail_row_resolves_to_vm_target(): void
    {
        $result = $this->parser->parse($this->fixture());
        $first = $result['rules'][0];

        self::assertSame('1', $first['id']);
        self::assertSame(1, $first['priority']);
        self::assertSame(['mon'], $first['match']['value']['days']);
        self::assertSame('00:00', $first['match']['value']['from']);
        self::assertSame('10:00', $first['match']['value']['to']);
        self::assertSame('vm:default', $first['target_id']);
        self::assertTrue($first['_raw']['vm']);
        self::assertSame('voicemail', $first['_raw']['destination']);
        self::assertTrue($first['enabled']);
    }

    public function test_number_row_normalises_id_by_digits_only(): void
    {
        $result = $this->parser->parse($this->fixture());
        $second = $result['rules'][1];

        self::assertSame('num:01454898476', $second['target_id']);
        // The raw destination preserves the original formatting so a
        // round-trip POST sends back exactly what the operator typed.
        self::assertSame('01454 898476', $second['_raw']['destination']);
        self::assertSame('Steve C', $second['label']);
        self::assertSame(90, $second['_raw']['timeout']);
    }

    public function test_disabled_row_carries_enabled_false(): void
    {
        $result = $this->parser->parse($this->fixture());
        $fourth = $result['rules'][3];

        self::assertFalse($fourth['enabled']);
        self::assertSame(['thu'], $fourth['match']['value']['days']);
        self::assertSame(60, $fourth['_raw']['timeout']);
    }

    public function test_targets_include_voicemail_box_and_distinct_numbers(): void
    {
        $result = $this->parser->parse($this->fixture());
        $targets = $result['targets'];

        $ids = array_map(fn($t) => $t['id'], $targets);
        self::assertContains('vm:20042', $ids);
        self::assertContains('num:01454898476', $ids);
        self::assertContains('num:07775513635', $ids);
        self::assertContains('num:07931901060', $ids);
        // The "None" voicemail option must NOT appear as a target.
        self::assertNotContains('vm:none', $ids);
    }

    public function test_csrf_is_empty_because_upstream_uses_session_cookies(): void
    {
        $result = $this->parser->parse($this->fixture());
        self::assertSame('', $result['csrf']);
    }

    // -- hunt-group list page --------------------------------------------

    public function test_parses_huntgroup_list_into_id_name_pairs(): void
    {
        $groups = $this->parser->parseHuntgroupList($this->listFixture());

        self::assertSame(
            [['id' => '157626', 'name' => 'New Rota']],
            $groups
        );
    }

    public function test_huntgroup_list_skips_the_disabled_placeholder_option(): void
    {
        $groups = $this->parser->parseHuntgroupList($this->listFixture());

        // The "Select from list:" placeholder (value="none", disabled)
        // must never surface as a selectable hunt group.
        $ids = array_map(static fn(array $g): string => $g['id'], $groups);
        self::assertNotContains('none', $ids);
    }

    public function test_huntgroup_list_returns_empty_when_account_has_no_groups(): void
    {
        // The select is present but holds only the placeholder — a valid
        // "no hunt groups yet" state, not an error.
        $html = '<select name="huntgroup"><option value="none" disabled>Select from list:</option></select>';
        self::assertSame([], $this->parser->parseHuntgroupList($html));
    }

    public function test_huntgroup_list_throws_on_empty_html(): void
    {
        $this->expectException(ForwardingException::class);
        $this->parser->parseHuntgroupList('');
    }

    public function test_huntgroup_list_throws_when_chooser_select_absent(): void
    {
        // A login-page (or any non-list) response has no huntgroup select.
        $this->expectException(ForwardingException::class);
        $this->parser->parseHuntgroupList('<html><body><form action="/login"><input name="username"></form></body></html>');
    }

    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_157626.html');
    }

    private function listFixture(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/huntgroup_list.html');
    }
}
