<?php

/**
 * Fired when Tamar is uninstalled.
 *
 * Removes Tamar's options row. Beacon's capabilities are owned by
 * Beacon and cleaned up by its own uninstaller; the upstream PBX's
 * config is not Tamar's to delete.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('tamar_settings');
