<?php
/**
 * Plugin Name: SQLite Database Integration Loader
 * Plugin URI: https://github.com/soulteary/docker-sqlite-wordpress
 * Description: Loads the bundled SQLite Database Integration admin interface from its MU Plugin subdirectory.
 * Version: 1.0.0
 * Author: soulteary
 * Author URI: https://soulteary.com
 * License: Apache-2.0
 *
 * @package sqlite-database-integration-loader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
$sqlite_ui = __DIR__ . '/sqlite-database-integration/load.php';
if ( is_readable( $sqlite_ui ) ) {
    require_once $sqlite_ui;
}
