<?php

declare(strict_types=1);

namespace Tamar\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;

/**
 * Read-only "current forwarding setup" view.
 *
 * The hunt group is really an ordered ring sequence: each rule forwards
 * to a target during a day/time window, lower priority rings first, and
 * a voicemail target acts as the fall-through. Two flat tables hide that
 * shape, so this renders it as a top-to-bottom call flow plus a grouped
 * reference of the known targets.
 *
 * Deliberately built from the Beacon CallForwardingService contract only
 * — the ForwardingRule getters and the target models returned by
 * listTargets() (getId/getLabel/getKind/getAddress). It never reaches
 * into Tamar-specific internals (the parser's `meta`, `_raw`, etc.), so
 * a sibling plugin that swaps the bound driver gets a working overview
 * for free.
 *
 * Note on scope: the rota name, hunting strategy, and greeting are NOT
 * exposed by the contract (they live in the parser's `meta` bucket), so
 * they can't be shown here without a new contract method. The header
 * line states the assumed "hunt in order" semantics rather than reading
 * a real strategy.
 */
final class ForwardingOverview
{
    /**
     * @param ForwardingRule[]   $rules   From CallForwardingService::listRules()
     * @param ForwardingTarget[] $targets From CallForwardingService::listTargets()
     *                                    — which is what the contract has always
     *                                    returned. The previous `object[]` said
     *                                    nothing, so every getter call below was
     *                                    a call on a shapeless object.
     */
    public function render(array $rules, array $targets): void
    {
        // Index targets by id so each rule can resolve its destination.
        $targetsById = [];
        foreach ($targets as $target) {
            $targetsById[$target->getId()] = $target;
        }

        // Hunt-in-order: lowest priority number rings first. usort is on a
        // local copy (arrays are passed by value), so the caller's order
        // is untouched.
        usort($rules, static fn(ForwardingRule $a, ForwardingRule $b): int
            => $a->getPriority() <=> $b->getPriority());

        $activeCount = 0;
        foreach ($rules as $rule) {
            if ($rule->isEnabled()) {
                $activeCount++;
            }
        }

        echo '<div class="tamar-overview">';
        $this->styles();

        echo '<div class="tamar-overview__head">';
        echo '<h2>' . esc_html__('Call flow', 'tamar') . '</h2>';
        echo '<span class="tamar-overview__hint">' . esc_html__('Hunt in order — rings top to bottom', 'tamar') . '</span>';
        echo '</div>';
        echo '<p class="tamar-overview__sub">'
            . esc_html(sprintf(
                /* translators: %d: number of active forwarding steps */
                _n('%d active step', '%d active steps', $activeCount, 'tamar'),
                $activeCount
            ))
            . '</p>';

        if ($rules === []) {
            echo '<p class="tamar-empty">'
                . esc_html__('No forwarding rules configured — Tamar is not routing calls for this hunt group.', 'tamar')
                . '</p>';
        } else {
            echo '<ol class="tamar-flow">';
            $step = 1;
            $lastVoicemail = null;
            foreach ($rules as $rule) {
                $target = $targetsById[$rule->getTargetId()] ?? null;
                if ($target !== null && $target->getKind() === 'voicemail') {
                    $lastVoicemail = $target;
                }
                $this->renderStep($step++, $rule, $target);
            }
            // If any step routes to voicemail, show it as the fall-through
            // tail so the "and finally…" behaviour is explicit.
            if ($lastVoicemail !== null) {
                $this->renderFallThrough($lastVoicemail);
            }
            echo '</ol>';
        }

        $this->renderTargets($targets);

        echo '</div>';
    }

    private function renderStep(int $step, ForwardingRule $rule, ?ForwardingTarget $target): void
    {
        $enabled = $rule->isEnabled();
        $classes = 'tamar-step' . ($enabled ? '' : ' tamar-step--off');
        $label = $rule->getLabel() !== '' ? $rule->getLabel() : __('(unnamed rule)', 'tamar');

        echo '<li class="' . esc_attr($classes) . '">';
        echo '<span class="tamar-step__num">' . esc_html((string) $step) . '</span>';
        echo '<div class="tamar-step__body">';

        echo '<div class="tamar-step__head">';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo $enabled
            ? '<span class="tamar-badge tamar-badge--on">' . esc_html__('Active', 'tamar') . '</span>'
            : '<span class="tamar-badge tamar-badge--off">' . esc_html__('Disabled', 'tamar') . '</span>';
        echo '</div>';

        echo '<div class="tamar-step__dest"><span class="dashicons dashicons-arrow-right-alt2"></span> '
            . $this->describeTarget($rule->getTargetId(), $target)
            . '</div>';

        $when = $this->describeWhen($rule);
        if ($when !== '') {
            echo '<div class="tamar-step__when"><span class="dashicons dashicons-clock"></span> '
                . esc_html($when) . '</div>';
        }

        echo '</div></li>';
    }

    private function renderFallThrough(ForwardingTarget $voicemail): void
    {
        $label = $voicemail->getLabel() !== '' ? $voicemail->getLabel() : $voicemail->getId();
        echo '<li class="tamar-step tamar-step--tail">';
        echo '<span class="tamar-step__num dashicons dashicons-redo"></span>';
        echo '<div class="tamar-step__body">';
        echo '<span class="dashicons dashicons-microphone"></span> ';
        echo esc_html__('Falls through to', 'tamar') . ' <strong>' . esc_html($label) . '</strong> ';
        echo '<span class="tamar-target__kind">' . esc_html__('voicemail', 'tamar') . '</span>';
        echo '</div></li>';
    }

    /**
     * Render a rule's destination. Resolves the target where possible;
     * falls back to the raw target id so nothing is silently dropped.
     */
    private function describeTarget(string $targetId, ?ForwardingTarget $target): string
    {
        if ($target === null) {
            return '<span class="tamar-target tamar-target--unknown"><code>'
                . esc_html($targetId) . '</code></span>';
        }

        $kind = $target->getKind();
        $icon = match ($kind) {
            'voicemail' => 'dashicons-microphone',
            'queue'     => 'dashicons-groups',
            'number'    => 'dashicons-phone',
            default     => 'dashicons-marker',
        };
        $label = $target->getLabel() !== '' ? $target->getLabel() : $target->getId();

        $out = '<span class="tamar-target">';
        $out .= '<span class="dashicons ' . esc_attr($icon) . '"></span> ';
        $out .= '<strong>' . esc_html($label) . '</strong>';
        if ($target->getAddress() !== '') {
            $out .= ' <span class="tamar-target__addr">' . esc_html($target->getAddress()) . '</span>';
        }
        $out .= ' <span class="tamar-target__kind">' . esc_html($kind) . '</span>';
        $out .= '</span>';

        return $out;
    }

    /**
     * Human description of a rule's match window. Handles the common
     * time-window shape (days + from/to) and degrades to the match type
     * name for anything else.
     */
    private function describeWhen(ForwardingRule $rule): string
    {
        $match = $rule->getMatch();
        $value = is_array($match['value'] ?? null) ? $match['value'] : [];
        $days  = is_array($value['days'] ?? null) ? $value['days'] : [];
        $from  = (string) ($value['from'] ?? '');
        $to    = (string) ($value['to'] ?? '');

        if ($from === '' && $to === '' && $days === []) {
            $type = $rule->getMatchType();
            return $type !== ''
                ? sprintf(/* translators: %s: match type */ __('Match: %s', 'tamar'), $type)
                : '';
        }

        $window = ($from !== '' || $to !== '')
            ? sprintf('%s–%s', $from !== '' ? $from : '00:00', $to !== '' ? $to : '23:59')
            : __('all day', 'tamar');

        return $this->formatDays($days) . ' · ' . $window;
    }

    /** @param string[] $days */
    private function formatDays(array $days): string
    {
        $order  = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $labels = [
            'mon' => __('Mon', 'tamar'), 'tue' => __('Tue', 'tamar'),
            'wed' => __('Wed', 'tamar'), 'thu' => __('Thu', 'tamar'),
            'fri' => __('Fri', 'tamar'), 'sat' => __('Sat', 'tamar'),
            'sun' => __('Sun', 'tamar'),
        ];

        $set = array_values(array_intersect($order, array_map('strtolower', $days)));

        if ($set === [] || $set === $order) {
            return __('Every day', 'tamar');
        }
        if ($set === ['mon', 'tue', 'wed', 'thu', 'fri']) {
            return __('Weekdays', 'tamar');
        }
        if ($set === ['sat', 'sun']) {
            return __('Weekends', 'tamar');
        }
        return implode(', ', array_map(static fn(string $d): string => $labels[$d], $set));
    }

    /** @param ForwardingTarget[] $targets */
    private function renderTargets(array $targets): void
    {
        echo '<h2 class="tamar-overview__targets-head">' . esc_html__('Known targets', 'tamar') . '</h2>';

        if ($targets === []) {
            echo '<p class="tamar-empty">' . esc_html__('No targets reported by the upstream.', 'tamar') . '</p>';
            return;
        }

        $byKind = [];
        foreach ($targets as $target) {
            $byKind[$target->getKind()][] = $target;
        }
        ksort($byKind);

        echo '<div class="tamar-targets">';
        foreach ($byKind as $kind => $group) {
            echo '<div class="tamar-targets__group">';
            echo '<div class="tamar-targets__kind">'
                . esc_html(ucfirst((string) $kind))
                . ' <span>(' . count($group) . ')</span></div>';
            echo '<ul>';
            foreach ($group as $target) {
                $label = $target->getLabel() !== '' ? $target->getLabel() : $target->getId();
                echo '<li><strong>' . esc_html($label) . '</strong>';
                if ($target->getAddress() !== '') {
                    echo ' <span class="tamar-target__addr">' . esc_html($target->getAddress()) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul></div>';
        }
        echo '</div>';
    }

    /**
     * Scoped inline styles. Kept inline (rather than enqueued) so the
     * view is a single self-contained drop-in; everything is namespaced
     * under .tamar-overview and uses WP admin colour cues so it sits
     * naturally in wp-admin and respects the active colour scheme.
     */
    private function styles(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        echo '<style>
.tamar-overview{max-width:760px;}
.tamar-overview__head{display:flex;align-items:baseline;justify-content:space-between;gap:1em;}
.tamar-overview__head h2{margin:0;}
.tamar-overview__hint{color:#646970;font-size:13px;}
.tamar-overview__sub{color:#646970;margin:.25em 0 1em;}
.tamar-overview__targets-head{margin-top:1.75em;}
.tamar-flow{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px;}
.tamar-step{display:flex;gap:12px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:.85em 1em;}
.tamar-step--off{opacity:.6;}
.tamar-step--tail{align-items:center;background:transparent;border-style:dashed;}
.tamar-step__num{flex:0 0 26px;height:26px;border-radius:50%;background:#f0f0f1;display:flex;align-items:center;justify-content:center;font-weight:600;color:#646970;font-size:13px;}
.tamar-step__body{flex:1;min-width:0;}
.tamar-step__head{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.tamar-step__head strong{font-size:15px;}
.tamar-step__dest{display:flex;align-items:center;flex-wrap:wrap;gap:4px;font-size:14px;margin-bottom:4px;}
.tamar-step__when{display:flex;align-items:center;gap:5px;color:#646970;font-size:13px;}
.tamar-step .dashicons{color:#646970;width:18px;height:18px;font-size:18px;}
.tamar-target{display:inline-flex;align-items:center;flex-wrap:wrap;gap:5px;}
.tamar-target__addr{font-family:Consolas,Monaco,monospace;font-size:12px;color:#646970;}
.tamar-target__kind{font-size:11px;padding:1px 7px;border-radius:999px;background:#f0f0f1;color:#646970;}
.tamar-target--unknown code{font-size:12px;}
.tamar-badge{font-size:11px;padding:2px 8px;border-radius:999px;}
.tamar-badge--on{background:#edfaef;color:#0a7d33;}
.tamar-badge--off{background:#f0f0f1;color:#646970;}
.tamar-targets{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
.tamar-targets__group{background:#f6f7f7;border-radius:6px;padding:.85em 1em;}
.tamar-targets__kind{font-size:13px;color:#646970;margin-bottom:8px;}
.tamar-targets__kind span{color:#8c8f94;}
.tamar-targets__group ul{margin:0;padding:0;list-style:none;}
.tamar-targets__group li{padding:3px 0;font-size:14px;}
.tamar-empty{color:#646970;}
</style>';
    }
}
