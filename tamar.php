<?php

declare(strict_types=1);

/**
 * Plugin Name: Tamar
 * Description: Beacon driver for Tamar Telecommunications' control panel. Implements Beacon's CallForwardingService contract by reading and writing the hunt-group editor at /phonedivert/huntgroup. Requires the Beacon plugin to be installed and active.
 * Version: 1.1.0
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * Requires Plugins: beacon
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/tamar
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/tamar
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 * Text Domain: tamar
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

$tamar_plugin_data = get_plugin_data(__FILE__, false, false);
define('TAMAR_VERSION', $tamar_plugin_data['Version']);
define('TAMAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAMAR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TAMAR_PLUGIN_FILE', __FILE__);

// Single wp_options key that holds the whole settings row. Must match
// the key deleted in uninstall.php ('tamar_settings').
define('TAMAR_OPTION_KEY', 'tamar_settings');

// Load Composer autoloader if present.
$tamar_autoloader = TAMAR_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($tamar_autoloader)) {
    require_once $tamar_autoloader;
}

// Fallback PSR-4 autoloader for the Tamar namespace. Lets the plugin
// run on a fresh deployment before `composer install` has been executed.
spl_autoload_register(function ($class) {
    $prefix = 'Tamar\\';
    $base_dir = TAMAR_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Tamar binds its driver on `beacon/loaded`, which fires during
// `plugins_loaded` from Beacon. We hook there to register against
// whichever container Beacon ended up using (its own minimal PSR-11
// container, or a shared one provided via the `beacon/container` filter).
add_action('beacon/loaded', function ($container) {
    try {
        if (!class_exists('Tamar\\Plugin')) {
            throw new \Exception('Tamar\\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Tamar\Plugin::init($container);

        /**
         * Fires after Tamar has bound its concrete driver against
         * Beacon's CallForwardingService contract.
         *
         * @param \Psr\Container\ContainerInterface $container The shared dependency container
         */
        do_action('tamar/loaded', \Tamar\Plugin::getContainer());

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('tamar')->error('Tamar Plugin Initialisation Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Tamar Plugin Initialisation Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Tamar Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('tamar')->critical('Tamar Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Tamar Plugin Fatal Error: ' . $e->getMessage());
    }
});

// Surface a notice if Beacon never loaded (e.g. it isn't installed or active).
// Beacon fires `beacon/loaded` from `plugins_loaded`, so by `admin_init` we
// know whether it ran. If it didn't, Tamar can't have bound anything.
add_action('admin_init', function () {
    if (did_action('beacon/loaded')) {
        return;
    }
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error is-dismissible"><p>'
            . '<strong>Tamar Plugin Error:</strong> '
            . esc_html__('Tamar requires the Beacon plugin to be installed and activated.', 'tamar')
            . '</p></div>';
    });
});
