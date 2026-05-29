<?php

declare(strict_types=1);

/**
 * Tamar PHPUnit bootstrap.
 *
 * Defines ABSPATH and a small set of WP function shims so Tamar's
 * source files (which guard against direct access and call a handful
 * of WP utilities) load under PHPUnit without a real WordPress.
 *
 * Real integration tests would use wp_mock / Brain Monkey; this
 * minimal shim is enough for the unit tests that exercise the
 * driver against a fake transport.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// PSR-11 stubs — Beacon's container uses them.
require_once __DIR__ . '/../../beacon/tests/stubs/Psr/Container/ContainerExceptionInterface.php';
require_once __DIR__ . '/../../beacon/tests/stubs/Psr/Container/NotFoundExceptionInterface.php';
require_once __DIR__ . '/../../beacon/tests/stubs/Psr/Container/ContainerInterface.php';

// Beacon source — Tamar depends on its interfaces and base class.
$beaconSrc = __DIR__ . '/../../beacon/src';
require_once $beaconSrc . '/Forwarding/Interfaces/CallForwardingService.php';
require_once $beaconSrc . '/Forwarding/Interfaces/ForwardingException.php';
require_once $beaconSrc . '/Forwarding/Models/ForwardingRule.php';
require_once $beaconSrc . '/Forwarding/AbstractCallForwardingService.php';
require_once $beaconSrc . '/Targets/Models/ForwardingTarget.php';
require_once $beaconSrc . '/Transport/Interfaces/HttpTransport.php';
require_once $beaconSrc . '/Transport/Interfaces/TransportException.php';

// Tamar source.
require_once __DIR__ . '/../src/Forwarding/HuntgroupPageParser.php';
require_once __DIR__ . '/../src/Forwarding/HuntgroupFormBuilder.php';

// The driver uses Tamar\Logger\HasLogger; we shim it for tests.
if (!trait_exists('Tamar\\Logger\\HasLogger')) {
    eval('namespace Tamar\\Logger; trait HasLogger {
        public static function logError(string $m, array $c = []): void {}
        public static function logWarning(string $m, array $c = []): void {}
        public static function logInfo(string $m, array $c = []): void {}
        public static function logDebug(string $m, array $c = []): void {}
    }');
}

require_once __DIR__ . '/../src/Forwarding/HuntgroupCallForwardingService.php';
