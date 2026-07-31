<?php

declare(strict_types=1);

/**
 * Tamar PHPUnit bootstrap.
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
 * Beyond the stubs this still loads the Beacon source Tamar builds on, which is
 * a sibling plugin and so not reachable from Composer's autoloader here.
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

// Beacon source — Tamar depends on its interfaces and base class.
// HasLogger must load first: AbstractCallForwardingService uses it, and
// the trait is a safe no-op when the Sentinel logger isn't present.
$beaconSrc = __DIR__ . '/../../beacon/src';
require_once $beaconSrc . '/Logger/HasLogger.php';
require_once $beaconSrc . '/Forwarding/Interfaces/CallForwardingService.php';
require_once $beaconSrc . '/Forwarding/Interfaces/ForwardingException.php';
require_once $beaconSrc . '/Forwarding/Models/ForwardingRule.php';
require_once $beaconSrc . '/Forwarding/AbstractCallForwardingService.php';
require_once $beaconSrc . '/Targets/Models/ForwardingTarget.php';
require_once $beaconSrc . '/Transport/Interfaces/HttpTransport.php';
require_once $beaconSrc . '/Transport/Interfaces/TransportException.php';
