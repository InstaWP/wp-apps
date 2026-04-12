<?php
/**
 * Plugin Name: WP Apps Runtime
 * Description: Manages sandboxed WordPress app extensions — manifest parsing, OAuth tokens, permission enforcement, hook dispatch, and UI bridge.
 * Version: 0.1.0
 * Author: InstaWP
 * Author URI: https://instawp.com
 * License: GPL-2.0-or-later
 * Requires PHP: 8.1
 * Requires at least: 6.5
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WP_APPS_VERSION', '0.1.0');
define('WP_APPS_PATH', __DIR__);
define('WP_APPS_FILE', __FILE__);

require_once __DIR__ . '/vendor/autoload.php';

WPApps\Runtime\Core\Plugin::instance();
