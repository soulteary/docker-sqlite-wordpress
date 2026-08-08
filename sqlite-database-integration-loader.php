<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$sqlite_ui = __DIR__ . '/sqlite-database-integration/load.php';
if ( is_readable( $sqlite_ui ) ) {
    require_once $sqlite_ui;
}
