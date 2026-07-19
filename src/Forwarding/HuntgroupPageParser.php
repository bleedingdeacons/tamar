<?php

declare(strict_types=1);

namespace Tamar\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Interfaces\ForwardingException;

/**
 * Parses Tamar Telecommunications' hunt-group edit page.
 *
 * The page (https://www.tamartelecommunications.co.uk/phonedivert/huntgroup?huntgroup=<id>)
 * is a single hunt group's editor, not a generic rules list — so what we
 * extract is one Beacon "rule" per `<tr class="huntdest">` row, plus
 * top-level metadata (rota name, announcement, voicemail box, hunting
 * strategy) carried in a separate `meta` bucket.
 *
 * Why DOMDocument and not regex? The upstream's HTML is generated and
 * full of dynamic class names, conditional blocks, and inline scripts.
 * The structure we depend on is small (one named form, predictable
 * `<ordinal>_<field>` input names, a `huntdest` row class) and DOM
 * queries survive whitespace and attribute-order changes that would
 * break a regex.
 *
 * Anchors we depend on:
 *
 *   form #auto-form                    the edit form; action is the apply URL
 *   input[name=hg-id]                  the hunt group ID (canonical, not the URL one)
 *   input[name=hg-name]                rota display name
 *   select[name=greeting]              announcement greeting; <option value="…">label</option>
 *   select[name=voicemail]             voicemail box; same shape
 *   select[name=hunting]               in-order / random / last / mostidle / rotary / simultaneous
 *   tr.huntdest                        one rota line — repeats per ordinal
 *     input[name=N_sun..N_sat]         day-of-week checkboxes
 *     input[name=N_start], N_end       HH:MM
 *     input[name=N_vm]                 voicemail flag (mutually exclusive with N_q in the JS)
 *     input[name=N_q]                  queue flag (rarely used — preserved as-is)
 *     input[name=N_destination]        free-text destination (number / "voicemail" / "queue")
 *     input[name=N_description]        operator-facing comment
 *     input[name=N_timeout]            seconds to ring before falling through
 *     input[name=N_enabled]            whether this line participates
 *     input[name=N_order]              hidden, redundant — the table order is authoritative
 *
 * The page has NO CSRF token — auth is session-cookie only — so we
 * don't extract one. If Tamar ever adds CSRF, the obvious place to look
 * is a hidden input inside `form#auto-form`.
 *
 * The "targets" Beacon expects are synthesised from two sources:
 *  - voicemail boxes from `select[name=voicemail]` (kind: voicemail)
 *  - distinct destinations seen across the existing rota rows (kind:
 *    number) — they're free-text so we can't enumerate every possible
 *    target, only the ones already in use.
 *
 * Greetings (`select[name=greeting]`) are exposed via `meta` rather
 * than as targets — they're played to the caller before any forwarding
 * decision and don't fit the "destination" model.
 */
final class HuntgroupPageParser
{
    use \Tamar\Logger\HasLogger;

    /** Log to the shared "tamar" channel so log lines name the plugin. */
    protected static function logChannel(): string
    {
        return 'tamar';
    }

    /**
     * Shape of the returned array:
     *
     * [
     *   'meta' => [
     *      'huntgroup_id' => '157626',
     *      'name'         => 'New Rota',
     *      'greeting'     => 'd3e375bb-...|none',
     *      'voicemail'    => '20042|none',
     *      'hunting'      => 'inorder|random|last|mostidle|rotary|simultaneous',
     *      'greetings_available' => [['id'=>..., 'label'=>...], ...],
     *      'voicemails_available' => [['id'=>..., 'label'=>...], ...],
     *   ],
     *   'rules' => [
     *      [
     *        'id' => '2',                              // row ordinal (string for uniformity)
     *        'priority' => 2,                          // also the ordinal — hunt-in-order semantics
     *        'label' => 'Steve C',                     // N_description
     *        'match' => [
     *           'type' => 'time_window',               // every row is a time-window match
     *           'value' => [
     *              'days' => ['mon'],                  // weekdays checked
     *              'from' => '10:00',
     *              'to'   => '14:00',
     *           ],
     *        ],
     *        'target_id' => 'num:01454898476',         // synthetic — see destinationId()
     *        'enabled' => true,
     *        // Tamar-specific extras the round-trip needs:
     *        '_raw' => [
     *           'destination' => '01454 898476',       // verbatim from the input
     *           'timeout'     => 90,
     *           'vm'          => false,
     *           'q'           => false,
     *           'description' => 'Steve C',
     *        ],
     *      ],
     *      ...
     *   ],
     *   'targets' => [
     *      ['id' => 'vm:20042', 'kind' => 'voicemail', 'label' => 'Voice to Email', 'address' => '20042'],
     *      ['id' => 'num:01454898476', 'kind' => 'number', 'label' => 'Steve C', 'address' => '01454 898476'],
     *      ...
     *   ],
     *   'csrf' => '',                                  // Tamar has none; field kept for contract uniformity
     * ]
     *
     * @return array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>, targets: array<int,array<string,mixed>>, csrf: string}
     * @throws ForwardingException
     */
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            self::logError('Hunt-group page HTML was empty');
            throw new ForwardingException('Hunt-group page HTML was empty.');
        }

        self::logDebug('Parsing hunt-group page', ['html_bytes' => strlen($html)]);

        $doc = $this->loadDocument($html);
        $xpath = new \DOMXPath($doc);

        // Sanity check: the edit page always contains the auto-form
        // and the huntingconfig table. A login redirect or error page
        // won't — throw fast rather than reporting "no rules", which
        // an operator could misread as "the rota is empty".
        $form = $xpath->query("//form[@id='auto-form']")->item(0);
        $table = $xpath->query("//table[@id='huntingconfig']")->item(0);
        if (!$form instanceof \DOMElement || !$table instanceof \DOMElement) {
            self::logError('Hunt-group page missing expected edit form/table', [
                'has_form' => $form instanceof \DOMElement,
                'has_table' => $table instanceof \DOMElement,
            ]);
            throw new ForwardingException(
                'Hunt-group page did not contain the expected edit form. '
                . 'The upstream may have redirected us to the login page, or its admin UI has changed shape.'
            );
        }

        $meta = $this->extractMeta($xpath, $form);
        $rules = $this->extractRules($xpath, $table);
        $targets = $this->synthesiseTargets($meta, $rules);

        self::logDebug('Hunt-group page parsed', [
            'huntgroup_id' => $meta['huntgroup_id'] ?? '',
            'rule_count' => count($rules),
            'target_count' => count($targets),
        ]);

        return [
            'meta' => $meta,
            'rules' => $rules,
            'targets' => $targets,
            'csrf' => '', // Tamar's admin uses session cookies only; no CSRF on this form.
        ];
    }

    /**
     * Authoritative "is this the authenticated hunt-group editor page?"
     * check, for callers (chiefly the login flow) that need to tell the
     * editor page from a re-rendered login page before committing to a
     * full {@see parse()}.
     *
     * It uses the same `//form[@id='auto-form']` anchor that parse()
     * keys off, so login-success detection and the later parse share a
     * single definition of "the editor page" and can't diverge — a
     * markup or quoting change that breaks the parse can no longer let
     * login report success.
     */
    public function looksLikeEditorPage(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        $xpath = new \DOMXPath($this->loadDocument($html));
        return $xpath->query("//form[@id='auto-form']")->item(0) instanceof \DOMElement;
    }

    /**
     * Parse the hunt-group *list* page — GET /phonedivert/huntgroup with
     * no `?huntgroup=` query — into the available hunt groups.
     *
     * The list page is a chooser, not an editor: a single
     * `<select name="huntgroup">` whose `<option value="<id>">Name</option>`
     * entries are the account's hunt groups, preceded by a disabled
     * `value="none"` placeholder ("Select from list:"). We key on that
     * select's name — it's absent from the editor page (which only has
     * greeting/voicemail/hunting selects), so this doubles as a "did we
     * actually land on the list page?" check.
     *
     * Returns `[]` for an account with no hunt groups (the select is
     * present but holds only the placeholder). Throws when the select is
     * missing entirely — that means the upstream handed us something
     * other than the list page (most likely the login page because the
     * session didn't take).
     *
     * @return array<int,array{id:string,name:string}>
     * @throws ForwardingException
     */
    public function parseHuntgroupList(string $html): array
    {
        if (trim($html) === '') {
            self::logError('Hunt-group list page HTML was empty');
            throw new ForwardingException('Hunt-group list page HTML was empty.');
        }

        $xpath = new \DOMXPath($this->loadDocument($html));
        $select = $xpath->query("//select[@name='huntgroup']")->item(0);
        if (!$select instanceof \DOMElement) {
            self::logError('Hunt-group list page missing the expected huntgroup select');
            throw new ForwardingException(
                'Hunt-group list page did not contain the expected hunt-group chooser. '
                . 'The upstream may have redirected us to the login page, or its admin UI has changed shape.'
            );
        }

        $options = $xpath->query(".//option", $select);
        if ($options === false) {
            return [];
        }

        $out = [];
        foreach ($options as $option) {
            if (!$option instanceof \DOMElement) {
                continue;
            }
            // Skip the disabled "Select from list:" placeholder and any
            // empty / sentinel value. The id is the upstream's numeric
            // hunt group id; the visible text is the operator-facing name.
            $id = trim($option->getAttribute('value'));
            if ($id === '' || $id === 'none' || $option->hasAttribute('disabled')) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => trim($option->textContent),
            ];
        }

        self::logDebug('Hunt-group list parsed', ['count' => count($out)]);
        return $out;
    }

    /**
     * Extract the top-level fields above the rota table.
     *
     * @return array<string,mixed>
     */
    private function extractMeta(\DOMXPath $xpath, \DOMElement $form): array
    {
        return [
            'huntgroup_id' => $this->inputValue($xpath, $form, 'hg-id'),
            'name' => $this->inputValue($xpath, $form, 'hg-name'),
            'greeting' => $this->selectedValue($xpath, $form, 'greeting'),
            'voicemail' => $this->selectedValue($xpath, $form, 'voicemail'),
            'hunting' => $this->selectedValue($xpath, $form, 'hunting'),
            'greetings_available' => $this->selectOptions($xpath, $form, 'greeting'),
            'voicemails_available' => $this->selectOptions($xpath, $form, 'voicemail'),
        ];
    }

    /**
     * Walk every `<tr class="huntdest">` and turn it into a raw rule
     * array. Row order from the table is authoritative — we ignore the
     * hidden N_order input, which exists in the page for JS reordering
     * but is redundant once parsed.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractRules(\DOMXPath $xpath, \DOMElement $table): array
    {
        $rows = $xpath->query(".//tr[contains(concat(' ', normalize-space(@class), ' '), ' huntdest ')]", $table);
        if ($rows === false) {
            return [];
        }

        $out = [];
        $position = 0;
        foreach ($rows as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }
            $position++;
            $ordinal = $this->detectOrdinal($xpath, $row);
            if ($ordinal === null) {
                // A row without a numeric prefix is malformed — skip
                // rather than throw, so one bad row doesn't make the
                // whole list unloadable.
                self::logWarning('Skipping malformed huntdest row with no detectable ordinal', [
                    'position' => $position,
                ]);
                continue;
            }
            // buildRule() is declared `: array` — it has no null return path.
            $out[] = $this->buildRule($xpath, $row, $ordinal, $position);
        }
        return $out;
    }

    /**
     * Figure out the row's ordinal by looking at any `<ordinal>_*` input
     * inside it. We use the first input whose name starts with digits
     * followed by `_`.
     */
    private function detectOrdinal(\DOMXPath $xpath, \DOMElement $row): ?int
    {
        $inputs = $xpath->query(".//input[@name]", $row);
        if ($inputs === false) {
            return null;
        }
        foreach ($inputs as $input) {
            if (!$input instanceof \DOMElement) {
                continue;
            }
            $name = $input->getAttribute('name');
            if (preg_match('/^(\d+)_/', $name, $m) === 1) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Turn one huntdest row into Beacon's raw rule array.
     *
     * @return array<string,mixed>
     */
    private function buildRule(\DOMXPath $xpath, \DOMElement $row, int $ordinal, int $position): array
    {
        $days = [];
        foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            if ($this->rowChecked($xpath, $row, $ordinal . '_' . $day)) {
                $days[] = $day;
            }
        }

        $start = $this->rowValue($xpath, $row, $ordinal . '_start');
        $end = $this->rowValue($xpath, $row, $ordinal . '_end');
        $destination = $this->rowValue($xpath, $row, $ordinal . '_destination');
        $description = $this->rowValue($xpath, $row, $ordinal . '_description');
        $timeoutRaw = $this->rowValue($xpath, $row, $ordinal . '_timeout');
        $timeout = $timeoutRaw === '' ? 0 : (int) $timeoutRaw;
        $vm = $this->rowChecked($xpath, $row, $ordinal . '_vm');
        $queue = $this->rowChecked($xpath, $row, $ordinal . '_q');
        $enabled = $this->rowChecked($xpath, $row, $ordinal . '_enabled');

        return [
            'id' => (string) $ordinal,
            'priority' => $position,
            'label' => $description,
            'match' => [
                'type' => 'time_window',
                'value' => [
                    'days' => $days,
                    'from' => $start,
                    'to' => $end,
                ],
            ],
            'target_id' => $this->destinationId($destination, $vm, $queue),
            'enabled' => $enabled,
            '_raw' => [
                'destination' => $destination,
                'timeout' => $timeout,
                'vm' => $vm,
                'q' => $queue,
                'description' => $description,
            ],
        ];
    }

    /**
     * Build a stable target ID from a row's destination column.
     *
     * The challenge: destinations are free-text on this page, not
     * picked from an enumerable list. So we synthesise IDs that round-
     * trip cleanly:
     *
     *  - "voicemail" + VM flag → 'vm:<box-id>' if a box is selected,
     *                            else 'vm:default'
     *  - "queue" + queue flag  → 'queue:<id>' (rare; carried as 'queue:default' here)
     *  - anything else         → 'num:<digits>' (digits only so a number
     *                            keyed by spaces or formatting still
     *                            collides correctly)
     */
    private function destinationId(string $destination, bool $vm, bool $queue): string
    {
        $normalised = strtolower(trim($destination));
        if ($vm || $normalised === 'voicemail') {
            // We don't know the box ID at this layer — the service
            // layer rewrites this to 'vm:<box>' using the meta voicemail
            // when it hydrates. Leave a generic placeholder for now.
            return 'vm:default';
        }
        if ($queue || $normalised === 'queue') {
            return 'queue:default';
        }
        $digits = preg_replace('/\D+/', '', $destination) ?? '';
        if ($digits === '') {
            // An empty destination on a disabled row is fine — give it
            // a deterministic empty-target ID so equality checks work.
            return 'num:';
        }
        return 'num:' . $digits;
    }

    /**
     * Build the targets list from voicemail boxes plus distinct numbers
     * already in use on the rota.
     *
     * @param array<string,mixed> $meta
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array<string,mixed>>
     */
    private function synthesiseTargets(array $meta, array $rules): array
    {
        $targets = [];

        // Voicemail boxes — every <option> in the voicemail select that
        // isn't the "None" sentinel becomes a target.
        $voicemails = $meta['voicemails_available'] ?? [];
        if (is_array($voicemails)) {
            foreach ($voicemails as $vm) {
                $id = (string) ($vm['id'] ?? '');
                if ($id === '' || $id === 'none') {
                    continue;
                }
                $targets[] = [
                    'id' => 'vm:' . $id,
                    'kind' => 'voicemail',
                    'label' => (string) ($vm['label'] ?? $id),
                    'address' => $id,
                ];
            }
        }

        // Distinct numbers in the existing rota — keyed by digits so
        // "01454 898476" and "01454898476" collapse to one target.
        $seen = [];
        foreach ($rules as $rule) {
            $raw = $rule['_raw'] ?? null;
            if (!is_array($raw)) {
                continue;
            }
            $destination = (string) ($raw['destination'] ?? '');
            if ($destination === '' || strtolower(trim($destination)) === 'voicemail' || strtolower(trim($destination)) === 'queue') {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $destination) ?? '';
            if ($digits === '' || isset($seen[$digits])) {
                continue;
            }
            $seen[$digits] = true;
            $targets[] = [
                'id' => 'num:' . $digits,
                'kind' => 'number',
                'label' => (string) ($rule['_raw']['description'] ?? $destination),
                'address' => $destination,
            ];
        }

        return $targets;
    }

    // -- DOM helpers ------------------------------------------------------

    private function inputValue(\DOMXPath $xpath, \DOMElement $form, string $name): string
    {
        $node = $xpath->query(".//input[@name='" . $name . "']", $form)->item(0);
        return $node instanceof \DOMElement ? $node->getAttribute('value') : '';
    }

    private function selectedValue(\DOMXPath $xpath, \DOMElement $form, string $name): string
    {
        $select = $xpath->query(".//select[@name='" . $name . "']", $form)->item(0);
        if (!$select instanceof \DOMElement) {
            return '';
        }
        $selected = $xpath->query(".//option[@selected]", $select)->item(0);
        if ($selected instanceof \DOMElement) {
            return $selected->getAttribute('value');
        }
        // No explicit `selected` attr — browsers fall back to the first
        // option. Mirror that to avoid `''` when the upstream renders a
        // pre-selected first item without the attribute.
        $first = $xpath->query(".//option", $select)->item(0);
        return $first instanceof \DOMElement ? $first->getAttribute('value') : '';
    }

    /**
     * @return array<int,array{id:string,label:string}>
     */
    private function selectOptions(\DOMXPath $xpath, \DOMElement $form, string $name): array
    {
        $select = $xpath->query(".//select[@name='" . $name . "']", $form)->item(0);
        if (!$select instanceof \DOMElement) {
            return [];
        }
        $options = $xpath->query(".//option", $select);
        if ($options === false) {
            return [];
        }
        $out = [];
        foreach ($options as $opt) {
            if (!$opt instanceof \DOMElement) {
                continue;
            }
            $out[] = [
                'id' => $opt->getAttribute('value'),
                'label' => trim($opt->textContent),
            ];
        }
        return $out;
    }

    private function rowValue(\DOMXPath $xpath, \DOMElement $row, string $name): string
    {
        $node = $xpath->query(".//input[@name='" . $name . "']", $row)->item(0);
        return $node instanceof \DOMElement ? $node->getAttribute('value') : '';
    }

    /**
     * Is a checkbox of the given name checked? Treat any presence of
     * the `checked` attribute as truthy — the HTML serialisation varies
     * (`checked`, `checked="checked"`, `checked=""`).
     */
    private function rowChecked(\DOMXPath $xpath, \DOMElement $row, string $name): bool
    {
        $node = $xpath->query(".//input[@name='" . $name . "' and @type='checkbox']", $row)->item(0);
        return $node instanceof \DOMElement && $node->hasAttribute('checked');
    }

    private function loadDocument(string $html): \DOMDocument
    {
        return HtmlDocument::load($html);
    }
}
