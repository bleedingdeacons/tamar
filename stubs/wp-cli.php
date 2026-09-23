<?php

/**
 * The WP-CLI symbols this plugin uses, for static analysis only.
 *
 * <b>Why this is not php-stubs/wp-cli-stubs.</b> Every published version of
 * that package requires `php-stubs/wordpress-stubs ^4.7 || ^5.0 || ^6.0`,
 * and this plugin is on v7, so Composer refuses the combination. Fellowship
 * hit the same wall and solved it the same way; this file is a copy of its
 * `stubs/wp-cli.php` plus `WP_CLI::error()`.
 *
 * Listed in `phpstan.neon.dist` under `scanFiles` and loaded by the test
 * bootstrap, never at runtime: WP-CLI defines the real ones, and
 * `Plugin::init()` only touches them behind `defined('WP_CLI')`. Excluded
 * from the production zip by `build.php`. There is deliberately no ABSPATH
 * guard — nothing in the plugin includes this file.
 *
 * Keep it minimal. It exists to stop PHPStan reporting a real command as an
 * unknown class, not to model WP-CLI — anything here the plugin does not
 * call is a description nobody checks.
 */

namespace {
    class WP_CLI
    {
        /**
         * @param string               $name     The command, e.g. "tamar rules".
         * @param callable|object      $callable The command's implementation.
         * @param array<string, mixed> $args
         */
        public static function add_command($name, $callable, $args = []): bool
        {
            return true;
        }

        /** @param string $message */
        public static function warning($message): void
        {
        }

        /**
         * Prints the message and exits, so nothing after it runs.
         *
         * @param string $message
         */
        public static function error($message): never
        {
            throw new \RuntimeException($message);
        }
    }

    class WP_CLI_Command
    {
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<string, mixed> $assoc_args
     * @param string               $flag
     * @param mixed                $default
     *
     * @return mixed
     */
    function get_flag_value($assoc_args, $flag, $default = null)
    {
        return $assoc_args[$flag] ?? $default;
    }

    /**
     * @param string                           $format
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string>|string        $fields
     */
    function format_items($format, $items, $fields): void
    {
    }
}
