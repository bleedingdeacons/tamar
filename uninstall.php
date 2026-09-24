<?php

/**
 * Fired when Tamar is uninstalled.
 *
 * Removes Tamar's options rows (the settings and the stored panel
 * session) and the forwarding roles, which Tamar took over when Beacon
 * became a library. The upstream PBX's config is not Tamar's to delete.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('tamar_settings');
delete_option('tamar_panel_session');

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    \Beacon\Capabilities\CapabilityBootstrap::remove();
}
