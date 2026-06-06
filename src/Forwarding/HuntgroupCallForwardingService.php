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
 *   POST /customer-login/                   (session-cookie auth)
 *
 * The login form (id="login") signals its outcome in the URL rather
 * than the status code: a successful login redirects to
 * `?logged_in=1`, a rejected credential to `?notify=failedlogin`.
 * `ensureLoggedIn()` therefore decides success/failure from the
 * post-login URL (Location header, body as fallback), not from the
 * HTTP status alone.
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
     * Log to the shared "tamar" channel rather than the default
     * (class-name-derived) channel, so login / connection-test / rule
     * activity lands alongside the rest of the plugin's logging instead
     * of in a separate "huntgroupcallforwardingservice" channel.
     */
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
        private readonly string $loginPath = '/customer-login/',
        private readonly string $updatePath = '/phonedivert/huntgroup/update',
        /**
         * Substring that appears in the post-login URL when auth
         * succeeded. The upstream redirects the login POST to
         * `…/customer-login/?logged_in=1` on success.
         */
        private readonly string $loginSuccessMarker = 'logged_in=1',
        /**
         * Substring that appears in the post-login URL when auth
         * failed. The upstream redirects to
         * `…/customer-login/?notify=failedlogin` on a bad credential.
         */
        private readonly string $loginFailureMarker = 'notify=failedlogin',
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
        $startedAt = microtime(true);
        self::logInfo('Connection test started', [
            'huntgroup_id' => $this->huntgroupId,
            'base_url' => $this->baseUrl,
            'login_path' => $this->loginPath,
            'rules_path' => $this->rulesPath,
            'username' => self::maskValue($this->username),
            'has_password' => $this->hasPassword(),
        ]);
        $this->cache = null;
        $this->loggedIn = false;

        // Stage 1 — authenticate. Exercise the login step explicitly so
        // a credential rejection surfaces as a login failure (with its
        // own message) rather than as a downstream page-fetch error.
        // ensureLoggedIn() throws a ForwardingException if the upstream
        // redirects to ?notify=failedlogin or otherwise refuses the
        // login.
        self::logDebug('Connection test: authenticating', [
            'huntgroup_id' => $this->huntgroupId,
        ]);
        try {
            $this->ensureLoggedIn();
        } catch (ForwardingException $e) {
            self::logError('Connection test failed at login stage', [
                'huntgroup_id' => $this->huntgroupId,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        self::logInfo('Connection test: login verified', [
            'huntgroup_id' => $this->huntgroupId,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        // Stage 2 — confirm we can actually read the configured hunt
        // group with that session.
        self::logDebug('Connection test: fetching hunt-group page', [
            'huntgroup_id' => $this->huntgroupId,
        ]);
        try {
            $state = $this->load();
        } catch (ForwardingException $e) {
            self::logError('Connection test failed at page-fetch stage', [
                'huntgroup_id' => $this->huntgroupId,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        self::logInfo('Connection test succeeded', [
            'huntgroup_id' => $this->huntgroupId,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'rule_count' => count($state['rules'] ?? []),
            'target_count' => count($state['targets'] ?? []),
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
        // The username is masked; the password is never logged in any
        // form — only a boolean for whether one is configured.
        self::logInfo('Starting upstream login', [
            'huntgroup_id' => $this->huntgroupId,
            'url' => $url,
            'username' => self::maskValue($this->username),
            'has_password' => $this->hasPassword(),
            'success_marker' => $this->loginSuccessMarker,
            'failure_marker' => $this->loginFailureMarker,
        ]);

        // Guard: a blank username or password can't succeed and the
        // upstream's failure page is ambiguous about which field was
        // wrong. Catch it here with a clear message rather than spending
        // a round-trip to be told "failedlogin".
        if ($this->username === '' || !$this->hasPassword()) {
            self::logError('Upstream login aborted: missing credentials', [
                'huntgroup_id' => $this->huntgroupId,
                'has_username' => $this->username !== '',
                'has_password' => $this->hasPassword(),
            ]);
            throw new ForwardingException(
                'Upstream login cannot proceed: username and/or password is not configured. Set them under Settings → Tamar.'
            );
        }
        // The customer-login form (id="login") is a WordPress-style
        // login. Send the WP-standard field names (`log`/`pwd`) and the
        // generic `username`/`password` aliases so the same POST works
        // whether the upstream reads one pair or the other.
        $body = http_build_query([
            'log' => $this->username,
            'pwd' => $this->password,
            'username' => $this->username,
            'password' => $this->password,
        ]);
        self::logDebug('Submitting login form', [
            'huntgroup_id' => $this->huntgroupId,
            'url' => $url,
            // Field names only — values omitted as two of them are the
            // password.
            'fields' => ['log', 'pwd', 'username', 'password'],
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
            self::logError('Transport failure during upstream login', [
                'huntgroup_id' => $this->huntgroupId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new ForwardingException('Could not reach the upstream login page: ' . $e->getMessage(), 0, $e);
        }

        // Record that a response came back, and from which channel the
        // outcome will be read. The Location header is a URL (safe to
        // log) and is the most useful single field for diagnosing a
        // login that lands back on the login page. We still never log
        // the body or any cookie values here.
        $headers = is_array($resp['headers'] ?? null) ? $resp['headers'] : [];
        $location = (string) ($headers['location'] ?? '');
        self::logDebug('Login response received', [
            'huntgroup_id' => $this->huntgroupId,
            'status' => $resp['status'],
            'location' => $location,
            'location_query' => self::parseQueryForLog($location),
            'has_location_header' => $location !== '',
            'body_bytes' => strlen((string) ($resp['body'] ?? '')),
        ]);

        // The upstream signals the login OUTCOME in the URL, not the
        // status code: a bad password still returns a normal (2xx/3xx)
        // response and redirects to `?notify=failedlogin`, while a good
        // one redirects to `?logged_in=1`. So a status check alone would
        // treat a wrong password as success. We inspect the post-login
        // URL instead, drawn from the redirect Location header (or, as a
        // fallback, the response body in case the marker is rendered
        // inline at 200).
        $haystack = $this->loginOutcomeHaystack($resp);

        $failureMatched = $this->loginFailureMarker !== ''
            && str_contains($haystack, $this->loginFailureMarker);
        $successMatched = $this->loginSuccessMarker !== ''
            && str_contains($haystack, $this->loginSuccessMarker);
        self::logDebug('Evaluated login outcome markers', [
            'huntgroup_id' => $this->huntgroupId,
            'failure_marker_matched' => $failureMatched,
            'success_marker_matched' => $successMatched,
            'status' => $resp['status'],
        ]);

        // When neither marker matched, the outcome is ambiguous — this
        // is the case that produces a "logged in on status alone" then
        // a failed page fetch. Log a short, sanitized snippet of the
        // response so the actual shape (a re-rendered login form, an
        // error banner, a hidden nonce field) is visible without
        // leaking secrets.
        if (!$failureMatched && !$successMatched) {
            self::logWarning('Login produced no recognised outcome marker; recording sanitized snippet', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
                'body_snippet' => $this->sanitizeSnippet((string) ($resp['body'] ?? '')),
                'has_login_form' => str_contains((string) ($resp['body'] ?? ''), 'id="login"'),
            ]);
        }

        if ($failureMatched) {
            self::logError('Upstream login failed (credentials rejected)', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
            throw new ForwardingException(
                'Upstream login failed: the control panel rejected the username or password. Check Tamar credentials under Settings → Tamar.'
            );
        }

        // A hard 4xx/5xx on the login request itself is still a failure
        // (e.g. the login path moved or the host is broken) — surface it
        // distinctly from a credential rejection.
        if ($resp['status'] >= 400) {
            self::logError('Upstream login request returned an error status', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
            throw new ForwardingException('Upstream login failed with status ' . $resp['status'] . '.');
        }

        // Prefer a positive success signal when the upstream gives one.
        // If we have neither marker we fall through and trust the status
        // (some redirect configurations may not expose the Location to
        // this layer); the subsequent page GET will still catch an
        // unauthenticated session via its own 401/403 handling.
        if ($successMatched) {
            self::logInfo('Logged in to upstream (success marker present)', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
        } else {
            self::logInfo('Logged in to upstream (no explicit marker; proceeding on status)', [
                'huntgroup_id' => $this->huntgroupId,
                'status' => $resp['status'],
            ]);
        }

        $this->loggedIn = true;
    }

    /**
     * Mask a sensitive value for logging: never the raw string. Returns
     * a fixed sentinel for empty/secret-bearing values and otherwise a
     * length-only hint with the first and last character, so an
     * operator can sanity-check "is this the username I expect" without
     * the value itself landing in a log file.
     *
     * Used for the username (which may be an email) — the PASSWORD is
     * never passed to this or any log call; it has no masked form here
     * at all.
     */
    /**
     * Parse the query string of a URL (or a bare Location value) into a
     * name => value map for logging. Parameters whose names look
     * secret-bearing are redacted; outcome signals like `logged_in` and
     * `notify` are kept verbatim. Returns [] when there's no query.
     *
     * @return array<string,string>
     */
    private static function parseQueryForLog(string $url): array
    {
        if ($url === '') {
            return [];
        }
        $query = parse_url($url, PHP_URL_QUERY);
        // A bare "?notify=failedlogin" or "notify=failedlogin" with no
        // scheme/host won't parse as a URL query — handle that too.
        if (!is_string($query) || $query === '') {
            $query = str_contains($url, '?') ? substr($url, strpos($url, '?') + 1) : $url;
            // Only treat it as a query if it actually looks like one.
            if (!str_contains($query, '=')) {
                return [];
            }
        }
        $parsed = [];
        parse_str($query, $parsed);

        $out = [];
        foreach ($parsed as $name => $value) {
            $name = (string) $name;
            $flat = is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
            if (preg_match('/(pass|pwd|token|nonce|secret|auth|key|sid|session)/i', $name) === 1) {
                $out[$name] = '[redacted]';
            } else {
                $out[$name] = $flat;
            }
        }
        return $out;
    }

    private static function maskValue(string $value): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '(empty)';
        }
        if ($len <= 2) {
            return '(set, ' . $len . ' chars)';
        }
        return $value[0] . str_repeat('*', max(1, $len - 2)) . $value[$len - 1]
            . ' (' . $len . ' chars)';
    }

    /**
     * Whether the configured password is non-empty. Logged as a plain
     * boolean so an operator can tell "no password saved" apart from
     * "wrong password" — without the value ever being recorded.
     */
    private function hasPassword(): bool
    {
        return $this->password !== '';
    }

    /**
     * Produce a short, secret-scrubbed snippet of a response body for
     * diagnostic logging. Whitespace is collapsed, the result is capped
     * at a few hundred characters, and anything that looks like a
     * password — including the configured password itself, and the
     * value of any password-ish input field — is replaced with
     * `[redacted]`. This is a best-effort scrub for an operator log,
     * not a security guarantee, so it errs toward over-redaction.
     */
    private function sanitizeSnippet(string $body): string
    {
        $snippet = (string) preg_replace('/\s+/', ' ', $body);

        // Redact the actual configured password if it happens to be
        // reflected anywhere in the page.
        if ($this->password !== '') {
            $snippet = str_ireplace($this->password, '[redacted]', $snippet);
        }

        // Redact the value="" of any input whose name/type looks like a
        // password or token field.
        $snippet = (string) preg_replace(
            '/(name=("|\')[^"\']*(pass|pwd|token|nonce|secret)[^"\']*("|\')[^>]*value=("|\'))[^"\']*(("|\'))/i',
            '$1[redacted]$6',
            $snippet
        );
        // And the reverse attribute order (value before name).
        $snippet = (string) preg_replace(
            '/(value=("|\'))[^"\']*(("|\')[^>]*name=("|\')[^"\']*(pass|pwd|token|nonce|secret))/i',
            '$1[redacted]$3',
            $snippet
        );

        $max = 400;
        if (strlen($snippet) > $max) {
            $snippet = substr($snippet, 0, $max) . '…';
        }
        return trim($snippet);
    }

    /**
     * Build the string we scan for the login success/failure markers.
     *
     * Two deployment realities are covered:
     *
     *  - The default transport FOLLOWS redirects, so after a login POST
     *    the response is the *final* page (the dashboard at
     *    `?logged_in=1`, or the re-rendered login page at
     *    `?notify=failedlogin`). The marker therefore shows up in the
     *    response BODY — crucially, a rejected login's page renders the
     *    `notify=failedlogin` notice, which is the signal we must not
     *    miss. The `Location` header is usually already consumed by the
     *    redirect-follow in this mode.
     *  - A transport configured with `maxRedirects: 0` returns the raw
     *    3xx instead; there the `Location` header carries the marker.
     *
     * Scanning both the Location header and the body covers both modes.
     *
     * @param array{status:int,body:string,headers:array<string,string>} $resp
     */
    private function loginOutcomeHaystack(array $resp): string
    {
        $headers = $resp['headers'] ?? [];
        $location = is_array($headers) ? (string) ($headers['location'] ?? '') : '';
        return $location . "\n" . (string) ($resp['body'] ?? '');
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
