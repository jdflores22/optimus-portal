<?php

/**
 * Data Migration Script for Container-Based eDO Workflow
 * 
 * This script migrates existing Manifest and Container data to the new
 * container-based eDO workflow structure.
 * 
 * IMPORTANT: Run this script AFTER executing all Doctrine migrations.
 * 
 * Usage:
 *   php migrations/migrate_existing_edo_data.php [--dry-run] [--batch-size=100]
 * 
 * Options:
 *   --dry-run      : Preview changes without committing to database
 *   --batch-size=N : Process N records at a time (default: 100)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv);
$batchSize = 100;
foreach ($argv as $arg) {
    if (strpos($arg, '--batch-size=') === 0) {
        $batchSize = (int) substr($arg, 13);
    }
}

echo "=== Container-Based eDO Data Migration ===\n";
echo "Dry Run: " . ($dryRun ? 'YES' : 'NO') . "\n";
echo "Batch Size: $batchSize\n";
echo "==========================================\n\n";

// Database connection
$dbHost = $_ENV['DATABASE_HOST'] ?? 'localhost';
$dbPort = $_ENV['DATABASE_PORT'] ?? '3306';
$dbName = $_ENV['DATABASE_NAME'] ?? 'optimus';
$dbUser = $_ENV['DATABASE_USER'] ?? 'root';
$dbPass = $_ENV['DATABASE_PASSWORD'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✓ Database connection established\n\n";
} catch (PDOException $e) {
    die("✗ Database connection failed: " . $e->getMessage() . "\n");
}

// Step 1: Analyze existing data
echo "Step 1: Analyzing existing data...\n";
echo "-----------------------------------\n";

$stats = [
    'manifests_total' => 0,
    'manifests_with_containers' => 0,
    'containers_total' => 0,
    'containers_without_manifest' => 0,
    'edos_existing' => 0,
    'edos_to_generate' => 0,
];

// Count manifests
$stmt = $pdo->query("SELECT COUNT(*) FROM manifests");
$stats['manifests_total'] = $stmt->fetchColumn();

// Count manifests with containers
$stmt = $pdo->query("SELECT COUNT(DISTINCT manifest_id) FROM containers WHERE manifest_id IS NOT NULL");
$stats['manifests_with_containers'] = $stmt->fetchColumn();

// Count containers
$stmt = $pdo->query("SELECT COUNT(*) FROM containers");
$stats['containers_total'] = $stmt->fetchColumn();

// Count containers without manifest
$stmt = $pdo->query("SELECT COUNT(*) FROM containers WHERE manifest_id IS NULL");
$stats['containers_without_manifest'] = $stmt->fetchColumn();

// Count existing eDOs
$stmt = $pdo->query("SELECT COUNT(*) FROM electronic_delivery_orders");
$stats['edos_existing'] = $stmt->fetchColumn();

// Count containers that need eDOs (have manifest but no eDO)
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT c.id)
    FROM containers c
    WHERE c.manifest_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM electronic_delivery_orders edo
        WHERE edo.container_id = c.id
    )
");
$stats['edos_to_generate'] = $stmt->fetchColumn();

foreach ($stats as $key => $value) {
    echo "  " . str_pad($key, 35) . ": $value\n";
}
echo "\n";

if ($stats['edos_to_generate'] === 0) {
    echo "✓ No eDOs need to be generated. Migration complete.\n";
    exit(0);
}

// Step 2: Generate eDOs for existing containers
echo "Step 2: Generating eDOs for containers...\n";
echo "------------------------------------------\n";

if ($dryRun) {
    echo "DRY RUN MODE - No changes will be made\n\n";
}

$pdo->beginTransaction();

try {
    // Get containers that need eDOs
    $stmt = $pdo->prepare("
        SELECT c.id, c.container_number, c.manifest_id, m.manifest_number, m.created_at
        FROM containers c
        INNER JOIN manifests m ON c.manifest_id = m.id
        WHERE NOT EXISTS (
            SELECT 1 FROM electronic_delivery_orders edo
            WHERE edo.container_id = c.id
        )
        ORDER BY c.id
        LIMIT :batch_size
    ");
    
    $stmt->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $generatedCount = 0;
    $auditLogCount = 0;
    
    // Get system user for audit logs (use first admin user)
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'SYSTEM_ADMIN' LIMIT 1");
    $systemUserId = $stmt->fetchColumn();
    
    if (!$systemUserId) {
        throw new Exception("No system admin user found for audit logs");
    }
    
    foreach ($containers as $container) {
        // Generate eDO number
        $date = date('Ymd');
        $containerNum = preg_replace('/[^A-Z0-9]/', '', strtoupper($container['container_number']));
        $edoNumber = "EDO-{$date}-{$containerNum}-0001";
        
        // Check if eDO number already exists (collision handling)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM electronic_delivery_orders WHERE edo_number = ?");
        $stmt->execute([$edoNumber]);
        $exists = $stmt->fetchColumn();
        
        if ($exists) {
            // Append random suffix to make it unique
            $edoNumber .= '-' . substr(md5(uniqid()), 0, 4);
        }
        
        // Calculate expiration date (14 days from manifest creation)
        $manifestCreatedAt = new DateTime($container['created_at']);
        $expiresAt = clone $manifestCreatedAt;
        $expiresAt->modify('+14 days');
        
        // Insert eDO
        $stmt = $pdo->prepare("
            INSERT INTO electronic_delivery_orders 
            (edo_number, container_id, manifest_id, status, generated_at, expires_at, version)
            VALUES (?, ?, ?, 'active', ?, ?, 1)
        ");
        
        $stmt->execute([
            $edoNumber,
            $container['id'],
            $container['manifest_id'],
            $manifestCreatedAt->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s')
        ]);
        
        $edoId = $pdo->lastInsertId();
        $generatedCount++;
        
        // Create audit log entry
        $stmt = $pdo->prepare("
            INSERT INTO edo_audit_logs 
            (edo_id, container_id, event_type, user_id, details, timestamp)
            VALUES (?, ?, 'edo_created', ?, ?, ?)
        ");
        
        $details = json_encode([
            'edo_number' => $edoNumber,
            'manifest_number' => $container['manifest_number'],
            'migration' => true,
            'migration_date' => date('Y-m-d H:i:s')
        ]);
        
        $stmt->execute([
            $edoId,
            $container['id'],
            $systemUserId,
            $details,
            $manifestCreatedAt->format('Y-m-d H:i:s')
        ]);
        
        $auditLogCount++;
        
        echo "  ✓ Generated eDO: $edoNumber for container: {$container['container_number']}\n";
    }
    
    if ($dryRun) {
        $pdo->rollBack();
        echo "\nDRY RUN - Transaction rolled back\n";
    } else {
        $pdo->commit();
        echo "\n✓ Transaction committed\n";
    }
    
    echo "\nSummary:\n";
    echo "  eDOs generated: $generatedCount\n";
    echo "  Audit logs created: $auditLogCount\n";
    
    if ($generatedCount < $stats['edos_to_generate']) {
        echo "\n⚠ More containers need eDOs. Run this script again to process the next batch.\n";
    } else {
        echo "\n✓ All containers have been processed!\n";
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "Transaction rolled back.\n";
    exit(1);
}

echo "\n=== Migration Complete ===\n";
