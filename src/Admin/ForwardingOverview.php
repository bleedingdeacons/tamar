<?php

declare(strict_types=1);

namespace Tamar\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;

/**
 * Read-only "current forwarding setup" view, laid out like the panel's
 * own hunt-group page (/phonedivert/huntgroup?huntgroup=<id>).
 *
 * The page is one card: the group's Name, Announcement, Voicemail and
 * Hunting type across the top, then an "Existing configuration" table
 * with a row per rota line — order, Su–Sa, start and end time, VM, the
 * number calls divert to, a comment, the ring timeout and whether the
 * line is active. Above the rows sits a notice when an announcement
 * plays first, and below them one when unanswered calls go to
 * voicemail, as the panel shows them. Mirroring it means an operator
 * who knows one screen can read the other without translation.
 *
 * The rows are built from the Beacon CallForwardingService contract
 * only — the ForwardingRule getters and the targets from listTargets().
 * The group's settings and the timeouts are not in the contract, so
 * they come in as an optional $huntgroup summary (Tamar's driver
 * supplies it, see HuntgroupCallForwardingService::huntgroupSummary()).
 * Without one, the settings row and both notices are left out and each
 * timeout shows as "—", so a sibling plugin that swaps the bound driver
 * still gets a working table.
 *
 * @phpstan-type HuntgroupSummary array{name: string, greeting: string, voicemail: string, hunting: string, timeouts: array<string, int>}
 */
final class ForwardingOverview
{
    /** Day codes as the time-window match stores them, in the panel's column order. */
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * @param ForwardingRule[]      $rules     From CallForwardingService::listRules()
     * @param ForwardingTarget[]    $targets   From CallForwardingService::listTargets()
     * @param HuntgroupSummary|null $huntgroup The group's settings and timeouts, when the
     *                                         driver can say.
     */
    public function render(array $rules, array $targets, ?array $huntgroup = null): void
    {
        $targetsById = [];
        foreach ($targets as $target) {
            $targetsById[$target->getId()] = $target;
        }

        // The panel lists rows in hunt order. usort is on a local copy,
        // so the caller's order is untouched.
        usort($rules, static fn(ForwardingRule $a, ForwardingRule $b): int
            => $a->getPriority() <=> $b->getPriority());

        echo '<div class="tamar-overview">';
        $this->styles();

        echo '<fieldset class="tamar-card">';
        $name = $huntgroup['name'] ?? '';
        echo '<legend class="tamar-card__legend">'
            . esc_html($name !== ''
                /* translators: %s: hunt group name */
                ? sprintf(__('Hunt Group %s', 'tamar'), $name)
                : __('Hunt Group', 'tamar'))
            . '</legend>';

        if ($huntgroup !== null) {
            $this->renderSettings($huntgroup);
        }

        echo '<h5 class="tamar-card__title">' . esc_html__('Existing configuration', 'tamar') . '</h5>';

        if ($rules === []) {
            echo '<p class="tamar-empty">'
                . esc_html__('No forwarding rules configured — Tamar is not routing calls for this hunt group.', 'tamar')
                . '</p>';
        } else {
            $this->renderTable($rules, $targetsById, $huntgroup);
        }

        echo '</fieldset>';
        echo '</div>';
    }

    /** @param HuntgroupSummary $huntgroup */
    private function renderSettings(array $huntgroup): void
    {
        $fields = [
            __('Name', 'tamar') => $huntgroup['name'],
            __('Announcement', 'tamar') => $huntgroup['greeting'] !== '' ? $huntgroup['greeting'] : __('None', 'tamar'),
            __('Voicemail', 'tamar') => $huntgroup['voicemail'] !== '' ? $huntgroup['voicemail'] : __('None', 'tamar'),
            __('Hunting type', 'tamar') => $huntgroup['hunting'],
        ];

        echo '<div class="tamar-settings">';
        foreach ($fields as $label => $value) {
            echo '<div class="tamar-settings__field">';
            echo '<p>' . esc_html($label) . '</p>';
            echo $this->pill($value);
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * @param ForwardingRule[]                $rules Already in hunt order.
     * @param array<string, ForwardingTarget> $targetsById
     * @param HuntgroupSummary|null           $huntgroup
     */
    private function renderTable(array $rules, array $targetsById, ?array $huntgroup): void
    {
        echo '<div class="tamar-table-wrap"><table class="tamar-table">';
        echo '<thead>';
        echo '<tr><th rowspan="2">&nbsp;</th>'
            . '<th colspan="9">' . esc_html__('Enable on which days?', 'tamar') . '</th>'
            . '<th rowspan="2">' . esc_html__('VM', 'tamar') . '</th>'
            . '<th rowspan="2">' . esc_html__('Divert my calls to:', 'tamar') . '</th>'
            . '<th rowspan="2">' . esc_html__('Comment', 'tamar') . '</th>'
            . '<th rowspan="2">' . esc_html__('Timeout', 'tamar') . '</th>'
            . '<th rowspan="2">' . esc_html__('Active', 'tamar') . '</th></tr>';
        echo '<tr>';
        foreach (self::DAYS as $day) {
            echo '<th class="tamar-table__day">' . esc_html($this->dayAbbrev($day)) . '</th>';
        }
        echo '<th>' . esc_html__('Start time', 'tamar') . '</th>'
            . '<th>' . esc_html__('End time', 'tamar') . '</th>';
        echo '</tr>';
        echo '</thead><tbody>';

        if ($huntgroup !== null && $huntgroup['greeting'] !== '') {
            echo '<tr class="tamar-notice tamar-notice--info"><td colspan="15">'
                . esc_html__('Announcement will be played before we try to connect the call.', 'tamar')
                . '</td></tr>';
        }

        $position = 0;
        foreach ($rules as $rule) {
            $position++;
            $timeout = $huntgroup['timeouts'][$rule->getId()] ?? null;
            $this->renderRow($position, $rule, $targetsById[$rule->getTargetId()] ?? null, $timeout);
        }

        if ($huntgroup !== null && $huntgroup['voicemail'] !== '') {
            echo '<tr class="tamar-notice tamar-notice--warning"><td colspan="15">'
                . esc_html__('Voicemail is enabled if calls are not answered.', 'tamar')
                . '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private function renderRow(int $position, ForwardingRule $rule, ?ForwardingTarget $target, ?int $timeout): void
    {
        $enabled = $rule->isEnabled();
        echo '<tr class="' . esc_attr('tamar-row' . ($enabled ? '' : ' tamar-row--off')) . '">';
        echo '<td class="tamar-table__order">' . $position . '</td>';

        $days = $this->ruleDays($rule);
        foreach (self::DAYS as $day) {
            echo '<td class="tamar-table__day">' . $this->tick(in_array($day, $days, true), $this->dayName($day)) . '</td>';
        }

        [$from, $to] = $this->window($rule);
        echo '<td>' . $this->pill($from, 'tamar-pill--time') . '</td>';
        echo '<td>' . $this->pill($to, 'tamar-pill--time') . '</td>';

        echo '<td class="tamar-table__check">' . $this->tick($this->isVoicemail($rule, $target), __('Voicemail', 'tamar')) . '</td>';
        echo '<td>' . $this->pill($this->destination($rule, $target), 'tamar-pill--dest', __('Destination', 'tamar')) . '</td>';
        echo '<td>' . $this->pill($rule->getLabel(), 'tamar-pill--comment', __('Description or comment', 'tamar')) . '</td>';
        echo '<td>' . $this->pill($timeout !== null ? (string) $timeout : '—', 'tamar-pill--timeout') . '</td>';
        echo '<td class="tamar-table__check">' . $this->tick($enabled, __('Active', 'tamar')) . '</td>';
        echo '</tr>';
    }

    /**
     * A read-only stand-in for one of the panel's rounded inputs. An
     * empty value shows the placeholder greyed out, as the panel does.
     */
    private function pill(string $value, string $modifier = '', string $placeholder = ''): string
    {
        $classes = trim('tamar-pill ' . $modifier . ($value === '' ? ' tamar-pill--empty' : ''));
        return '<span class="' . esc_attr($classes) . '">'
            . esc_html($value !== '' ? $value : $placeholder)
            . '</span>';
    }

    /**
     * A read-only stand-in for one of the panel's checkboxes — a real
     * disabled checkbox would grey out and read as "not applicable".
     */
    private function tick(bool $on, string $label): string
    {
        return '<span class="' . esc_attr('tamar-tick' . ($on ? ' tamar-tick--on' : '')) . '" role="img" aria-label="'
            . esc_attr(sprintf('%s: %s', $label, $on ? __('yes', 'tamar') : __('no', 'tamar')))
            . '"></span>';
    }

    /**
     * Days a rule rings on. A catchall rings every day; a match type that
     * isn't a time window ticks none, as the contract says nothing about
     * its days.
     *
     * @return string[]
     */
    private function ruleDays(ForwardingRule $rule): array
    {
        if ($rule->isCatchall()) {
            return self::DAYS;
        }
        if ($rule->getMatchType() !== 'time_window') {
            return [];
        }
        $days = $this->windowValue($rule)['days'] ?? null;
        return is_array($days) ? array_map(static fn($d): string => strtolower((string) $d), $days) : [];
    }

    /**
     * A rule's start and end time. A catchall covers the whole day; a
     * match type that isn't a time window has none to show.
     *
     * @return array{string, string}
     */
    private function window(ForwardingRule $rule): array
    {
        if ($rule->isCatchall()) {
            return ['00:00', '23:59'];
        }
        if ($rule->getMatchType() !== 'time_window') {
            return ['—', '—'];
        }
        $value = $this->windowValue($rule);
        $from = (string) ($value['from'] ?? '');
        $to = (string) ($value['to'] ?? '');
        return [$from !== '' ? $from : '00:00', $to !== '' ? $to : '23:59'];
    }

    /** @return array<mixed> */
    private function windowValue(ForwardingRule $rule): array
    {
        $match = $rule->getMatch();
        return is_array($match['value'] ?? null) ? $match['value'] : [];
    }

    private function isVoicemail(ForwardingRule $rule, ?ForwardingTarget $target): bool
    {
        return $target !== null
            ? $target->getKind() === 'voicemail'
            : str_starts_with($rule->getTargetId(), 'vm:');
    }

    /**
     * What the panel's "Divert my calls to" box would hold: the number,
     * or the word voicemail/queue. An unresolved target falls back to its
     * raw id so nothing is silently dropped; an empty one stays empty.
     */
    private function destination(ForwardingRule $rule, ?ForwardingTarget $target): string
    {
        if ($this->isVoicemail($rule, $target)) {
            return 'voicemail';
        }
        if ($target === null) {
            $id = $rule->getTargetId();
            return $id === 'num:' ? '' : $id;
        }
        if ($target->getKind() === 'queue') {
            return 'queue';
        }
        return $target->getAddress() !== '' ? $target->getAddress() : $target->getId();
    }

    private function dayAbbrev(string $day): string
    {
        return match ($day) {
            'sun' => _x('Su', 'Sunday, abbreviated', 'tamar'),
            'mon' => _x('Mo', 'Monday, abbreviated', 'tamar'),
            'tue' => _x('Tu', 'Tuesday, abbreviated', 'tamar'),
            'wed' => _x('We', 'Wednesday, abbreviated', 'tamar'),
            'thu' => _x('Th', 'Thursday, abbreviated', 'tamar'),
            'fri' => _x('Fr', 'Friday, abbreviated', 'tamar'),
            default => _x('Sa', 'Saturday, abbreviated', 'tamar'),
        };
    }

    private function dayName(string $day): string
    {
        return match ($day) {
            'sun' => __('Sunday', 'tamar'),
            'mon' => __('Monday', 'tamar'),
            'tue' => __('Tuesday', 'tamar'),
            'wed' => __('Wednesday', 'tamar'),
            'thu' => __('Thursday', 'tamar'),
            'fri' => __('Friday', 'tamar'),
            default => __('Saturday', 'tamar'),
        };
    }

    /**
     * Scoped inline styles, kept inline so the view is a single
     * self-contained drop-in; everything is namespaced under
     * .tamar-overview. Colours, radii and type sizes are the panel's own
     * (its Bootstrap theme), so the two screens look alike. Public so
     * the Overview page can print them before its hunt-group chooser,
     * which uses the same card; they print once per request.
     */
    public function styles(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        echo '<style>
.tamar-overview{--tamar-ink:#212529;--tamar-purple:#382e62;--tamar-line:#dee2e6;--tamar-input:#ced4da;color:var(--tamar-ink);}
.tamar-card{background:#fff;border:1px solid #d9dbe6;border-radius:10px;padding:24px;margin:20px 0;font-size:14px;line-height:1.5;}
.tamar-card__legend{float:left;width:100%;padding:0;margin:0 0 8px;font-size:18px;font-weight:700;color:#888;}
.tamar-card__legend+*{clear:left;}
.tamar-card__title{font-size:20px;font-weight:500;color:var(--tamar-purple);margin:32px 0 16px;}
.tamar-settings{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 24px;}
.tamar-settings__field p{font-size:15px;margin:0 0 8px;}
.tamar-settings .tamar-pill{display:block;}
.tamar-pill{display:inline-block;box-sizing:border-box;min-height:36px;padding:6px 12px;border:1px solid var(--tamar-input);border-radius:20px;background:#fff;font-size:15px;line-height:22px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tamar-pill--empty{color:#8c9196;}
.tamar-pill--time{min-width:72px;text-align:center;font-variant-numeric:tabular-nums;}
.tamar-pill--dest{min-width:140px;}
.tamar-pill--comment{min-width:160px;max-width:220px;}
.tamar-pill--timeout{min-width:52px;text-align:center;}
.tamar-table-wrap{overflow-x:auto;}
.tamar-table{border-collapse:collapse;width:100%;margin:0 0 8px;}
.tamar-table th{font-weight:700;text-align:left;vertical-align:bottom;padding:8px;border-bottom:1px solid var(--tamar-line);white-space:nowrap;}
.tamar-table td{padding:8px;border-bottom:1px solid var(--tamar-line);vertical-align:middle;}
.tamar-table .tamar-table__day{text-align:center;padding-left:2px;padding-right:2px;}
.tamar-table__check{text-align:center;}
.tamar-table__order{font-weight:600;color:#646970;text-align:center;width:24px;}
.tamar-row--off td{opacity:.55;}
.tamar-tick{display:inline-block;width:14px;height:14px;box-sizing:border-box;border:1px solid #767676;border-radius:3px;background:#fff;vertical-align:middle;position:relative;}
.tamar-tick--on{background:#0075ff;border-color:#0075ff;}
.tamar-tick--on::after{content:"";position:absolute;left:4px;top:1px;width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg);}
.tamar-notice td{padding:12px 16px;}
.tamar-notice--info td{background:#cfe2ff;color:#084298;border-bottom-color:#b6d4fe;}
.tamar-notice--warning td{background:#fff3cd;color:#664d03;border-bottom-color:#ffecb5;}
.tamar-empty{color:#646970;}
.tamar-card--chooser .tamar-card__title{margin-top:0;}
.tamar-card--chooser p{margin:0 0 8px;}
.tamar-card--chooser select,.tamar-card--chooser input[type=text]{border-radius:20px;border-color:var(--tamar-input);padding:4px 32px 4px 12px;min-width:240px;}
</style>';
    }
}
