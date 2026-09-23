<?php

declare(strict_types=1);

/**
 * Tamar test bootstrap (Pest, running on PHPUnit).
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything below that defines WordPress functions of its own must stay after
 * the Bootstrap::load() call, not before it.
 *
 * Not loaded here: the `sentinel` stub group. Tamar\Logger\HasLogger is written
 * to no-op when wp_log() is absent — the shared logger mu-plugin is Sentinel's,
 * and Tamar does not depend on it — and that is the branch these tests run.
 *
 * The Beacon library Tamar builds on is a Composer dependency, so the autoloader
 * below supplies it.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::load(['wordpress']);

// Makes plugins_url()/plugin_dir_url() answer with Tamar's own path.
WpState::$pluginSlug = 'tamar';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// WP-CLI's symbols, for tests/Unit/RulesCommandTest.php. Loaded after
// Bootstrap::load() so Patchwork can redefine the WP_CLI\Utils functions;
// WP_CLI::error() throws here where the real one exits.
require_once __DIR__ . '/../stubs/wp-cli.php';
