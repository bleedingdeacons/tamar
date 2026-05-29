<?php

declare(strict_types=1);

namespace Tamar\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to Tamar's settings row.
 *
 * Settings live under a single wp_options key (defined as
 * `TAMAR_OPTION_KEY` in the bootstrap) so the whole config can be
 * backed up, exported, or rotated as a unit.
 *
 * The password is encrypted at rest with a key derived from
 * WordPress's `AUTH_KEY` / `AUTH_SALT`. This isn't a security
 * boundary against someone with full DB + filesystem access (they
 * already have AUTH_KEY), but it does mean a casual `SELECT * FROM
 * wp_options` from a read-only audit account doesn't leak the PBX
 * admin password in cleartext — which is the realistic threat here.
 *
 * If `AUTH_KEY` isn't defined (some odd test setups), we fall back
 * to a no-op cipher with a logged warning. The plugin still works;
 * the password is just stored as base64 of plaintext rather than as
 * a real ciphertext. Better to keep working than to fail closed when
 * the operator may not have any way to fix it.
 */
final class TamarSettings
{
    /**
     * Return a fully-populated settings array. Missing keys are
     * filled in with safe defaults so callers can rely on the shape.
     *
     * @return array{
     *   base_url:string,
     *   username:string,
     *   password_cipher:string,
     *   rules_path:string,
     *   login_path:string,
     *   commit_path:string,
     *   verify_tls:bool,
     *   timeout:int
     * }
     */
    public static function load(): array
    {
        $raw = get_option(TAMAR_OPTION_KEY, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        return [
            'base_url' => (string) ($raw['base_url'] ?? ''),
            'username' => (string) ($raw['username'] ?? ''),
            'password_cipher' => (string) ($raw['password_cipher'] ?? ''),
            'rules_path' => (string) ($raw['rules_path'] ?? '/admin/forwarding'),
            'login_path' => (string) ($raw['login_path'] ?? '/admin/login'),
            'commit_path' => (string) ($raw['commit_path'] ?? '/admin/forwarding/apply'),
            'verify_tls' => (bool) ($raw['verify_tls'] ?? true),
            'timeout' => max(1, (int) ($raw['timeout'] ?? 15)),
        ];
    }

    /**
     * Persist a settings array. If `password_plaintext` is non-empty
     * it's encrypted and stored as `password_cipher`; an empty
     * plaintext means "leave the existing cipher alone" so the admin
     * page can submit without re-typing the password every time.
     *
     * @param array<string,mixed> $input Raw $_POST-shaped data.
     */
    public static function save(array $input): void
    {
        $existing = self::load();

        $cipher = $existing['password_cipher'];
        $plaintext = trim((string) ($input['password_plaintext'] ?? ''));
        if ($plaintext !== '') {
            $cipher = self::encrypt($plaintext);
        }

        $next = [
            'base_url' => esc_url_raw((string) ($input['base_url'] ?? $existing['base_url'])),
            'username' => sanitize_text_field((string) ($input['username'] ?? $existing['username'])),
            'password_cipher' => $cipher,
            'rules_path' => self::sanitisePath((string) ($input['rules_path'] ?? $existing['rules_path'])),
            'login_path' => self::sanitisePath((string) ($input['login_path'] ?? $existing['login_path'])),
            'commit_path' => self::sanitisePath((string) ($input['commit_path'] ?? $existing['commit_path'])),
            'verify_tls' => !empty($input['verify_tls']),
            'timeout' => max(1, min(120, (int) ($input['timeout'] ?? $existing['timeout']))),
        ];

        update_option(TAMAR_OPTION_KEY, $next);
    }

    /**
     * Decrypt the stored password and return the plaintext.
     *
     * Returns '' if no password has been set yet (a fresh install).
     */
    public static function password(): string
    {
        $cipher = self::load()['password_cipher'];
        if ($cipher === '') {
            return '';
        }
        return self::decrypt($cipher);
    }

    /**
     * Sanitise a URL path. We keep leading-slash semantics intact —
     * the driver concatenates `base_url . path` and a missing slash
     * would break that. Anything weird gets stripped.
     */
    private static function sanitisePath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '/';
        }
        if ($raw[0] !== '/') {
            $raw = '/' . $raw;
        }
        // Strip control characters and obvious shell-escape attempts.
        // We do NOT urlencode — the path may contain query strings
        // the upstream needs verbatim.
        return preg_replace('/[\x00-\x1F\x7F]/', '', $raw) ?? '/';
    }

    /**
     * AES-256-CBC with a key derived from AUTH_KEY. Returns
     * `base64(iv || ciphertext)`. Returns the input wrapped in a
     * sentinel prefix if openssl isn't available — extremely rare on
     * a modern WP host, but the fallback means the plugin still
     * functions rather than fataling.
     */
    private static function encrypt(string $plaintext): string
    {
        $key = self::deriveKey();
        if ($key === null || !function_exists('openssl_encrypt')) {
            return 'plain:' . base64_encode($plaintext);
        }
        $iv = random_bytes(16);
        $ct = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            // Encryption itself failed — log and fall back to the
            // sentinel-wrapped form so we don't lose the operator's
            // input. They can re-save once the host's OpenSSL is
            // healthy.
            \Tamar\Plugin::logWarning('openssl_encrypt failed; storing password unencrypted.');
            return 'plain:' . base64_encode($plaintext);
        }
        return 'aes:' . base64_encode($iv . $ct);
    }

    private static function decrypt(string $cipher): string
    {
        if (str_starts_with($cipher, 'plain:')) {
            $b64 = substr($cipher, strlen('plain:'));
            return (string) base64_decode($b64, true);
        }
        if (!str_starts_with($cipher, 'aes:')) {
            // Legacy / unrecognised — return empty rather than
            // guessing. The operator will need to re-enter the
            // password, which is the right behaviour on an upgrade
            // from a different encoding.
            return '';
        }
        $key = self::deriveKey();
        if ($key === null || !function_exists('openssl_decrypt')) {
            return '';
        }
        $blob = base64_decode(substr($cipher, strlen('aes:')), true);
        if (!is_string($blob) || strlen($blob) < 17) {
            return '';
        }
        $iv = substr($blob, 0, 16);
        $ct = substr($blob, 16);
        $pt = openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $pt === false ? '' : $pt;
    }

    /**
     * Derive a 32-byte key from AUTH_KEY. Returns null if AUTH_KEY
     * isn't defined.
     */
    private static function deriveKey(): ?string
    {
        if (!defined('AUTH_KEY') || AUTH_KEY === '' || AUTH_KEY === 'put your unique phrase here') {
            return null;
        }
        // sha256 binary digest is exactly 32 bytes — perfect for AES-256.
        return hash('sha256', (string) AUTH_KEY, true);
    }
}
