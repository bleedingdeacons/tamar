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

/**
 * The `tamar/find_huntgroup` filter: how another plugin reads a named hunt
 * group without depending on Tamar's classes, and without changing it.
 *
 *     $group = apply_filters('tamar/find_huntgroup', null, 'Forward Week 5');
 *
 * Returns `['id' => …, 'name' => …, 'rules' => ForwardingRule[]]`, or null
 * when the account has no hunt group by that name. Nothing is written to
 * the panel and the configured hunt group is left alone. Failure is a
 * ForwardingException, which passes straight back through apply_filters()
 * to the caller, so an unreachable panel cannot be mistaken for a missing
 * group. Trusted's Rota Calendar is the caller, checking the current week.
 *
 * Whether the filter is registered — `has_filter()` — is how a caller
 * knows Tamar is there to read from.
 */
final class HuntgroupFindFilter
{
    public const HOOK = 'tamar/find_huntgroup';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function register(): void
    {
        add_filter(self::HOOK, [$this, 'find'], 10, 2);
    }

    /**
     * @param mixed $found Whatever an earlier callback returned; ignored.
     * @return array{id:string,name:string,rules:array<int,ForwardingRule>}|null
     * @throws ForwardingException
     */
    public function find(mixed $found, string $name): ?array
    {
        if (!current_user_can('beacon_view_forwarding')) {
            throw new ForwardingException('You do not have permission to read forwarding from Tamar.');
        }

        $service = $this->container->has(CallForwardingService::class)
            ? $this->container->get(CallForwardingService::class)
            : null;
        if (!$service instanceof HuntgroupCallForwardingService) {
            throw new ForwardingException('The bound forwarding driver cannot look up hunt groups by name.');
        }

        return $service->findHuntgroup($name);
    }
}
