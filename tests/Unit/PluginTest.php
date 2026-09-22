<?php

declare(strict_types=1);

namespace Tamar\Tests\Unit;

use Beacon\Forwarding\ForwardingRegistry;
use Tamar\Forwarding\HuntgroupCallForwardingService;
use Tamar\Plugin;

it('publishes its driver to the Beacon registry for Trusted to find', function () {
    if (!defined('TAMAR_OPTION_KEY')) {
        define('TAMAR_OPTION_KEY', 'tamar_settings');
    }
    ForwardingRegistry::clear();

    Plugin::init();

    expect(ForwardingRegistry::has())->toBeTrue()
        ->and(ForwardingRegistry::get())->toBeInstanceOf(HuntgroupCallForwardingService::class);
});
