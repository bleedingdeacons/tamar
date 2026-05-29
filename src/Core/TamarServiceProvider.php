<?php

declare(strict_types=1);

namespace Tamar\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Transport\Interfaces\HttpTransport;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Forwarding\HuntgroupFormBuilder;
use Tamar\Forwarding\HuntgroupPageParser;
use Tamar\Transport\WpHttpTransport;

/**
 * Wire Tamar's concrete drivers into Beacon's container.
 *
 * Two bindings:
 *
 *  1. {@see HttpTransport} → {@see WpHttpTransport}. WP's HTTP API
 *     respects host-level proxy and CA config that a raw cURL handle
 *     would ignore.
 *
 *  2. {@see CallForwardingService} → {@see HuntgroupCallForwardingService}.
 *     Driver specifically targets Tamar Telecommunications'
 *     `/phonedivert/huntgroup` editor. Settings are read inside the
 *     factory, not at registration time, so an admin-page save takes
 *     effect on the next request without needing a page reload.
 *
 * Both bindings are factories so a request that never touches
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

        $container->factory(HttpTransport::class, function () {
            $settings = \Tamar\Admin\TamarSettings::load();
            return new WpHttpTransport(
                verifyTls: $settings['verify_tls'],
                timeoutSeconds: $settings['timeout'],
            );
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
