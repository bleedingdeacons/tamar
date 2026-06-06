<?php

declare(strict_types=1);

namespace Tamar\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\AbstractCallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\TransportException;

/**
 * Concrete Beacon driver for Tamar Telecommunications' hunt-group
 * editor.
 *
 * The upstream's edit URL is per-client:
 *
 *   GET  /phonedivert/huntgroup?huntgroup=<id>
 *   POST /phonedivert/huntgroup/update      (form-urlencoded, replaces whole rota)
 *   POST /phonedivert/login/                (session-cookie auth)
 *
 * There is no separate "apply pending changes" step on the upstream —
 * POSTing the update commits immediately. We implement `commit()` as
 * a no-op success so callers can be uniform across drivers, in line
 * with the contract documentation.
 *
 * Per-request page cache: a `listRules()` followed by `listTargets()`
 * or `findRule()` shouldn't issue two GETs. The cache is keyed off
 * this instance and lives only as long as it does; a fresh request
 * builds a fresh service via the container factory and gets a fresh
 * cache. This matters because the page is non-trivial to fetch (login
 * + GET) and because rules/targets are extracted from the same HTML.
 *
 * Rule IDs: the upstream has no stable per-row identifier — rows are
 * positional. The parser uses the row's ordinal at parse time as its
 * `id`. That means deleting row 3 makes the former row 4 the new row
 * 3, so a sequence of two `saveRule('4', ...)` calls done across a
 * `deleteRule('3', ...)` will hit different rows. Document this in
 * the README; callers should re-fetch after a mutation.
 */
final class HuntgroupCallForwardingService extends AbstractCallForwardingService
{
    use \Tamar\Logger\HasLogger;

    /**
     * @var array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>, targets: array<int,array<string,mixed>>, csrf: string}|null
     */
    private ?array $cache = null;

    private bool $loggedIn = false;

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly HuntgroupPageParser $parser,
        private readonly HuntgroupFormBuilder $builder,
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $huntgroupId,
        private readonly string $rulesPath = '/phonedivert/huntgroup',
        private readonly string $loginPath = '/phonedivert/login/',
        private readonly string $updatePath = '/phonedivert/huntgroup/update',
    ) {
    }

    public function listRules(): array
    {
        $state = $this->load();
        $rules = $this->hydrateRules($state['rules']);
        // Targets are voicemail-shaped; rewrite vm:default → vm:<actual box>
        // for each rule so the contract's "rule.target_id is a real
        // target.id" invariant holds.
        $vmBoxId = $this->resolvedVoicemailBoxId($state);
        if ($vmBoxId !== '') {
            $rewritten = [];
            foreach ($rules as $rule) {
                if ($rule->getTargetId() === 'vm:default') {
                    $rewritten[] = $rule->with(['target_id' => 'vm:' . $vmBoxId]);
                } else {
                    $rewritten[] = $rule;
                }
            }
            $rules = $rewritten;
        }
        self::logDebug('Listed forwarding rules', [
            'huntgroup_id' => $this->huntgroupId,
            'count' => count($rules),
        ]);
        return $rules;
    }

    public function findRule(string $ruleId): ?ForwardingRule
    {
        foreach ($this->listRules() as $rule) {
            if ($rule->getId() === $ruleId) {
                self::logDebug('Found forwarding rule', [
                    'huntgroup_id' => $this->huntgroupId,
                    'rule_id' => $ruleId,
                ]);
                return $rule;
            }
        }
        self::logDebug('Forwarding rule not found', [
            'huntgroup_id' => $this->huntgroupId,
            'rule_id' => $ruleId,
        ]);
        return null;
    }

    public function saveRule(ForwardingRule $rule): string
    {
        self::logInfo('Saving forwarding rule', [
            'huntgroup_id' => $this->huntgroupId,
            'rule_id' => $rule->getId() !== '' ? $rule->getId() : '(new)',
            'match_type' => $rule->getMatchType(),
            'target_id' => $rule->getTargetId(),
            'enabled' => $rule->isEnabled(),
        ]);

        $this->validateRule($rule);

        $state = $this->load();

        // Translate the Beacon rule back into the parser's raw shape so
        // the builder can encode it. The match values map directly; the
        // target_id resolves back into a destination string.
        $raw = $this->ruleToRowArray($rule, $state);

        $state = $this->builder->applyRuleEdit($state, $raw);
        $this->pushState($state);

        // After a save, the canonical ID is the new positional ordinal.
        // For an edit-in-place, the existing ID is fine.
        if ($rule->getId() !== '') {
            self::logInfo('Forwarding rule saved (edit in place)', [
                'huntgroup_id' => $this->huntgroupId,
                'rule_id' => $rule->getId(),
            ]);
            return $rule->getId();
        }
        // For an append, the new row's ordinal is rules-count after
        // the local apply. The upstream will renumber on next GET, but
        // this is the value callers expect right now.
        $newId = (string) count($state['rules']);
        self::logInfo('Forwarding rule saved (appended)', [
            'huntgroup_id' => $this->huntgroupId,
            'rule_id' => $newId,
        ]);
        return $newId;
    }

    public function deleteRule(string $ruleId): bool
    {
        self::logInfo('Deleting forwarding rule', [
            'huntgroup_id' => $this->huntgroupId,
            'rule_id' => $ruleId,
        ]);
        $state = $this->load();
        [$state, $removed] = $this->builder->applyRuleDelete($state, $ruleId);
        if (!$removed) {
            self::logWarning('Delete requested for unknown rule; nothing removed', [
                'huntgroup_id' => $this->huntgroupId,
                'rule_id' => $ruleId,
            ]);
            return false;
        }
        $this->pushState($state);
        self::logInfo('Forwarding rule deleted', [
            'huntgroup_id' => $this->huntgroupId,
            'rule_id' => $ruleId,
            'remaining' => count($state['rules']),
        ]);
        return true;
    }

    public function listTargets(): array
    {
        $state = $this->load();
        $targets = $this->hydrateTargets($state['targets']);
        self::logDebug('Listed forwarding targets', [
            'huntgroup_id' => $this->huntgroupId,
            'count' => count($targets),
        ]);
        return $targets;
    }

    public function commit(): bool
    {
        // Tamar's upstream applies changes on each update POST — no
        // separate commit step. We return true unconditionally so
        // callers can treat the contract uniformly across drivers.
        self::logDebug('commit() is a no-op for the Tamar driver (changes apply on each update POST)', [
            'huntgroup_id' => $this->huntgroupId,
        ]);
        return true;
    }

    public function testConnection(): bool
    {
        self::logInfo('Testing upstream connection', [
            'huntgroup_id' => $this->huntgroupId,
            'base_url' => $this->baseUrl,
        ]);
        $this->cache = null;
        $this->loggedIn = false;
        $this->load();
        self::logInfo('Upstream connection test succeeded', [
            'huntgroup_id' => $this->huntgroupId,
        ]);
        return true;
    }

    // -- internals --------------------------------------------------------

    /**
     * GET the rules page (logging in first if needed), parse it, and
     * cache the result for the lifetime of this service instance.
     *
     * @return array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>, targets: array<int,array<string,mixed>>, csrf: string}
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            self::logDebug('Serving hunt-group page from per-request cache', [
                'huntgroup_id' => $this->huntgroupId,
            ]);
            return $this->cache;
        }
        $this->ensureLoggedIn();
        $url = $this->rulesUrl();
        self::logDebug('Fetching hunt-group page', [
            'huntgroup_id' => $this->huntgroupId,
            'url' => $url,
        ]);
        try {
            $resp = $this->transport->request('GET', $url);
        } catch (TransportException $e) {
            self::logError('Transport failure fetching hunt-group page', [
                'huntgroup_id' => $this->huntgroupId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new ForwardingException('Could not reach the upstream: ' . $e->getMessage(), 0, $e);
        }
        if ($resp['status'] === 401 || $resp['status'] === 403) {
            self::logError('Upstream returned unauthorised fetching hunt-group page', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
            throw new ForwardingException(
                'Upstream returned unauthorised when fetching the hunt-group page. Check Tamar credentials.'
            );
        }
        if ($resp['status'] >= 400) {
            self::logError('Upstream returned error status fetching hunt-group page', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
            throw new ForwardingException('Upstream returned status ' . $resp['status'] . ' when fetching the hunt-group page.');
        }
        $this->cache = $this->parser->parse((string) ($resp['body'] ?? ''));
        self::logDebug('Hunt-group page fetched and parsed', [
            'huntgroup_id' => $this->huntgroupId,
            'status' => $resp['status'],
            'rule_count' => count($this->cache['rules'] ?? []),
            'target_count' => count($this->cache['targets'] ?? []),
        ]);
        return $this->cache;
    }

    /**
     * POST the full rota back upstream and invalidate the cache so the
     * next read re-fetches.
     *
     * @param array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>} $state
     */
    private function pushState(array $state): void
    {
        $this->ensureLoggedIn();
        $body = $this->builder->build($state);
        $url = rtrim($this->baseUrl, '/') . $this->updatePath;
        self::logDebug('Pushing rota to upstream', [
            'huntgroup_id' => $this->huntgroupId,
            'url' => $url,
            'rule_count' => count($state['rules'] ?? []),
            'body_bytes' => strlen($body),
        ]);
        try {
            $resp = $this->transport->request(
                'POST',
                $url,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                $body,
            );
        } catch (TransportException $e) {
            self::logError('Transport failure saving rota', [
                'huntgroup_id' => $this->huntgroupId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new ForwardingException('Could not reach the upstream to save the rota: ' . $e->getMessage(), 0, $e);
        }
        if ($resp['status'] >= 400) {
            self::logError('Upstream returned error status saving rota', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
            throw new ForwardingException('Upstream returned status ' . $resp['status'] . ' when saving the rota.');
        }
        self::logDebug('Rota saved upstream; invalidating cache', [
            'huntgroup_id' => $this->huntgroupId,
            'status' => $resp['status'],
        ]);
        $this->cache = null;
    }

    private function ensureLoggedIn(): void
    {
        if ($this->loggedIn) {
            self::logDebug('Already logged in to upstream this request', [
                'huntgroup_id' => $this->huntgroupId,
            ]);
            return;
        }
        $url = rtrim($this->baseUrl, '/') . $this->loginPath;
        // Credentials are deliberately never placed in the log context.
        self::logDebug('Logging in to upstream', [
            'huntgroup_id' => $this->huntgroupId,
            'url' => $url,
            'username' => $this->username,
        ]);
        $body = http_build_query([
            'username' => $this->username,
            'password' => $this->password,
        ]);
        try {
            $resp = $this->transport->request(
                'POST',
                $url,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                $body,
            );
        } catch (TransportException $e) {
            self::logError('Transport failure during upstream login', [
                'huntgroup_id' => $this->huntgroupId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new ForwardingException('Could not reach the upstream login page: ' . $e->getMessage(), 0, $e);
        }
        // The login endpoint may return 200 with a session cookie or
        // redirect to the dashboard. Either is fine; only an explicit
        // 4xx is a failure.
        if ($resp['status'] >= 400) {
            self::logError('Upstream login failed', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
            throw new ForwardingException('Upstream login failed with status ' . $resp['status'] . '.');
        }
        $this->loggedIn = true;
        self::logInfo('Logged in to upstream', [
            'huntgroup_id' => $this->huntgroupId,
            'status' => $resp['status'],
        ]);
    }

    private function rulesUrl(): string
    {
        $base = rtrim($this->baseUrl, '/') . $this->rulesPath;
        $separator = str_contains($base, '?') ? '&' : '?';
        return $base . $separator . 'huntgroup=' . rawurlencode($this->huntgroupId);
    }

    private function resolvedVoicemailBoxId(array $state): string
    {
        $meta = $state['meta'] ?? [];
        $vm = (string) ($meta['voicemail'] ?? '');
        if ($vm === '' || $vm === 'none') {
            return '';
        }
        return $vm;
    }

    /**
     * Translate a Beacon ForwardingRule back into the raw-array shape
     * the parser emits, so the builder can re-encode it.
     *
     * @param array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>, targets: array<int,array<string,mixed>>, csrf: string} $state
     * @return array<string,mixed>
     */
    private function ruleToRowArray(ForwardingRule $rule, array $state): array
    {
        $match = $rule->getMatch();
        $matchValue = is_array($match['value'] ?? null) ? $match['value'] : [];

        // Resolve target_id back to a destination string. Three shapes:
        //  - vm:<box>   → destination "voicemail", vm flag on
        //  - queue:<id> → destination "queue",     q flag on
        //  - num:<dig>  → destination = address from the targets table
        //                  if known, else the digits.
        $destination = '';
        $vmFlag = false;
        $queueFlag = false;
        $targetId = $rule->getTargetId();

        if (str_starts_with($targetId, 'vm:')) {
            $destination = 'voicemail';
            $vmFlag = true;
        } elseif (str_starts_with($targetId, 'queue:')) {
            $destination = 'queue';
            $queueFlag = true;
        } elseif (str_starts_with($targetId, 'num:')) {
            $digits = substr($targetId, 4);
            $destination = $this->addressFromTargets($state, $targetId) ?: $digits;
        } else {
            // Unknown target shape — treat the raw value as a literal
            // destination string. Better than silently dropping it.
            $destination = $targetId;
        }

        // Carry forward any timeout we already had for this rule's
        // original row; default 20 (matches the upstream's per-row
        // default for new lines).
        $existingTimeout = 20;
        $existingRules = $state['rules'] ?? [];
        foreach ($existingRules as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            if ((string) ($existing['id'] ?? '') !== $rule->getId()) {
                continue;
            }
            $raw = is_array($existing['_raw'] ?? null) ? $existing['_raw'] : [];
            $existingTimeout = (int) ($raw['timeout'] ?? 20);
            break;
        }

        return [
            'id' => $rule->getId(),
            'priority' => $rule->getPriority(),
            'label' => $rule->getLabel(),
            'match' => [
                'type' => $rule->getMatchType(),
                'value' => [
                    'days' => is_array($matchValue['days'] ?? null) ? $matchValue['days'] : [],
                    'from' => (string) ($matchValue['from'] ?? '00:00'),
                    'to' => (string) ($matchValue['to'] ?? '23:59'),
                ],
            ],
            'target_id' => $targetId,
            'enabled' => $rule->isEnabled(),
            '_raw' => [
                'destination' => $destination,
                'timeout' => $existingTimeout,
                'vm' => $vmFlag,
                'q' => $queueFlag,
                'description' => $rule->getLabel(),
            ],
        ];
    }

    /**
     * Look up a target's address (the actual phone number) from the
     * targets table on the parsed state. Returns '' if not found.
     *
     * @param array<string,mixed> $state
     */
    private function addressFromTargets(array $state, string $targetId): string
    {
        $targets = $state['targets'] ?? [];
        if (!is_array($targets)) {
            return '';
        }
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            if ((string) ($target['id'] ?? '') === $targetId) {
                return (string) ($target['address'] ?? '');
            }
        }
        return '';
    }
}
