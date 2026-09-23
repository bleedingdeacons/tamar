<?php

/**
 * Plugin Name: Tamar
 * Description: Call forwarding for Tamar Telecommunications' control panel. Implements the Beacon library's CallForwardingService contract by reading and writing the hunt-group editor at /phonedivert/huntgroup, and provides the forwarding roles and the optional forwarding REST API.
 * Version: 3.1.0
 * Requires at least: 6.1
 * Requires PHP: 8.4
 * GitHub Plugin URI: https://github.com/bleedingdeacons/tamar
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/tamar
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 * Text Domain: tamar
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Set `define('TAMAR_KILL', true);` in wp-config.php to stand Tamar down
// without deactivating it. Nothing is bound, so Trusted sees no driver.
if (defined('TAMAR_KILL') && TAMAR_KILL === true) {
    if (is_admin()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>'
                . '<strong>Tamar:</strong> Plugin is disabled via the '
                . '<code>TAMAR_KILL</code> kill switch in <code>wp-config.php</code>.'
                . '</p></div>';
        });
    }
    return;
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

// Load Composer autoloader if present. It also supplies the Beacon library.
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

// The forwarding roles belonged to the Beacon plugin until Beacon became a
// library. Tamar is now the plugin that owns them.
register_activation_hook(__FILE__, [\Beacon\Capabilities\CapabilityBootstrap::class, 'register']);
register_deactivation_hook(__FILE__, [\Beacon\Capabilities\CapabilityBootstrap::class, 'remove']);

// The old Beacon plugin strips these roles when it is deactivated or deleted,
// and an in-place upgrade never fires the activation hook, so put them back
// whenever they are missing rather than only on activation.
add_action('init', function () {
    if (get_role(\Beacon\Capabilities\CapabilityBootstrap::ROLE_OPERATOR) === null) {
        \Beacon\Capabilities\CapabilityBootstrap::register();
    }
});

add_action('plugins_loaded', function () {
    try {
        if (!class_exists('Tamar\\Plugin')) {
            throw new \Exception('Tamar\\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Tamar\Plugin::init();

        /**
         * Fires after Tamar has bound its driver and published it to
         * Beacon's ForwardingRegistry.
         *
         * @param \Psr\Container\ContainerInterface $container Tamar's dependency container
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
