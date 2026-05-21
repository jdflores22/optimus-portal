<?php

require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$conn = DriverManager::getConnection([
    'dbname' => 'optimus',
    'user' => 'root',
    'password' => '',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
]);

$count = $conn->executeQuery('SELECT COUNT(*) as cnt FROM noa')->fetchAssociative();
echo 'NOA count: ' . $count['cnt'] . PHP_EOL;
