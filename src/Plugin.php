<?php

declare(strict_types=1);

namespace Tamar;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Tamar\Core\TamarServiceProvider;
use Tamar\Admin\SettingsPage;

/**
 * Main Tamar Plugin Class.
 *
 * Tamar is the implementation plugin — it binds a concrete driver
 * for {@see \Beacon\Forwarding\Interfaces\CallForwardingService}
 * against Beacon's contract.
 *
 * The class is intentionally thin. Real work happens in the service
 * provider (container wiring) and the admin page (UI).
 */
class Plugin
{
    use \Tamar\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'tamar';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    public static function init(ContainerInterface $container): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $container;

        (new TamarServiceProvider())->register($container);

        // Admin UI bootstraps itself — it reads the bound service out
        // of the container when it needs it rather than holding a
        // reference at construction time. This means a later override
        // of CallForwardingService (e.g. a fake driver in test) is
        // picked up automatically.
        if (is_admin()) {
            (new SettingsPage($container))->register();
        }

        self::$initialized = true;

        self::logDebug('Initialised', ['version' => defined('TAMAR_VERSION') ? TAMAR_VERSION : 'unknown']);
    }

    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new \RuntimeException('Tamar Plugin not initialised — wait for the tamar/loaded action.');
        }
        return self::$container;
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
}
