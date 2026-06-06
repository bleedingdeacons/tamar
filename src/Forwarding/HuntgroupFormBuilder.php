<?php

declare(strict_types=1);

namespace Tamar\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Re-encodes a parsed hunt-group page back into the form-urlencoded
 * POST body Tamar's `/phonedivert/huntgroup/update` endpoint expects.
 *
 * Why we re-encode the whole form, not just the changed row: the
 * upstream's update endpoint is "replace the whole rota" — it expects
 * to see every row, every top-level field, every checkbox. Sending
 * only the changed row would wipe everything else. So the builder
 * takes the parsed state as the baseline and only the operator's
 * edits are layered on top.
 *
 * The encoding rules below were derived from the page's HTML form
 * markup, not from a published API. If Tamar ever ships a documented
 * API, prefer that over this builder.
 *
 * Encoding rules (matter for round-tripping):
 *
 *  - Checked checkboxes appear with value "on" (the HTML default when
 *    no explicit value attribute is set). Unchecked checkboxes are
 *    OMITTED ENTIRELY — that's how HTML forms work, and Tamar's PHP
 *    handler relies on `isset($_POST['1_mon'])`. Sending "0" would be
 *    interpreted as "checked, value 0".
 *  - Row indices are 1-based and contiguous. If the operator deletes
 *    a row, every higher-numbered row shifts down. We renumber on save.
 *  - Hidden `N_order` is included for parity even though Tamar's
 *    handler treats table position as authoritative. Cheap insurance.
 *  - The `hg-id` field carries the canonical hunt group ID. Don't
 *    confuse with the URL `?huntgroup=` query param — they're equal
 *    in practice but the body field is what the handler reads.
 *  - The `voicemail` and `greeting` selects allow the literal string
 *    "none" as the unset value. We preserve whatever the parser saw.
 *
 * Unknown / unmodelled fields: if Tamar adds a new top-level field
 * we don't yet model, the builder won't include it and the upstream
 * will reset it to its default on save. The fix is to add the field
 * here and in the parser — they're paired by design.
 */
final class HuntgroupFormBuilder
{
    use \Tamar\Logger\HasLogger;

    /** Log to the shared "tamar" channel so log lines name the plugin. */
    protected static function logChannel(): string
    {
        return 'tamar';
    }

    /**
     * Encode the parsed state (optionally overlaid with edits) as the
     * application/x-www-form-urlencoded body Tamar expects.
     *
     * @param array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>} $state
     *        The parser's output — same shape; `targets` and `csrf` are ignored.
     * @return string
     */
    public function build(array $state): string
    {
        $meta = $state['meta'] ?? [];
        $rules = $state['rules'] ?? [];

        $pairs = [];

        // Top-level fields. Order doesn't matter semantically but
        // mirrors the on-page form for easier diffing in a packet
        // capture.
        $pairs[] = ['hg-name', (string) ($meta['name'] ?? '')];
        $pairs[] = ['hg-id', (string) ($meta['huntgroup_id'] ?? '')];
        $pairs[] = ['greeting', (string) ($meta['greeting'] ?? 'none')];
        $pairs[] = ['voicemail', (string) ($meta['voicemail'] ?? 'none')];
        $pairs[] = ['hunting', $this->normaliseHunting((string) ($meta['hunting'] ?? 'inorder'))];

        // Rota rows — renumber to 1..N contiguous, regardless of the
        // ordinals in the input.
        $ordinal = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $ordinal++;
            $this->appendRow($pairs, $ordinal, $rule);
        }

        // http_build_query gives us the right encoding (RFC 3986) and
        // handles the `&` joining; we feed it a flat assoc-list via
        // numeric keys so duplicate names are preserved if anyone ever
        // adds one (currently none).
        $flat = [];
        foreach ($pairs as [$name, $value]) {
            $flat[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        $encoded = implode('&', $flat);
        self::logDebug('Built hunt-group update body', [
            'row_count' => $ordinal,
            'field_count' => count($pairs),
            'body_bytes' => strlen($encoded),
        ]);
        return $encoded;
    }

    /**
     * Apply a single edited rule on top of the parsed state, returning
     * a new state. Used by saveRule so the caller can pass just the
     * one row that changed.
     *
     * Matching is by `id` (which the parser sets to the original row
     * ordinal as a string). A new rule — empty id — is appended.
     *
     * @param array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>} $state
     * @param array<string,mixed> $edited
     * @return array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>}
     */
    public function applyRuleEdit(array $state, array $edited): array
    {
        $rules = $state['rules'] ?? [];
        $editedId = (string) ($edited['id'] ?? '');

        if ($editedId === '') {
            // New row — append.
            $rules[] = $edited;
            $state['rules'] = $rules;
            self::logDebug('Applied rule edit: appended new row', [
                'row_count' => count($rules),
            ]);
            return $state;
        }

        foreach ($rules as $i => $existing) {
            if (!is_array($existing)) {
                continue;
            }
            if ((string) ($existing['id'] ?? '') === $editedId) {
                $rules[$i] = $this->mergeRow($existing, $edited);
                $state['rules'] = $rules;
                self::logDebug('Applied rule edit: merged in place', [
                    'rule_id' => $editedId,
                ]);
                return $state;
            }
        }

        // ID didn't match anything — treat as append rather than fail.
        // Save-then-list workflow then resolves the canonical ID.
        $rules[] = $edited;
        $state['rules'] = $rules;
        self::logWarning('Applied rule edit: id not found, appended as new row', [
            'rule_id' => $editedId,
            'row_count' => count($rules),
        ]);
        return $state;
    }

    /**
     * Remove a rule by ID. Returns [newState, removed] so the caller
     * can distinguish "did nothing" from "deleted".
     *
     * @param array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>} $state
     * @return array{0: array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>}, 1: bool}
     */
    public function applyRuleDelete(array $state, string $ruleId): array
    {
        $rules = $state['rules'] ?? [];
        $kept = [];
        $removed = false;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (!$removed && (string) ($rule['id'] ?? '') === $ruleId) {
                $removed = true;
                continue;
            }
            $kept[] = $rule;
        }
        $state['rules'] = $kept;
        self::logDebug('Applied rule delete', [
            'rule_id' => $ruleId,
            'removed' => $removed,
            'remaining' => count($kept),
        ]);
        return [$state, $removed];
    }

    // ------------------------------------------------------------------

    /**
     * Merge an edit onto an existing row, preserving fields the editor
     * didn't supply. `_raw` is merged shallowly so a destination-only
     * edit doesn't blow away the timeout, etc.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $edited
     * @return array<string,mixed>
     */
    private function mergeRow(array $existing, array $edited): array
    {
        $merged = array_replace($existing, $edited);
        $existingRaw = is_array($existing['_raw'] ?? null) ? $existing['_raw'] : [];
        $editedRaw = is_array($edited['_raw'] ?? null) ? $edited['_raw'] : [];
        $merged['_raw'] = array_replace($existingRaw, $editedRaw);
        return $merged;
    }

    /**
     * Emit one rota row as its component name/value pairs.
     *
     * @param list<array{0:string,1:string}> $pairs
     * @param array<string,mixed> $rule
     */
    private function appendRow(array &$pairs, int $ordinal, array $rule): void
    {
        $prefix = $ordinal . '_';
        $match = is_array($rule['match'] ?? null) ? $rule['match'] : [];
        $value = is_array($match['value'] ?? null) ? $match['value'] : [];
        $raw = is_array($rule['_raw'] ?? null) ? $rule['_raw'] : [];

        $days = is_array($value['days'] ?? null) ? $value['days'] : [];
        foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            if (in_array($day, $days, true)) {
                // Tamar's PHP handler reads `isset()` so any non-empty
                // value works; "on" matches the browser default when
                // no `value=""` is on the input.
                $pairs[] = [$prefix . $day, 'on'];
            }
            // Unchecked: deliberately not emitted.
        }

        $pairs[] = [$prefix . 'start', (string) ($value['from'] ?? '00:00')];
        $pairs[] = [$prefix . 'end', (string) ($value['to'] ?? '23:59')];

        if (!empty($raw['vm'])) {
            $pairs[] = [$prefix . 'vm', 'on'];
        }
        if (!empty($raw['q'])) {
            $pairs[] = [$prefix . 'q', 'on'];
        }

        $pairs[] = [$prefix . 'destination', (string) ($raw['destination'] ?? '')];
        $pairs[] = [$prefix . 'description', (string) ($raw['description'] ?? $rule['label'] ?? '')];
        $pairs[] = [$prefix . 'timeout', (string) ((int) ($raw['timeout'] ?? 20))];

        if (!empty($rule['enabled'])) {
            $pairs[] = [$prefix . 'enabled', 'on'];
        }

        // Hidden N_order — Tamar uses table position, but we send this
        // for parity with the rendered form. Cheap insurance against a
        // future server-side change.
        $pairs[] = [$prefix . 'order', (string) $ordinal];
    }

    /**
     * Coerce an arbitrary hunting strategy string back to one of the
     * values the upstream `<select>` accepts. Unknown values fall back
     * to 'inorder' — the safest default — rather than erroring.
     */
    private function normaliseHunting(string $raw): string
    {
        $allowed = ['inorder', 'random', 'last', 'mostidle', 'rotary', 'simultaneous'];
        return in_array($raw, $allowed, true) ? $raw : 'inorder';
    }
}
