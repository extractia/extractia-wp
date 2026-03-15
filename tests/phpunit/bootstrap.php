<?php
/**
 * PHPUnit bootstrap — stubs the WordPress global functions so plugin
 * classes can be loaded and tested without a running WP installation.
 *
 * Usage: phpunit --configuration phpunit.xml
 */

define( 'ABSPATH',             '/tmp/wp/' );
define( 'EXTRACTIA_TESTING',   true );
define( 'EXTRACTIA_VERSION',   '1.0.0' );
define( 'EXTRACTIA_PLUGIN_DIR', __DIR__ . '/../../' );
define( 'EXTRACTIA_PLUGIN_URL', 'https://example.com/wp-content/plugins/extractia-wp/' );
define( 'EXTRACTIA_API_BASE',  'https://api.extractia.info/api/public' );
define( 'EXTRACTIA_OPTION_KEY','extractia_api_key' );

require_once __DIR__ . '/stubs/wp-stubs.php';
require_once EXTRACTIA_PLUGIN_DIR . 'includes/class-api-client.php';
require_once EXTRACTIA_PLUGIN_DIR . 'includes/class-rest-proxy.php';
require_once EXTRACTIA_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once EXTRACTIA_PLUGIN_DIR . 'includes/class-agent.php';
require_once EXTRACTIA_PLUGIN_DIR . 'includes/class-widget-registry.php';
require_once EXTRACTIA_PLUGIN_DIR . 'includes/class-hooks.php';
