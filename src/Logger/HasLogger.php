<?php

declare(strict_types=1);

namespace Tamar\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safe logging trait — silently no-ops if the shared logger mu-plugin
 * (deployed by Sentinel) is not available. Local copy so it resolves
 * via this plugin's own autoloader.
 */
trait HasLogger
{
    private static ?\Sentinel_Log_Channel $loggerChannel = null;

    protected static function logChannel(): string
    {
        $parts = explode('\\', static::class);
        return sanitize_key(end($parts));
    }

    public static function log(): ?\Sentinel_Log_Channel
    {
        if (self::$loggerChannel === null && function_exists('wp_log')) {
            self::$loggerChannel = wp_log(static::logChannel());
        }
        return self::$loggerChannel;
    }

    public static function logError(string $message, array $context = []): void
    {
        static::log()?->error($message, $context);
    }

    public static function logWarning(string $message, array $context = []): void
    {
        static::log()?->warning($message, $context);
    }

    public static function logInfo(string $message, array $context = []): void
    {
        static::log()?->info($message, $context);
    }

    public static function logDebug(string $message, array $context = []): void
    {
        static::log()?->debug($message, $context);
    }
}
