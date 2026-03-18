<?php

/**
 * Plugin Name: Perimetre Core
 * Description: Shared agency plugin for headless WordPress projects.
 * Version: 1.0.0
 * Author: Perimetre
 * Author URI: https://perimetre.co
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('PERIMETRE_CORE_VERSION', '1.0.0');
define('PERIMETRE_CORE_PATH', plugin_dir_path(__FILE__));
define('PERIMETRE_CORE_URL', plugin_dir_url(__FILE__));

require_once PERIMETRE_CORE_PATH . 'vendor/autoload.php';

use Perimetre\Core\Blocks\Registry as BlockRegistry;
use Perimetre\Core\GraphQL\Registry as GraphQLRegistry;

/**
 * Bootstrap block registration on init.
 * Priority 5 to run before most plugins register their own blocks.
 */
add_action('init', [BlockRegistry::class, 'register'], 5);

/**
 * Bootstrap GraphQL type and field registration.
 */
add_action('graphql_register_types', [GraphQLRegistry::class, 'register']);
