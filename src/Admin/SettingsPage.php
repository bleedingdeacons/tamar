<?php

declare(strict_types=1);

namespace Tamar\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;

/**
 * Tamar's admin settings page.
 *
 * Two halves:
 *
 *  1. A form for the upstream connection — base URL, credentials,
 *     paths, TLS verification, timeout. Submitting POSTs back to
 *     `admin-post.php`; we handle the save in `handleSave()` and
 *     redirect to avoid the resubmit-on-reload problem.
 *
 *  2. A "current state" panel that lists rules and targets out of
 *     the bound CallForwardingService, plus a "Test connection"
 *     button and an "Apply pending changes" button (which calls
 *     `commit()` on the service).
 *
 * The page reads the driver out of the container *per-request*, not
 * at construction time, so a sibling plugin that swaps in a different
 * driver via `tamar/register_services` is transparently picked up.
 *
 * Capability checks: every privileged action checks the relevant
 * Beacon capability before running. The form submission checks
 * `beacon_manage_forwarding`; the commit button checks
 * `beacon_push_config`; the list view checks `beacon_view_forwarding`.
 */
final class SettingsPage
{
    use \Tamar\Logger\HasLogger;

    /** Top-level menu slug — a container only, with no page of its own. */
    private const MENU_SLUG = 'tamar';

    /** Distinct slugs per page — each must differ from MENU_SLUG to render as a child. */
    private const OVERVIEW_SLUG = 'tamar-overview';
    private const SETTINGS_SLUG = 'tamar-settings';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_tamar_save_settings', [$this, 'handleSave']);
        add_action('admin_post_tamar_test_connection', [$this, 'handleTest']);
        add_action('admin_post_tamar_commit', [$this, 'handleCommit']);
    }

    public function addMenu(): void
    {
        // Top-level entry is a *container* only — its callback is
        // __return_null and it has no page of its own. Each real page
        // is a submenu with its OWN distinct slug. (The previous code
        // gave the submenu the same slug as the parent, which collapses
        // to a single link in some WP setups and hides the "Settings"
        // child — Sentinel's menu works precisely because it avoids that.)
        add_menu_page(
            __('Tamar — Call Forwarding', 'tamar'),
            __('Tamar', 'tamar'),
            'beacon_view_forwarding',
            self::MENU_SLUG,
            '__return_null',
            'dashicons-phone',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Tamar — Forwarding overview', 'tamar'),
            __('Overview', 'tamar'),
            'beacon_view_forwarding',
            self::OVERVIEW_SLUG,
            [$this, 'renderOverview']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Tamar — Settings', 'tamar'),
            __('Settings', 'tamar'),
            'beacon_view_forwarding',
            self::SETTINGS_SLUG,
            [$this, 'renderSettings']
        );

        // add_menu_page auto-creates a first submenu that duplicates the
        // parent label ("Tamar") and points at the empty container page.
        // Strip it after every admin_menu callback has registered, so the
        // only visible child is "Settings".
        add_action('admin_menu', [$this, 'removeDuplicateSubmenu'], 999);
    }

    /**
     * Remove the auto-generated first submenu whose slug equals the
     * parent menu slug, leaving just the real "Settings" page.
     */
    public function removeDuplicateSubmenu(): void
    {
        global $submenu;

        if (empty($submenu[self::MENU_SLUG])) {
            return;
        }

        foreach ($submenu[self::MENU_SLUG] as $index => $item) {
            // $item[2] is the submenu slug; the auto entry reuses the parent slug.
            if (isset($item[2]) && $item[2] === self::MENU_SLUG) {
                unset($submenu[self::MENU_SLUG][$index]);
                break;
            }
        }
    }

    public function renderOverview(): void
    {
        if (!current_user_can('beacon_view_forwarding')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'tamar'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Tamar — Forwarding overview', 'tamar') . '</h1>';
        $this->renderFlashNotice();
        $this->renderStatePanel();
        echo '</div>';
    }

    public function renderSettings(): void
    {
        if (!current_user_can('beacon_view_forwarding')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'tamar'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Tamar — Settings', 'tamar') . '</h1>';
        $this->renderFlashNotice();
        $this->renderSettingsForm(TamarSettings::load());
        echo '</div>';
    }

    private function renderFlashNotice(): void
    {
        $notice = $this->consumeFlash();
        if ($notice !== null) {
            echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>'
                . esc_html($notice['message']) . '</p></div>';
        }
    }

    private function renderSettingsForm(array $settings): void
    {
        $canEdit = current_user_can('beacon_manage_forwarding');
        $disabled = $canEdit ? '' : ' disabled';
        echo '<h2>' . esc_html__('Upstream connection', 'tamar') . '</h2>';
        if (!$canEdit) {
            echo '<p><em>' . esc_html__('Read-only — your role can view but not change these settings.', 'tamar') . '</em></p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tamar_save_settings">';
        wp_nonce_field('tamar_save_settings');

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="tamar-base-url">' . esc_html__('Base URL', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-base-url" name="base_url" type="url" class="regular-text" value="' . esc_attr($settings['base_url']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('e.g. https://pbx.example.com — no trailing slash.', 'tamar') . '</p></td></tr>';

        echo '<tr><th><label for="tamar-username">' . esc_html__('Username', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-username" name="username" type="text" class="regular-text" value="' . esc_attr($settings['username']) . '"' . $disabled . '></td></tr>';

        echo '<tr><th><label for="tamar-password">' . esc_html__('Password', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-password" name="password_plaintext" type="password" class="regular-text" autocomplete="new-password"' . $disabled . '>';
        if ($settings['password_cipher'] !== '') {
            echo '<p class="description">' . esc_html__('A password is set. Leave blank to keep it; type a new one to replace it.', 'tamar') . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th><label for="tamar-rules-path">' . esc_html__('Hunt-group page path', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-rules-path" name="rules_path" type="text" class="regular-text" value="' . esc_attr($settings['rules_path']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('GET endpoint for the editor. Default: /phonedivert/huntgroup', 'tamar') . '</p></td></tr>';

        echo '<tr><th><label for="tamar-login-path">' . esc_html__('Login path', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-login-path" name="login_path" type="text" class="regular-text" value="' . esc_attr($settings['login_path']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Default: /phonedivert/login/', 'tamar') . '</p></td></tr>';

        echo '<tr><th><label for="tamar-commit-path">' . esc_html__('Update path', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-commit-path" name="commit_path" type="text" class="regular-text" value="' . esc_attr($settings['commit_path']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('POST endpoint that saves the rota. Default: /phonedivert/huntgroup/update', 'tamar') . '</p></td></tr>';

        // Hunt-group ID — appended to the rules path as a query string
        // (?huntgroup=<id>). The upstream's edit URL is per-client, so
        // this number is what scopes Tamar to *this* WordPress site's
        // hunt group rather than every hunt group on the account.
        echo '<tr><th><label for="tamar-huntgroup-id">' . esc_html__('Hunt group ID', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-huntgroup-id" name="huntgroup_id" type="text" class="regular-text" value="' . esc_attr($settings['huntgroup_id']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Numeric ID from the upstream edit URL — e.g. 157626 in ".../huntgroup?huntgroup=157626".', 'tamar') . '</p></td></tr>';

        echo '<tr><th><label for="tamar-verify-tls">' . esc_html__('Verify TLS certificate', 'tamar') . '</label></th>';
        echo '<td><label><input id="tamar-verify-tls" name="verify_tls" type="checkbox" value="1"' . checked($settings['verify_tls'], true, false) . $disabled . '> ' . esc_html__('Recommended on; disable only for development against a self-signed certificate.', 'tamar') . '</label></td></tr>';

        echo '<tr><th><label for="tamar-timeout">' . esc_html__('Timeout (seconds)', 'tamar') . '</label></th>';
        echo '<td><input id="tamar-timeout" name="timeout" type="number" min="1" max="120" value="' . esc_attr((string) $settings['timeout']) . '"' . $disabled . '></td></tr>';

        echo '</tbody></table>';

        // Both buttons live inside the settings form so the values
        // currently in the fields are POSTed (and saved) before we act.
        // "Test connection" sets tamar_do_test=1, which tells handleSave
        // to save first and then run the connection test.
        if ($canEdit) {
            echo '<p class="submit">';
            echo '<button type="submit" name="submit" class="button button-primary">' . esc_html__('Save settings', 'tamar') . '</button> ';
            echo '<button type="submit" name="tamar_do_test" value="1" class="button">' . esc_html__('Save and test connection', 'tamar') . '</button>';
            echo '</p>';
        }
        echo '</form>';
    }

    private function renderStatePanel(): void
    {
        echo '<h2>' . esc_html__('Current forwarding state', 'tamar') . '</h2>';

        if (!$this->container->has(CallForwardingService::class)) {
            echo '<p>' . esc_html__('No driver is bound. (Beacon has loaded, but Tamar failed to register its driver.)', 'tamar') . '</p>';
            return;
        }

        /** @var CallForwardingService $service */
        $service = $this->container->get(CallForwardingService::class);

        try {
            $rules = $service->listRules();
            $targets = $service->listTargets();
        } catch (ForwardingException $e) {
            echo '<p class="notice notice-error" style="padding:0.5em 1em;">' . esc_html($e->getMessage()) . '</p>';
            self::logWarning('Settings page could not list state', ['error' => $e->getMessage()]);
            return;
        }

        (new ForwardingOverview())->render($rules, $targets);

        if (current_user_can('beacon_push_config')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:1em;">';
            echo '<input type="hidden" name="action" value="tamar_commit">';
            wp_nonce_field('tamar_commit');
            echo '<button class="button button-primary" type="submit">' . esc_html__('Apply pending changes upstream', 'tamar') . '</button>';
            echo ' <span class="description">' . esc_html__('Some PBXes require an explicit apply step after edits.', 'tamar') . '</span>';
            echo '</form>';
        }
    }

    public function handleSave(): void
    {
        if (!current_user_can('beacon_manage_forwarding')) {
            wp_die(esc_html__('You do not have permission to change Tamar settings.', 'tamar'));
        }
        check_admin_referer('tamar_save_settings');

        TamarSettings::save($_POST);

        // "Save and test connection" was clicked: persist first (done
        // above), then run the test against the freshly saved settings.
        if (isset($_POST['tamar_do_test'])) {
            $this->runConnectionTest();
            $this->redirectTo(self::SETTINGS_SLUG);
            return;
        }

        $this->setFlash('success', __('Tamar settings saved.', 'tamar'));
        $this->redirectTo(self::SETTINGS_SLUG);
    }

    public function handleTest(): void
    {
        if (!current_user_can('beacon_view_forwarding')) {
            wp_die(esc_html__('You do not have permission to test the Tamar connection.', 'tamar'));
        }
        check_admin_referer('tamar_test_connection');

        $this->runConnectionTest();
        $this->redirectTo(self::SETTINGS_SLUG);
    }

    private function runConnectionTest(): void
    {
        try {
            /** @var CallForwardingService $service */
            $service = $this->container->get(CallForwardingService::class);
            $service->testConnection();
            $this->setFlash('success', __('Connection OK — Tamar reached the upstream and parsed the rules page.', 'tamar'));
        } catch (\Throwable $e) {
            $this->setFlash('error', __('Connection failed: ', 'tamar') . $e->getMessage());
        }
    }

    public function handleCommit(): void
    {
        if (!current_user_can('beacon_push_config')) {
            wp_die(esc_html__('You do not have permission to push forwarding changes upstream.', 'tamar'));
        }
        check_admin_referer('tamar_commit');

        try {
            /** @var CallForwardingService $service */
            $service = $this->container->get(CallForwardingService::class);
            $service->commit();
            $this->setFlash('success', __('Forwarding changes pushed upstream.', 'tamar'));
        } catch (\Throwable $e) {
            $this->setFlash('error', __('Commit failed: ', 'tamar') . $e->getMessage());
        }

        $this->redirectTo(self::OVERVIEW_SLUG);
    }

    /**
     * Flash messages travel through a transient keyed by user ID —
     * survives the post-redirect-get without leaking between admins.
     */
    private function setFlash(string $type, string $message): void
    {
        set_transient($this->flashKey(), ['type' => $type, 'message' => $message], 30);
    }

    /**
     * @return array{type:string,message:string}|null
     */
    private function consumeFlash(): ?array
    {
        $flash = get_transient($this->flashKey());
        if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
            return null;
        }
        delete_transient($this->flashKey());
        return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
    }

    private function flashKey(): string
    {
        return 'tamar_flash_' . get_current_user_id();
    }

    private function redirectTo(string $slug): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . $slug));
        exit;
    }
}
