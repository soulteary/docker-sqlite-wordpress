<?php
/** Verify the packaged 3.1 storage manager preserves existing database data. */
require '/usr/src/wordpress/wp-content/mu-plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-storage.php';

function storage_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/sqlite-storage-smoke-' . bin2hex(random_bytes(8));
mkdir($root);
$root = realpath($root);
try {
    foreach (['.ht.sqlite', '.ht.sqlite.php'] as $index => $filename) {
        $directory = $root . '/legacy-' . $index;
        mkdir($directory);
        $legacy = $directory . '/' . $filename;
        $db = new PDO('sqlite:' . $legacy);
        $db->exec('CREATE TABLE preserved (value TEXT)');
        $db->exec("INSERT INTO preserved VALUES ('existing site data')");
        $db = null;
        $path = WP_SQLite_Storage::with_secret_path($directory)->initialize();
        storage_assert($path !== $legacy && is_file($path), 'Legacy database was not migrated');
        storage_assert(is_file($directory . '/db-path.php'), 'Database path record missing');
        $db = new PDO('sqlite:' . $path);
        storage_assert($db->query('SELECT value FROM preserved')->fetchColumn() === 'existing site data', 'Migration lost existing data');
        $db = null;
        storage_assert(WP_SQLite_Storage::with_secret_path($directory)->initialize() === $path, 'Restart changed the recorded database path');
    }
    $explicit = $root . '/explicit.sqlite';
    storage_assert(WP_SQLite_Storage::with_explicit_path($explicit)->initialize() === $explicit, 'Explicit database path changed');
    echo "SQLite storage migration and restart tests passed\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
