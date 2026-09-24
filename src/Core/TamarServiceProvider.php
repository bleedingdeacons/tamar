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
use Tamar\Forwarding\PanelSessionStore;
use Tamar\Transport\ResumableSessionTransport;

/**
 * Wire Tamar's concrete drivers into Tamar's container.
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
 *     effect on the next request without needing a page reload. The
 *     driver's transport is wrapped in {@see ResumableSessionTransport}
 *     and handed a {@see PanelSessionStore}, so one login serves
 *     many requests instead of one.
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
    /**
     * Desktop Chrome on Windows, in the reduced form Chrome itself sends
     * (major version only). Chrome 154 was stable on 2026-09-22.
     *
     * This replaced Beacon's descriptive "Tamar/x.y.z (contact; site)"
     * user-agent. That shape was a fix for SiteGround blocking *inbound*
     * REST calls to this site, and had been carried onto this outbound
     * page scrape where it did nothing useful. The panel is an ordinary
     * logged-in web page, so a browser's user-agent is the honest fit.
     *
     * Nothing checks the version, but it ages; raise it now and then.
     */
    public const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36';

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
                // The panel is a web UI this plugin logs in to and reads
                // as a browser would, so it introduces itself as one.
                userAgent: self::BROWSER_USER_AGENT,
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
                transport: new ResumableSessionTransport($transport),
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
                sessions: new PanelSessionStore(),
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
