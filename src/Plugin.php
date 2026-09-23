<?php

declare(strict_types=1);

namespace Tamar;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Beacon\Core\BeaconContainer;
use Beacon\Forwarding\ForwardingRegistry;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Rest\ForwardingRestController;
use Tamar\Core\TamarServiceProvider;
use Tamar\Admin\SettingsPage;
use Tamar\Cli\RulesCommand;

/**
 * Main Tamar Plugin Class.
 *
 * Tamar is the call-forwarding plugin. It owns the container its driver
 * is wired in, publishes that driver through the Beacon library's
 * {@see ForwardingRegistry} for Trusted to find, and carries what the
 * Beacon plugin used to: the forwarding roles and the opt-in REST API.
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

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $container = new BeaconContainer();
        self::$container = $container;

        (new TamarServiceProvider())->register($container);

        ForwardingRegistry::bind(static function () use ($container): CallForwardingService {
            $service = $container->get(CallForwardingService::class);
            if (!$service instanceof CallForwardingService) {
                throw new \RuntimeException('Tamar bound something other than a CallForwardingService.');
            }
            return $service;
        });

        // Off unless wp-config.php opts in: this API decides where helpline
        // calls are routed, and the in-process registry is the supported path.
        if (defined('BEACON_ENABLE_REST') && BEACON_ENABLE_REST) {
            (new ForwardingRestController($container))->register();
            self::logWarning('Forwarding REST API enabled via BEACON_ENABLE_REST — this exposes call-forwarding control over HTTP.');
        }

        // Admin UI bootstraps itself — it reads the bound service out
        // of the container when it needs it rather than holding a
        // reference at construction time. This means a later override
        // of CallForwardingService (e.g. a fake driver in test) is
        // picked up automatically.
        if (is_admin()) {
            (new SettingsPage($container))->register();
        }

        // Only under WP-CLI: RulesCommand extends WP_CLI_Command, which
        // does not exist on a web request.
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('tamar rules', new RulesCommand($container));
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
