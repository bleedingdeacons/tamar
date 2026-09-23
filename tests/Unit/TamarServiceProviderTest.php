<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Core\BeaconContainer;
use Beacon\Transport\Interfaces\HttpTransport;
use Brain\Monkey\Functions;
use Tamar\Core\TamarServiceProvider;

it('introduces requests to the panel with a browser user-agent', function () {
    if (!defined('TAMAR_OPTION_KEY')) {
        define('TAMAR_OPTION_KEY', 'tamar_settings');
    }

    $sent = null;
    Functions\when('wp_remote_request')->alias(function (string $url, array $args) use (&$sent): array {
        $sent = $args;
        return ['response' => ['code' => 200], 'body' => '', 'headers' => [], 'cookies' => []];
    });
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('');
    Functions\when('wp_remote_retrieve_headers')->justReturn([]);
    Functions\when('wp_remote_retrieve_cookies')->justReturn([]);

    $container = new BeaconContainer();
    (new TamarServiceProvider())->register($container);

    $transport = $container->get(HttpTransport::class);
    assert($transport instanceof HttpTransport);
    $transport->request('GET', 'https://panel.example/phonedivert/huntgroup');

    expect($sent['user-agent'])->toBe(TamarServiceProvider::BROWSER_USER_AGENT)
        ->and($sent['user-agent'])->toStartWith('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
        ->and($sent['user-agent'])->not->toContain('Tamar/');
});
