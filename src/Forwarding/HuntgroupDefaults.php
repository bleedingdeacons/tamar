<?php

declare(strict_types=1);

namespace Tamar\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Interfaces\ForwardingException;

/**
 * The settings every hunt group Tamar writes from a rota is given: how
 * long each row rings, the announcement, the voicemail box and the
 * hunting type. Set under Tamar → Settings, and applied by
 * {@see HuntgroupCallForwardingService::publishHuntgroup()}, the path
 * Trusted's Publish and Sync to Tamar both take.
 *
 * The panel names an announcement or voicemail box by an opaque option
 * value (a UUID, a box number) that is only known once a hunt-group
 * page has been read. So the voicemail box is held as that value once
 * one has been chosen, and until then as a name — "Voice to Email" —
 * looked up on the page being written. The announcement's default is
 * the panel's own "none".
 *
 * A value the page does not offer is refused rather than written: the
 * panel would ignore it, and a voicemail row with no box sends callers
 * nowhere.
 */
final class HuntgroupDefaults
{
    public const DEFAULT_RING_TIMEOUT = 90;
    public const MIN_RING_TIMEOUT = 5;
    public const MAX_RING_TIMEOUT = 180;

    /** The panel's "no announcement" / "no voicemail box" value. */
    public const NONE = 'none';

    /** The voicemail box used until one is chosen, by its name in the panel. */
    public const DEFAULT_VOICEMAIL_NAME = 'Voice to Email';

    public const DEFAULT_HUNTING = 'inorder';

    /** The panel's hunting types, value => label as the panel shows it. */
    public const HUNTING_TYPES = [
        'inorder' => 'Hunt in-order',
        'random' => 'Random hunt',
        'last' => 'Last found',
        'mostidle' => 'Most idle',
        'rotary' => 'Rotary',
        'simultaneous' => 'Simultaneous Ring All',
    ];

    /**
     * @param string $voicemail A panel box value, or '' for the box named
     *                          {@see DEFAULT_VOICEMAIL_NAME}.
     */
    public function __construct(
        public readonly int $ringTimeout = self::DEFAULT_RING_TIMEOUT,
        public readonly string $greeting = self::NONE,
        public readonly string $voicemail = '',
        public readonly string $hunting = self::DEFAULT_HUNTING,
    ) {
    }

    /**
     * Apply these to a parsed hunt-group page's meta: greeting,
     * voicemail and hunting. The ring timeout goes on each row, so the
     * caller sets it there.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     * @throws ForwardingException When the page offers no such announcement or voicemail box.
     */
    public function applyTo(array $meta): array
    {
        $meta['greeting'] = $this->resolve(
            $this->greeting,
            '',
            self::options($meta, 'greetings_available'),
            'announcement'
        );
        $meta['voicemail'] = $this->resolve(
            $this->voicemail,
            self::DEFAULT_VOICEMAIL_NAME,
            self::options($meta, 'voicemails_available'),
            'voicemail box'
        );
        $meta['hunting'] = $this->hunting;

        return $meta;
    }

    /**
     * The option value to write: $value itself when the page offers it,
     * or — for an empty $value — the option named $defaultName.
     *
     * @param list<array{id:string,label:string}> $options
     * @throws ForwardingException
     */
    private function resolve(string $value, string $defaultName, array $options, string $what): string
    {
        if ($value === self::NONE || ($value === '' && $defaultName === '')) {
            return self::NONE;
        }

        if ($value === '') {
            foreach ($options as $option) {
                if (strcasecmp(trim($option['label']), $defaultName) === 0) {
                    return $option['id'];
                }
            }
            throw new ForwardingException(sprintf(
                'The control panel has no %s called "%s". Choose one under Tamar → Settings.',
                $what,
                $defaultName
            ));
        }

        foreach ($options as $option) {
            if ($option['id'] === $value) {
                return $value;
            }
        }
        throw new ForwardingException(sprintf(
            'The %s chosen under Tamar → Settings is no longer in the control panel. Choose another.',
            $what
        ));
    }

    /**
     * @param array<string,mixed> $meta
     * @return list<array{id:string,label:string}>
     */
    private static function options(array $meta, string $key): array
    {
        $out = [];
        foreach ((is_array($meta[$key] ?? null) ? $meta[$key] : []) as $option) {
            if (is_array($option) && isset($option['id'])) {
                $out[] = ['id' => (string) $option['id'], 'label' => (string) ($option['label'] ?? '')];
            }
        }
        return $out;
    }
}
