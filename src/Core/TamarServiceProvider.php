<?php

declare(strict_types=1);

namespace Tamar\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\HttpTransportFactory;
use Beacon\Transport\WpHttpTransportFactory;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;

/**
 * Wire Tamar's concrete drivers into Beacon's container.
 *
 * Three bindings:
 *
 *  1. {@see HttpTransportFactory} → {@see WpHttpTransportFactory}.
 *     Beacon owns the WP-HTTP transport now; Tamar just configures
 *     the factory (TLS verification + timeout from settings) and
 *     leaves construction to it.
 *
 *  2. {@see HttpTransport} → resolved by asking the factory for a
 *     fresh instance. WP's HTTP API respects host-level proxy and CA
 *     config that a raw cURL handle would ignore.
 *
 *  3. {@see CallForwardingService} → {@see HuntgroupCallForwardingService}.
 *     Driver specifically targets Tamar Telecommunications'
 *     `/phonedivert/huntgroup` editor. Settings are read inside the
 *     factory, not at registration time, so an admin-page save takes
 *     effect on the next request without needing a page reload.
 *
 * All bindings are factories so a request that never touches
 * forwarding (a front-end page hit) doesn't pay the cost of building
 * them.
 *
 * If a different upstream is ever needed, the right move is to write a
 * new service class implementing {@see CallForwardingService} and
 * bind it here — `tamar/register_services` lets sibling plugins
 * override even that.
 */
final class TamarServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        if (!method_exists($container, 'factory')) {
            \Tamar\Plugin::logError(
                'Container does not support factory bindings; Tamar cannot register its driver.',
                ['container_class' => get_class($container)]
            );
            return;
        }

        $container->factory(HttpTransportFactory::class, function () {
            $settings = \Tamar\Admin\TamarSettings::load();
            return new WpHttpTransportFactory(
                verifyTls: $settings['verify_tls'],
                timeoutSeconds: $settings['timeout'],
                // Attribute the generic Beacon transport's HTTP logging
                // to Tamar's own channel, so a log line names the plugin
                // the traffic belongs to rather than the transport class.
                logChannel: 'tamar',
            );
        });

        $container->factory(HttpTransport::class, function (ContainerInterface $c) {
            /** @var HttpTransportFactory $factory */
            $factory = $c->get(HttpTransportFactory::class);
            return $factory->create();
        });

        $container->factory(CallForwardingService::class, function (ContainerInterface $c) {
            /** @var HttpTransport $transport */
            $transport = $c->get(HttpTransport::class);
            $settings = \Tamar\Admin\TamarSettings::load();

            return new HuntgroupCallForwardingService(
                transport: $transport,
                parser: new HuntgroupPageParser(),
                builder: new HuntgroupFormBuilder(),
                baseUrl: rtrim($settings['base_url'], '/'),
                username: $settings['username'],
                password: \Tamar\Admin\TamarSettings::password(),
                huntgroupId: (string) $settings['huntgroup_id'],
                rulesPath: $settings['rules_path'],
                loginPath: $settings['login_path'],
                loginSubmitPath: $settings['login_submit_path'],
                updatePath: $settings['commit_path'],
            );
        });

        /**
         * Fires after Tamar has bound its services into the container.
         * Useful for sibling plugins that want to wrap or decorate the
         * driver — e.g. a caching layer, an audit log.
         *
         * @param ContainerInterface $container
         */
        do_action('tamar/register_services', $container);
    }
}
