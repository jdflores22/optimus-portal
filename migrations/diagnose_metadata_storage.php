<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Table;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();
$connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');
$schemaManager = $connection->createSchemaManager();

$current = $schemaManager->introspectTable('doctrine_migration_versions');
$expected = new Table('doctrine_migration_versions_expected');
$expected->addColumn('version', 'string', ['notnull' => true, 'length' => 191]);
$expected->addColumn('executed_at', 'datetime', ['notnull' => false]);
$expected->addColumn('execution_time', 'integer', ['notnull' => false]);
$expected->setPrimaryKey(['version']);

$config = class_exists(ComparatorConfig::class)
    ? (new ComparatorConfig())->withReportModifiedIndexes(false)
    : null;
$comparator = $config ? $schemaManager->createComparator($config) : $schemaManager->createComparator();
$diff = $comparator->compareTables($current, $expected);

echo $diff->isEmpty() ? "Tables match\n" : "Diff found:\n";
foreach ($current->getColumns() as $column) {
    echo 'CURRENT ' . $column->getName() . ': ' . $column->getType()::class . ' len=' . ($column->getLength() ?? 'null') . "\n";
}
foreach ($expected->getColumns() as $column) {
    echo 'EXPECTED ' . $column->getName() . ': ' . $column->getType()::class . ' len=' . ($column->getLength() ?? 'null') . "\n";
}

if (!$diff->isEmpty()) {
    echo "\nAdded columns: " . count($diff->getAddedColumns()) . "\n";
    echo "Changed columns: " . count($diff->getChangedColumns()) . "\n";
    foreach ($diff->getChangedColumns() as $changed) {
        $old = $changed->getOldColumn();
        $new = $changed->getNewColumn();
        echo 'CHANGED COLUMN: ' . $old->getName() . ' props=' . $changed->countChangedProperties() . "\n";
        echo '  typeChanged=' . ($changed->hasTypeChanged() ? 'yes' : 'no') . "\n";
        echo '  lengthChanged=' . ($changed->hasLengthChanged() ? 'yes' : 'no') . "\n";
        echo '  notNullChanged=' . ($changed->hasNotNullChanged() ? 'yes' : 'no') . "\n";
        echo '  oldDefault=' . var_export($old->getDefault(), true) . "\n";
        echo '  newDefault=' . var_export($new->getDefault(), true) . "\n";
        echo '  platformOptionsChanged=' . ($changed->hasPlatformOptionsChanged() ? 'yes' : 'no') . "\n";
        echo '  commentChanged=' . ($changed->hasCommentChanged() ? 'yes' : 'no') . "\n";
        echo '  oldPlatformOptions=' . json_encode($old->getPlatformOptions()) . "\n";
        echo '  newPlatformOptions=' . json_encode($new->getPlatformOptions()) . "\n";
    }
}
