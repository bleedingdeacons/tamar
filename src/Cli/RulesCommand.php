<?php

declare(strict_types=1);

namespace Tamar\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;
use Psr\Container\ContainerInterface;
use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

/**
 * Read the hunt group's forwarding rules from the command line.
 *
 * Reads through the bound CallForwardingService — the same listRules() and
 * listTargets() the Overview page calls — so it shows what the upstream
 * holds now, not a cached copy, and needs the upstream credentials in
 * Settings to work.
 *
 * ## EXAMPLES
 *
 *     wp tamar rules list
 *     wp tamar rules list --day=mon
 *     wp tamar rules list --format=json
 */
final class RulesCommand extends WP_CLI_Command
{
    /** Day codes as the time-window match stores them, Monday first. */
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const DEFAULT_FIELDS = ['priority', 'label', 'days', 'from', 'to', 'target', 'telephone', 'enabled'];

    /**
     * The service is read out of the container when a command runs rather
     * than at registration, so a CLI invocation that never touches Tamar
     * never builds the HTTP client.
     */
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * List the forwarding rules in the hunt group.
     *
     * Rules print in hunt order. With --day, only that day's rules print,
     * earliest first, with hunt order breaking ties — the order the
     * Overview page uses.
     *
     * ## OPTIONS
     *
     * [--day=<day>]
     * : Only rules that apply on this day. A catchall applies every day; a
     * time window with no days ticked applies on none.
     * ---
     * options:
     *   - mon
     *   - tue
     *   - wed
     *   - thu
     *   - fri
     *   - sat
     *   - sun
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show. Default: priority,label,days,from,to,target,telephone,enabled.
     * Also available: id, match, target_id, kind, address.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - ids
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp tamar rules list
     *     wp tamar rules list --day=mon
     *     wp tamar rules list --fields=id,label,address --format=csv
     *
     * @subcommand list
     *
     * @param array<int, string>    $args      Positional arguments, unused.
     * @param array<string, string> $assocArgs Flags, as WP-CLI parsed them.
     */
    public function listRules(array $args, array $assocArgs = []): void
    {
        unset($args);

        $day = strtolower((string) Utils\get_flag_value($assocArgs, 'day', ''));
        if ($day !== '' && !in_array($day, self::DAYS, true)) {
            WP_CLI::error(sprintf('Unknown day "%s". Use one of: %s.', $day, implode(', ', self::DAYS)));
        }

        $format = (string) Utils\get_flag_value($assocArgs, 'format', 'table');
        $fields = Utils\get_flag_value($assocArgs, 'fields', self::DEFAULT_FIELDS);
        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        $service = $this->container->get(CallForwardingService::class);
        if (!$service instanceof CallForwardingService) {
            WP_CLI::error('No forwarding driver is bound — Tamar failed to register its driver.');
        }

        try {
            $rules = $service->listRules();
            $targets = $service->listTargets();
        } catch (ForwardingException $e) {
            WP_CLI::error($e->getMessage());
        }

        $rows = $this->rows($rules, $targets, $day);

        if ($rows === [] && !in_array($format, ['json', 'ids', 'count'], true)) {
            // An empty table reads as a broken command. Machine formats
            // still print their empty value, so a pipe gets [] or 0.
            WP_CLI::warning($day === ''
                ? 'The hunt group has no forwarding rules.'
                : sprintf('No forwarding rules apply on %s.', $day));
            return;
        }

        // format_items prints rather than returns; wrapping it in echo
        // would print its null return after the table.
        Utils\format_items($format, $rows, $fields);
    }

    /**
     * One flat row per rule, in hunt order — or, for a day, earliest first
     * with hunt order breaking ties.
     *
     * @param ForwardingRule[]   $rules
     * @param ForwardingTarget[] $targets
     * @param string             $day     A day code, or '' for every rule.
     *
     * @return array<int, array<string, int|string>>
     */
    public function rows(array $rules, array $targets, string $day = ''): array
    {
        $targetsById = [];
        foreach ($targets as $target) {
            $targetsById[$target->getId()] = $target;
        }

        usort($rules, static fn(ForwardingRule $a, ForwardingRule $b): int
            => $a->getPriority() <=> $b->getPriority());

        if ($day !== '') {
            $rules = array_values(array_filter($rules, fn(ForwardingRule $rule): bool
                => in_array($day, $this->ruleDays($rule), true)));
            // usort is stable, so equal start times keep hunt order.
            usort($rules, fn(ForwardingRule $a, ForwardingRule $b): int
                => $this->from($a, '00:00') <=> $this->from($b, '00:00'));
        }

        $rows = [];
        foreach ($rules as $rule) {
            $target = $targetsById[$rule->getTargetId()] ?? null;
            $value = $this->windowValue($rule);

            $rows[] = [
                'id'        => $rule->getId(),
                'priority'  => $rule->getPriority(),
                'label'     => $rule->getLabel(),
                'match'     => $rule->getMatchType(),
                'days'      => $rule->isCatchall() ? 'all' : implode(',', $this->ruleDays($rule)),
                'from'      => $this->from($rule, ''),
                'to'        => (string) ($value['to'] ?? ''),
                'target'    => $target !== null && $target->getLabel() !== '' ? $target->getLabel() : $rule->getTargetId(),
                'target_id' => $rule->getTargetId(),
                'kind'      => $target !== null ? $target->getKind() : '',
                'address'   => $target !== null ? $target->getAddress() : '',
                // Only a number target's address is a phone number; a
                // voicemail's is a mailbox id and a queue's is the queue's.
                'telephone' => $target !== null && $target->getKind() === 'number' ? $target->getAddress() : '',
                'enabled'   => $rule->isEnabled() ? 'yes' : 'no',
            ];
        }

        return $rows;
    }

    /**
     * The days a rule applies on, Monday first: all seven for a catchall,
     * the ticked ones for a time window, none for anything else.
     *
     * @return string[]
     */
    private function ruleDays(ForwardingRule $rule): array
    {
        if ($rule->isCatchall()) {
            return self::DAYS;
        }
        if ($rule->getMatchType() !== 'time_window') {
            return [];
        }

        $days = $this->windowValue($rule)['days'] ?? null;
        if (!is_array($days)) {
            return [];
        }

        return array_values(array_intersect(self::DAYS, array_map(
            static fn(mixed $d): string => strtolower(is_scalar($d) ? (string) $d : ''),
            $days
        )));
    }

    private function from(ForwardingRule $rule, string $default): string
    {
        $from = (string) ($this->windowValue($rule)['from'] ?? '');
        return $from !== '' ? $from : $default;
    }

    /** @return array<mixed> */
    private function windowValue(ForwardingRule $rule): array
    {
        $match = $rule->getMatch();
        return is_array($match['value'] ?? null) ? $match['value'] : [];
    }
}
