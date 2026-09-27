<?php

declare(strict_types=1);

namespace Tamar\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Psr\Container\ContainerInterface;
use Tamar\Admin\TamarSettings;

/**
 * The `tamar/publish_huntgroup` filter: how another plugin writes a whole
 * rota into a named hunt group without depending on Tamar's classes.
 *
 *     $published = apply_filters('tamar/publish_huntgroup', null, 'Forward Week 5', $rules);
 *
 * $rules is a list of Beacon ForwardingRule models. The hunt group is
 * created if the account has none by that name, every row in it is
 * replaced, and it becomes the configured hunt group, so Tamar's Overview
 * shows it. Returns `['id' => …, 'name' => …]`. Failure is a
 * ForwardingException, which passes straight back through
 * apply_filters() to the caller, rather than a return value it could
 * mistake for success. Trusted's Forwarding page is the caller.
 *
 * Whether the filter is registered — `has_filter()` — is how a caller
 * knows Tamar is there to publish to.
 */
final class HuntgroupPublishFilter
{
    public const HOOK = 'tamar/publish_huntgroup';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function register(): void
    {
        add_filter(self::HOOK, [$this, 'publish'], 10, 3);
    }

    /**
     * @param mixed $published Whatever an earlier callback returned; ignored.
     * @param mixed $rules     A list of ForwardingRule.
     * @return array{id:string,name:string}
     * @throws ForwardingException
     */
    public function publish(mixed $published, string $name, mixed $rules): array
    {
        // Creating a hunt group and repointing the settings at it is a
        // settings change, not just a push of existing rules.
        if (!current_user_can('beacon_manage_forwarding')) {
            throw new ForwardingException('You do not have permission to publish forwarding to Tamar.');
        }

        if (!is_array($rules)) {
            throw new ForwardingException('Publishing a hunt group needs a list of forwarding rules.');
        }
        foreach ($rules as $rule) {
            if (!$rule instanceof ForwardingRule) {
                throw new ForwardingException('Publishing a hunt group needs a list of forwarding rules.');
            }
        }

        $service = $this->container->has(CallForwardingService::class)
            ? $this->container->get(CallForwardingService::class)
            : null;
        if (!$service instanceof HuntgroupCallForwardingService) {
            throw new ForwardingException('The bound forwarding driver cannot create hunt groups.');
        }

        /** @var list<ForwardingRule> $rules */
        $id = $service->publishHuntgroup($name, $rules);
        TamarSettings::saveHuntgroupId($id);

        return ['id' => $id, 'name' => trim($name)];
    }
}
