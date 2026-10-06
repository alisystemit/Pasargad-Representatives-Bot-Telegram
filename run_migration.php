<?php
require __DIR__ . '/bootstrap.php';

$db = Pasargad\Support\Db::instance();
$migrator = new Pasargad\Support\Migrator($db);
$ran = $migrator->migrate();
echo 'Applied: ' . implode(', ', $ran) . PHP_EOL;