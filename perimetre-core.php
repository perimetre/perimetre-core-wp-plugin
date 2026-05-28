<?php

/**
 * Plugin Name: Perimetre Core
 * Description: Shared agency plugin for headless WordPress projects.
 * Version: 1.9.0
 * Author: Perimetre
 * Author URI: https://perimetre.co
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: perimetre-core
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('PERIMETRE_CORE_VERSION', '1.9.0');
define('PERIMETRE_CORE_FILE', __FILE__);
define('PERIMETRE_CORE_PATH', plugin_dir_path(__FILE__));
define('PERIMETRE_CORE_URL', plugin_dir_url(__FILE__));

require_once PERIMETRE_CORE_PATH . 'vendor/autoload.php';
require_once PERIMETRE_CORE_PATH . 'src/Acf/cta-fields.php';

use Perimetre\Core\Blocks\Registry as BlockRegistry;
use Perimetre\Core\GraphQL\Registry as GraphQLRegistry;
use Perimetre\Core\Plugin;
use Perimetre\Core\Status\Endpoint as StatusEndpoint;
use Perimetre\Core\Status\Settings as StatusSettings;
use Perimetre\Core\Webhook\Dispatcher as WebhookDispatcher;
use Perimetre\Core\Webhook\Settings as WebhookSettings;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Enable auto-updates from GitHub Releases.
 */
$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/perimetre/perimetre-core-wp-plugin/',
    __FILE__,
    'perimetre-core'
);
/** @phpstan-ignore method.notFound */
$updateChecker->getVcsApi()->enableReleaseAssets();

/**
 * Load plugin translations.
 */
add_action('init', [Plugin::class, 'load_textdomain'], 1);

/**
 * Bootstrap block registration on init.
 * Priority 5 to run before most plugins register their own blocks.
 */
add_action('init', [BlockRegistry::class, 'register'], 5);

/**
 * Bootstrap GraphQL type and field registration.
 */
add_action('graphql_register_types', [GraphQLRegistry::class, 'register']);

/**
 * Bootstrap status endpoint settings and rewrite rule.
 */
StatusSettings::register();
StatusEndpoint::register();

/**
 * Bootstrap webhook settings and dispatcher.
 */
WebhookSettings::register();
WebhookDispatcher::register();

/**
 * Schedule cron and flush rewrite rules on activation.
 */
register_activation_hook(__FILE__, [StatusEndpoint::class, 'activate']);

/**
 * Clean up status cron and rewrite rules on deactivation.
 */
register_deactivation_hook(__FILE__, [StatusEndpoint::class, 'deactivate']);
