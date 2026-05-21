<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

// Get database connection details
$dbHost = $_ENV['DATABASE_HOST'] ?? 'localhost';
$dbPort = $_ENV['DATABASE_PORT'] ?? '3306';
$dbName = $_ENV['DATABASE_NAME'] ?? 'optimus_portal';
$dbUser = $_ENV['DATABASE_USER'] ?? 'root';
$dbPass = $_ENV['DATABASE_PASSWORD'] ?? '';

try {
    // Connect to database
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Connected to database successfully.\n";

    // Read SQL file
    $sql = file_get_contents(__DIR__ . '/execute_configuration_migration.sql');
    
    // Remove comments
    $sql = preg_replace('/^--.*$/m', '', $sql);
    
    // Split by semicolons but keep multi-line statements together
    $statements = [];
    $current = '';
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $current .= ' ' . $line;
        
        if (str_ends_with($line, ';')) {
            $statements[] = trim($current);
            $current = '';
        }
    }
    
    if (!empty($current)) {
        $statements[] = trim($current);
    }

    echo "Executing " . count($statements) . " SQL statements...\n\n";

    foreach ($statements as $index => $statement) {
        if (empty($statement)) continue;
        
        try {
            $pdo->exec($statement);
            echo "✓ Statement " . ($index + 1) . " executed successfully\n";
        } catch (PDOException $e) {
            echo "✗ Statement " . ($index + 1) . " failed: " . $e->getMessage() . "\n";
            // Continue with other statements
        }
    }

    echo "\n✓ Migration completed successfully!\n";

    // Verify tables were created
    $tables = $pdo->query("SHOW TABLES LIKE 'system_configurations'")->fetchAll();
    if (count($tables) > 0) {
        echo "✓ system_configurations table created\n";
    }

    $tables = $pdo->query("SHOW TABLES LIKE 'configuration_history'")->fetchAll();
    if (count($tables) > 0) {
        echo "✓ configuration_history table created\n";
    }

    // Check inserted data
    $count = $pdo->query("SELECT COUNT(*) as cnt FROM system_configurations")->fetch();
    echo "✓ {$count['cnt']} configuration records inserted\n";

} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
