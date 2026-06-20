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
 * Concrete Beacon driver for Tamar Telecommunications' hunt-group editor.
 *
 * The service does two things: it logs in to the control panel, then
 * hands the hunt-group page to {@see HuntgroupPageParser} (to read the
 * rota) and {@see HuntgroupFormBuilder} (to write it back).
 *
 * Login is a plain HTML form POST. We GET the login page first so the
 * upstream sets its session cookie — the transport owns the cookie jar
 * and replays it automatically — then POST `username` and `password`
 * to the login handler. The session cookie authenticates every
 * subsequent request; success is implicit. An unauthenticated session
 * is caught when {@see load()} gets the login page instead of the
 * editor and the parser throws.
 *
 *   GET  /phonedivert/login                       establish the session cookie
 *   POST /phonedivert/login.php                   username + password
 *   GET  /phonedivert/huntgroup?huntgroup=<id>    the rota   (→ parser)
 *   POST /phonedivert/huntgroup/update            the rota   (← builder)
 *
 * There is no separate "apply" step — POSTing the update commits
 * immediately — so {@see commit()} is a no-op success for contract
 * uniformity. Reads are cached for the lifetime of the instance (one
 * WordPress request), so a listRules()+listTargets() pair issues a
 * single page GET.
 */
final class HuntgroupCallForwardingService extends AbstractCallForwardingService
{
    use \Tamar\Logger\HasLogger;

    /** Log to the shared "tamar" channel so log lines name the plugin. */
    protected static function logChannel(): string
    {
        return 'tamar';
    }

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
        private readonly string $loginPath = '/phonedivert/login',
        private readonly string $loginSubmitPath = '/phonedivert/login.php',
        private readonly string $updatePath = '/phonedivert/huntgroup/update',
    ) {
    }

    // -- CallForwardingService contract ----------------------------------

    public function listRules(): array
    {
        $state = $this->load();
        $rules = $this->hydrateRules($state['rules']);

        // Targets are voicemail-shaped; rewrite vm:default → vm:<box> so
        // the contract's "rule.target_id is a real target.id" holds.
        $vmBoxId = $this->voicemailBoxId($state);
        if ($vmBoxId !== '') {
            foreach ($rules as $i => $rule) {
                if ($rule->getTargetId() === 'vm:default') {
                    $rules[$i] = $rule->with(['target_id' => 'vm:' . $vmBoxId]);
                }
            }
        }
        return $rules;
    }

    public function findRule(string $ruleId): ?ForwardingRule
    {
        foreach ($this->listRules() as $rule) {
            if ($rule->getId() === $ruleId) {
                return $rule;
            }
        }
        return null;
    }

    public function saveRule(ForwardingRule $rule): string
    {
        $this->validateRule($rule);

        $state = $this->load();
        $state = $this->builder->applyRuleEdit($state, $this->ruleToRow($rule, $state));
        $this->pushState($state);

        // An edit keeps its id; an append's canonical id is its new
        // 1-based ordinal (the upstream renumbers on the next GET).
        return $rule->getId() !== '' ? $rule->getId() : (string) count($state['rules']);
    }

    public function deleteRule(string $ruleId): bool
    {
        $state = $this->load();
        [$state, $removed] = $this->builder->applyRuleDelete($state, $ruleId);
        if (!$removed) {
            return false;
        }
        $this->pushState($state);
        return true;
    }

    public function listTargets(): array
    {
        return $this->hydrateTargets($this->load()['targets']);
    }

    public function commit(): bool
    {
        // Tamar applies changes on each update POST — no separate step.
        return true;
    }

    public function testConnection(): bool
    {
        $this->cache = null;
        $this->loggedIn = false;

        $this->ensureLoggedIn();
        $state = $this->load();

        self::logInfo('Connection test succeeded', [
            'huntgroup_id' => $this->huntgroupId,
            'rule_count' => count($state['rules']),
            'target_count' => count($state['targets']),
        ]);
        return true;
    }

    // -- login -----------------------------------------------------------

    /**
     * Log in to the control panel once per instance: GET the login page
     * to pick up the session cookie, then POST the credentials to the
     * login handler. The transport replays the cookie on every later
     * request.
     */
    private function ensureLoggedIn(): void
    {
        if ($this->loggedIn) {
            return;
        }
        if ($this->username === '' || $this->password === '') {
            throw new ForwardingException(
                'Upstream login cannot proceed: username and/or password is not configured. Set them under Settings → Tamar.'
            );
        }

        $base = rtrim($this->baseUrl, '/');
        self::logInfo('Logging in to upstream', [
            'huntgroup_id' => $this->huntgroupId,
            'login_url' => $base . $this->loginPath,
            'submit_url' => $base . $this->loginSubmitPath,
        ]);

        // GET the login page so the upstream sets its session cookie.
        $this->request('GET', $base . $this->loginPath);

        // POST the credentials. The field names are `username` and
        // `password`; the form submits to the login handler.
        $resp = $this->request(
            'POST',
            $base . $this->loginSubmitPath,
            http_build_query(['username' => $this->username, 'password' => $this->password]),
        );
        if ($resp['status'] >= 400) {
            throw new ForwardingException('Upstream login failed with status ' . $resp['status'] . '.');
        }

        $this->loggedIn = true;
    }

    // -- page fetch / push -----------------------------------------------

    /**
     * GET the rota page (logging in first), parse it, and cache the
     * result for the lifetime of this instance.
     *
     * @return array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>, targets: array<int,array<string,mixed>>, csrf: string}
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->ensureLoggedIn();

        $resp = $this->request('GET', $this->rulesUrl());
        if ($resp['status'] >= 400) {
            throw new ForwardingException(
                'Upstream returned status ' . $resp['status'] . ' when fetching the hunt-group page.'
            );
        }

        // parse() throws if it got the login page instead of the editor —
        // that is our backstop for a login that didn't take.
        return $this->cache = $this->parser->parse($resp['body']);
    }

    /**
     * POST the whole rota back upstream and invalidate the cache.
     *
     * @param array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>} $state
     */
    private function pushState(array $state): void
    {
        $this->ensureLoggedIn();

        $resp = $this->request(
            'POST',
            rtrim($this->baseUrl, '/') . $this->updatePath,
            $this->builder->build($state),
        );
        if ($resp['status'] >= 400) {
            throw new ForwardingException('Upstream returned status ' . $resp['status'] . ' when saving the rota.');
        }
        $this->cache = null;
    }

    /**
     * Issue a request through the transport, translating a transport-
     * layer failure into a ForwardingException. A non-empty body is sent
     * form-urlencoded; an empty body is a plain GET.
     *
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function request(string $method, string $url, string $body = ''): array
    {
        $headers = $body !== '' ? ['Content-Type' => 'application/x-www-form-urlencoded'] : [];
        try {
            return $this->transport->request($method, $url, $headers, $body);
        } catch (TransportException $e) {
            throw new ForwardingException('Could not reach the Tamar control panel: ' . $e->getMessage(), 0, $e);
        }
    }

    // -- helpers ----------------------------------------------------------

    private function rulesUrl(): string
    {
        $base = rtrim($this->baseUrl, '/') . $this->rulesPath;
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . 'huntgroup=' . rawurlencode($this->huntgroupId);
    }

    /**
     * The selected voicemail box id, or '' when none is set.
     *
     * @param array<string,mixed> $state
     */
    private function voicemailBoxId(array $state): string
    {
        $meta = is_array($state['meta'] ?? null) ? $state['meta'] : [];
        $vm = (string) ($meta['voicemail'] ?? '');
        return ($vm === '' || $vm === 'none') ? '' : $vm;
    }

    /**
     * Translate a Beacon ForwardingRule back into the raw-row shape the
     * builder re-encodes.
     *
     * @param array{meta: array<string,mixed>, rules: array<int,array<string,mixed>>, targets: array<int,array<string,mixed>>, csrf: string} $state
     * @return array<string,mixed>
     */
    private function ruleToRow(ForwardingRule $rule, array $state): array
    {
        $match = $rule->getMatch();
        $value = is_array($match['value'] ?? null) ? $match['value'] : [];
        $targetId = $rule->getTargetId();

        // Resolve target_id back to a destination string + flags.
        $destination = $targetId;
        $vm = false;
        $queue = false;
        if (str_starts_with($targetId, 'vm:')) {
            $destination = 'voicemail';
            $vm = true;
        } elseif (str_starts_with($targetId, 'queue:')) {
            $destination = 'queue';
            $queue = true;
        } elseif (str_starts_with($targetId, 'num:')) {
            $destination = $this->targetAddress($state, $targetId) ?: substr($targetId, 4);
        }

        return [
            'id' => $rule->getId(),
            'priority' => $rule->getPriority(),
            'label' => $rule->getLabel(),
            'match' => [
                'type' => $rule->getMatchType(),
                'value' => [
                    'days' => is_array($value['days'] ?? null) ? $value['days'] : [],
                    'from' => (string) ($value['from'] ?? '00:00'),
                    'to' => (string) ($value['to'] ?? '23:59'),
                ],
            ],
            'target_id' => $targetId,
            'enabled' => $rule->isEnabled(),
            '_raw' => [
                'destination' => $destination,
                'timeout' => $this->existingTimeout($state, $rule->getId()),
                'vm' => $vm,
                'q' => $queue,
                'description' => $rule->getLabel(),
            ],
        ];
    }

    /**
     * Carry forward the timeout from the rule's existing row; default 20
     * (the upstream's per-row default for new lines).
     *
     * @param array<string,mixed> $state
     */
    private function existingTimeout(array $state, string $ruleId): int
    {
        foreach (($state['rules'] ?? []) as $existing) {
            if (is_array($existing) && (string) ($existing['id'] ?? '') === $ruleId) {
                $raw = is_array($existing['_raw'] ?? null) ? $existing['_raw'] : [];
                return (int) ($raw['timeout'] ?? 20);
            }
        }
        return 20;
    }

    /**
     * The address (phone number) of a target from the parsed state, or
     * '' if it isn't one of the known targets.
     *
     * @param array<string,mixed> $state
     */
    private function targetAddress(array $state, string $targetId): string
    {
        foreach (($state['targets'] ?? []) as $target) {
            if (is_array($target) && (string) ($target['id'] ?? '') === $targetId) {
                return (string) ($target['address'] ?? '');
            }
        }
        return '';
    }
}
