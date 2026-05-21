<?php

/**
 * Test script to validate Container-Based eDO Workflow migrations
 * 
 * This script checks if all required tables and columns exist after migration.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

echo "=== Container-Based eDO Migration Test ===\n";
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

$tests = [
    'Tables' => [
        'noa' => 'NOA table',
        'regeneration_requests' => 'RegenerationRequest table',
        'edo_billings' => 'EDOBilling table',
        'edo_payment_receipts' => 'EDOPaymentReceipt table',
        'edo_audit_logs' => 'EDOAuditLog table',
    ],
    'Columns' => [
        'containers.noa_id' => 'Container.noa_id column',
        'containers.manifest_id' => 'Container.manifest_id column',
        'electronic_delivery_orders.container_id' => 'ElectronicDeliveryOrder.container_id column',
        'electronic_delivery_orders.expires_at' => 'ElectronicDeliveryOrder.expires_at column',
        'electronic_delivery_orders.expired_days' => 'ElectronicDeliveryOrder.expired_days column',
        'electronic_delivery_orders.version' => 'ElectronicDeliveryOrder.version column',
        'electronic_delivery_orders.previous_version_id' => 'ElectronicDeliveryOrder.previous_version_id column',
        'manifests.noa_id' => 'Manifest.noa_id column',
    ],
    'Indexes' => [
        'electronic_delivery_orders.idx_edo_number' => 'eDO number index',
        'electronic_delivery_orders.idx_edo_status' => 'eDO status index',
        'electronic_delivery_orders.idx_edo_status_expires' => 'eDO status+expires composite index',
        'edo_audit_logs.idx_audit_container' => 'Audit log container index',
        'edo_audit_logs.idx_audit_edo' => 'Audit log eDO index',
        'regeneration_requests.idx_regen_status' => 'Regeneration request status index',
    ],
    'Foreign Keys' => [
        'noa.FK_noa_consignee' => 'NOA -> Consignee FK',
        'noa.FK_noa_created_by' => 'NOA -> User FK',
        'containers.FK_containers_noa' => 'Container -> NOA FK',
        'containers.FK_containers_manifest' => 'Container -> Manifest FK',
        'electronic_delivery_orders.FK_edo_container' => 'eDO -> Container FK',
        'electronic_delivery_orders.FK_edo_previous_version' => 'eDO -> Previous eDO FK',
        'regeneration_requests.FK_regen_req_edo' => 'RegenerationRequest -> eDO FK',
        'edo_billings.FK_edo_billing_regen_req' => 'EDOBilling -> RegenerationRequest FK',
        'edo_payment_receipts.FK_edo_payment_billing' => 'EDOPaymentReceipt -> EDOBilling FK',
    ],
];

$passed = 0;
$failed = 0;

// Test Tables
echo "Testing Tables:\n";
echo "---------------\n";
foreach ($tests['Tables'] as $table => $description) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "  ✓ $description exists\n";
            $passed++;
        } else {
            echo "  ✗ $description NOT FOUND\n";
            $failed++;
        }
    } catch (PDOException $e) {
        echo "  ✗ $description ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}
echo "\n";

// Test Columns
echo "Testing Columns:\n";
echo "----------------\n";
foreach ($tests['Columns'] as $column => $description) {
    list($table, $columnName) = explode('.', $column);
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE '$columnName'");
        if ($stmt->rowCount() > 0) {
            echo "  ✓ $description exists\n";
            $passed++;
        } else {
            echo "  ✗ $description NOT FOUND\n";
            $failed++;
        }
    } catch (PDOException $e) {
        echo "  ✗ $description ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}
echo "\n";

// Test Indexes
echo "Testing Indexes:\n";
echo "----------------\n";
foreach ($tests['Indexes'] as $index => $description) {
    list($table, $indexName) = explode('.', $index);
    try {
        $stmt = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$indexName'");
        if ($stmt->rowCount() > 0) {
            echo "  ✓ $description exists\n";
            $passed++;
        } else {
            echo "  ✗ $description NOT FOUND\n";
            $failed++;
        }
    } catch (PDOException $e) {
        echo "  ✗ $description ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}
echo "\n";

// Test Foreign Keys
echo "Testing Foreign Keys:\n";
echo "---------------------\n";
foreach ($tests['Foreign Keys'] as $fk => $description) {
    list($table, $fkName) = explode('.', $fk);
    try {
        $stmt = $pdo->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = '$dbName' 
            AND TABLE_NAME = '$table' 
            AND CONSTRAINT_NAME = '$fkName'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");
        if ($stmt->rowCount() > 0) {
            echo "  ✓ $description exists\n";
            $passed++;
        } else {
            echo "  ✗ $description NOT FOUND\n";
            $failed++;
        }
    } catch (PDOException $e) {
        echo "  ✗ $description ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}
echo "\n";

// Summary
echo "==========================================\n";
echo "Test Summary:\n";
echo "  Passed: $passed\n";
echo "  Failed: $failed\n";
echo "  Total:  " . ($passed + $failed) . "\n";
echo "==========================================\n";

if ($failed === 0) {
    echo "\n✓ All migration tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some migration tests failed. Please review the output above.\n";
    exit(1);
}
