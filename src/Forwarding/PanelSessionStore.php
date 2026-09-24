<?php

declare(strict_types=1);

namespace Tamar\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

use Tamar\Admin\TamarSettings;

/**
 * Keeps the panel's session cookies between WordPress requests, so the
 * driver can log in once and carry on using that session until the
 * panel stops accepting it.
 *
 * One non-autoloaded `wp_options` row, {@see OPTION}. The cookies are a
 * live login for as long as the panel honours them, so they are
 * encrypted with the password's key and scheme ({@see TamarSettings::seal()}).
 *
 * A session is retired after a lifetime drawn at random between
 * {@see MIN_LIFETIME} and {@see MAX_LIFETIME} when it is created, even
 * if the panel would still accept it. The panel's own `loginsession`
 * cookie lasts eight hours from issue, and its PHP session may lapse
 * sooner when idle; a session that always ran to the limit, or always
 * to the same length, is a regular pattern of its own. The draw is made
 * once per session and stored — drawing again on every request would
 * drag the effective lifetime down towards the minimum.
 *
 * The row records who it belongs to — a hash of the panel URL and the
 * username — and {@see load()} ignores a session belonging to anyone
 * else. That covers settings changed without going through the
 * settings page (WP-CLI, a restored backup, a copied database), where
 * sending one panel's cookies to another would be a leak rather than
 * just a failed login. Saving the settings page clears the row outright.
 */
final class PanelSessionStore
{
    public const OPTION = 'tamar_panel_session';

    /** Shortest lifetime a session can be given, in seconds. */
    public const MIN_LIFETIME = 600;

    /** Longest lifetime a session can be given, in seconds. */
    public const MAX_LIFETIME = 1800;

    /**
     * When a session created at $createdAt should be retired.
     */
    public static function drawExpiry(int $createdAt): int
    {
        return $createdAt + random_int(self::MIN_LIFETIME, self::MAX_LIFETIME);
    }

    /**
     * Identifies whose session a row holds. Deliberately excludes the
     * password: a hash of it in wp_options would be something to crack.
     */
    public static function owner(string $baseUrl, string $username): string
    {
        return hash('sha256', strtolower(rtrim($baseUrl, '/')) . "\n" . $username);
    }

    /**
     * Expiry is left to the caller, which wants to log it.
     *
     * @return array{cookies: array<string,string>, created_at: int, expires_at: int}|null
     *         Null when nothing usable is stored for this owner.
     */
    public function load(string $owner): ?array
    {
        $row = get_option(self::OPTION, null);
        if (!is_array($row) || ($row['owner'] ?? null) !== $owner || !is_string($row['cookies'] ?? null)) {
            return null;
        }

        $decoded = json_decode(TamarSettings::unseal($row['cookies']), true);
        if (!is_array($decoded)) {
            return null;
        }

        $cookies = [];
        foreach ($decoded as $name => $value) {
            if (is_string($name) && $name !== '' && is_string($value)) {
                $cookies[$name] = $value;
            }
        }
        if ($cookies === []) {
            return null;
        }

        return [
            'cookies' => $cookies,
            'created_at' => is_int($row['created_at'] ?? null) ? $row['created_at'] : 0,
            // A row without an expiry is treated as already expired.
            'expires_at' => is_int($row['expires_at'] ?? null) ? $row['expires_at'] : 0,
        ];
    }

    /**
     * @param array<string,string> $cookies  Name → value.
     * @param int                  $createdAt When the session was first logged in,
     *                                        kept across re-saves so the log can say
     *                                        how long a session lasted.
     * @param int                  $expiresAt When to retire it, from {@see drawExpiry()}.
     */
    public function save(string $owner, array $cookies, int $createdAt, int $expiresAt): void
    {
        $json = json_encode($cookies);
        if ($json === false) {
            return;
        }

        update_option(self::OPTION, [
            'owner' => $owner,
            'cookies' => TamarSettings::seal($json),
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'saved_at' => time(),
        ], false);
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}
