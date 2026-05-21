<?php
/**
 * Execute Terminal Allocation Migration
 * Adds shipping_line_id to shipping_line_terminal_allocations table
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

// Parse DATABASE_URL from .env file
$envFile = __DIR__ . '/../.env';
$databaseUrl = null;

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), 'DATABASE_URL=') === 0) {
            $databaseUrl = trim(substr($line, strlen('DATABASE_URL=')));
            $databaseUrl = trim($databaseUrl, '"');
            break;
        }
    }
}

if (!$databaseUrl) {
    echo "❌ Error: Could not find DATABASE_URL in .env file\n";
    exit(1);
}

// Parse the DATABASE_URL
// Format: mysql://user:password@host:port/database?params
preg_match('/mysql:\/\/([^:]+):([^@]*)@([^:]+):(\d+)\/([^?]+)/', $databaseUrl, $matches);

if (count($matches) < 6) {
    echo "❌ Error: Could not parse DATABASE_URL\n";
    exit(1);
}

$connectionParams = [
    'dbname' => $matches[5],
    'user' => $matches[1],
    'password' => $matches[2],
    'host' => $matches[3],
    'port' => $matches[4],
    'driver' => 'pdo_mysql',
    'charset' => 'utf8mb4',
];

try {
    $conn = DriverManager::getConnection($connectionParams);
    
    echo "=== Terminal Allocation Migration ===\n\n";
    echo "Database: {$connectionParams['dbname']}\n";
    echo "Host: {$connectionParams['host']}\n\n";
    
    // Step 1: Check if column already exists
    echo "Step 1: Checking if shipping_line_id column exists...\n";
    $sql = "SHOW COLUMNS FROM shipping_line_terminal_allocations LIKE 'shipping_line_id'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ Column shipping_line_id already exists. Skipping column creation.\n\n";
    } else {
        echo "Adding shipping_line_id column...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations 
            ADD COLUMN shipping_line_id INT NULL AFTER staff_user_id
        ");
        echo "✓ Column added successfully.\n\n";
    }
    
    // Step 2: Check if foreign key exists
    echo "Step 2: Checking foreign key constraint...\n";
    $sql = "SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = '{$connectionParams['dbname']}'
            AND TABLE_NAME = 'shipping_line_terminal_allocations' 
            AND CONSTRAINT_NAME = 'fk_terminal_allocation_shipping_line'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ Foreign key already exists. Skipping.\n\n";
    } else {
        echo "Adding foreign key constraint...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations
            ADD CONSTRAINT fk_terminal_allocation_shipping_line
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id)
            ON DELETE CASCADE
        ");
        echo "✓ Foreign key added successfully.\n\n";
    }
    
    // Step 3: Check if index exists
    echo "Step 3: Checking index...\n";
    $sql = "SHOW INDEX FROM shipping_line_terminal_allocations 
            WHERE Key_name = 'idx_terminal_allocation_shipping_line'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ Index already exists. Skipping.\n\n";
    } else {
        echo "Creating index...\n";
        $conn->executeStatement("
            CREATE INDEX idx_terminal_allocation_shipping_line 
            ON shipping_line_terminal_allocations(shipping_line_id)
        ");
        echo "✓ Index created successfully.\n\n";
    }
    
    // Step 4: Check for records without shipping_line_id
    echo "Step 4: Checking for records without shipping_line_id...\n";
    $sql = "SELECT COUNT(*) as count 
            FROM shipping_line_terminal_allocations 
            WHERE shipping_line_id IS NULL";
    $result = $conn->fetchAssociative($sql);
    $nullCount = $result['count'];
    
    if ($nullCount > 0) {
        echo "Found {$nullCount} records without shipping_line_id.\n";
        echo "Attempting to populate shipping_line_id from staff user hierarchy...\n";
        
        // Try to find shipping line through staff user's admin
        $sql = "
            UPDATE shipping_line_terminal_allocations ta
            INNER JOIN staff_users su ON ta.staff_user_id = su.id
            INNER JOIN users u ON su.id = u.id
            LEFT JOIN shipping_lines sl ON u.shipping_line_id = sl.id
            SET ta.shipping_line_id = sl.id
            WHERE ta.shipping_line_id IS NULL AND sl.id IS NOT NULL
        ";
        
        $updated = $conn->executeStatement($sql);
        echo "✓ Updated {$updated} records.\n";
        
        // Check again
        $result = $conn->fetchAssociative("
            SELECT COUNT(*) as count 
            FROM shipping_line_terminal_allocations 
            WHERE shipping_line_id IS NULL
        ");
        $remainingNull = $result['count'];
        
        if ($remainingNull > 0) {
            echo "\n⚠ WARNING: {$remainingNull} records still have NULL shipping_line_id.\n";
            echo "These records need manual review:\n";
            
            $records = $conn->fetchAllAssociative("
                SELECT ta.id, ta.staff_user_id, su.email, t.name as terminal_name
                FROM shipping_line_terminal_allocations ta
                LEFT JOIN staff_users su ON ta.staff_user_id = su.id
                LEFT JOIN terminals t ON ta.terminal_id = t.id
                WHERE ta.shipping_line_id IS NULL
            ");
            
            foreach ($records as $record) {
                echo "  - ID: {$record['id']}, Staff: {$record['email']}, Terminal: {$record['terminal_name']}\n";
            }
            echo "\n";
        } else {
            echo "✓ All records now have shipping_line_id set.\n\n";
        }
    } else {
        echo "✓ All records already have shipping_line_id set.\n\n";
    }
    
    // Step 5: Update unique constraint
    echo "Step 5: Updating unique constraint...\n";
    
    // Check if old constraint exists
    $sql = "SHOW INDEX FROM shipping_line_terminal_allocations 
            WHERE Key_name = 'unique_staff_terminal'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "Dropping old unique constraint...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations
            DROP INDEX unique_staff_terminal
        ");
        echo "✓ Old constraint dropped.\n";
    }
    
    // Check if new constraint exists
    $sql = "SHOW INDEX FROM shipping_line_terminal_allocations 
            WHERE Key_name = 'unique_shipping_line_staff_terminal'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ New unique constraint already exists.\n\n";
    } else {
        echo "Adding new unique constraint...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations
            ADD UNIQUE KEY unique_shipping_line_staff_terminal (shipping_line_id, staff_user_id, terminal_id)
        ");
        echo "✓ New constraint added successfully.\n\n";
    }
    
    // Step 6: Verification
    echo "Step 6: Verification...\n";
    $sql = "
        SELECT 
            ta.id,
            sl.brand_name as shipping_line,
            su.email as staff_email,
            t.name as terminal_name,
            ta.capacity_20ft,
            ta.capacity_40ft,
            ta.allocated_capacity
        FROM shipping_line_terminal_allocations ta
        LEFT JOIN shipping_lines sl ON ta.shipping_line_id = sl.id
        LEFT JOIN staff_users su ON ta.staff_user_id = su.id
        LEFT JOIN terminals t ON ta.terminal_id = t.id
        ORDER BY sl.brand_name, t.name
        LIMIT 10
    ";
    
    $records = $conn->fetchAllAssociative($sql);
    
    if (count($records) > 0) {
        echo "\nSample records (showing first 10):\n";
        echo str_repeat("-", 120) . "\n";
        printf("%-5s %-25s %-30s %-25s %-10s %-10s %-10s\n", 
            "ID", "Shipping Line", "Staff Email", "Terminal", "20ft", "40ft", "Allocated");
        echo str_repeat("-", 120) . "\n";
        
        foreach ($records as $record) {
            printf("%-5s %-25s %-30s %-25s %-10s %-10s %-10s\n",
                $record['id'],
                substr($record['shipping_line'] ?? 'NULL', 0, 25),
                substr($record['staff_email'] ?? 'NULL', 0, 30),
                substr($record['terminal_name'] ?? 'NULL', 0, 25),
                $record['capacity_20ft'],
                $record['capacity_40ft'],
                $record['allocated_capacity']
            );
        }
        echo str_repeat("-", 120) . "\n";
    }
    
    // Summary
    $totalRecords = $conn->fetchOne("SELECT COUNT(*) FROM shipping_line_terminal_allocations");
    echo "\n=== Migration Summary ===\n";
    echo "Total allocations: {$totalRecords}\n";
    echo "✓ Migration completed successfully!\n\n";
    
    echo "Next steps:\n";
    echo "1. Verify the data looks correct in the sample above\n";
    echo "2. Test the NOA creation form at /manifest-workflow/upload\n";
    echo "3. Verify CY allocations show the correct shipping line\n";
    echo "4. Update any admin interfaces that manage terminal allocations\n\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

try {
    $conn = DriverManager::getConnection($connectionParams);
    
    echo "=== Terminal Allocation Migration ===\n\n";
    
    // Step 1: Check if column already exists
    echo "Step 1: Checking if shipping_line_id column exists...\n";
    $sql = "SHOW COLUMNS FROM shipping_line_terminal_allocations LIKE 'shipping_line_id'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ Column shipping_line_id already exists. Skipping column creation.\n\n";
    } else {
        echo "Adding shipping_line_id column...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations 
            ADD COLUMN shipping_line_id INT NULL AFTER staff_user_id
        ");
        echo "✓ Column added successfully.\n\n";
    }
    
    // Step 2: Check if foreign key exists
    echo "Step 2: Checking foreign key constraint...\n";
    $sql = "SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_NAME = 'shipping_line_terminal_allocations' 
            AND CONSTRAINT_NAME = 'fk_terminal_allocation_shipping_line'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ Foreign key already exists. Skipping.\n\n";
    } else {
        echo "Adding foreign key constraint...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations
            ADD CONSTRAINT fk_terminal_allocation_shipping_line
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id)
            ON DELETE CASCADE
        ");
        echo "✓ Foreign key added successfully.\n\n";
    }
    
    // Step 3: Check if index exists
    echo "Step 3: Checking index...\n";
    $sql = "SHOW INDEX FROM shipping_line_terminal_allocations 
            WHERE Key_name = 'idx_terminal_allocation_shipping_line'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ Index already exists. Skipping.\n\n";
    } else {
        echo "Creating index...\n";
        $conn->executeStatement("
            CREATE INDEX idx_terminal_allocation_shipping_line 
            ON shipping_line_terminal_allocations(shipping_line_id)
        ");
        echo "✓ Index created successfully.\n\n";
    }
    
    // Step 4: Check for records without shipping_line_id
    echo "Step 4: Checking for records without shipping_line_id...\n";
    $sql = "SELECT COUNT(*) as count 
            FROM shipping_line_terminal_allocations 
            WHERE shipping_line_id IS NULL";
    $result = $conn->fetchAssociative($sql);
    $nullCount = $result['count'];
    
    if ($nullCount > 0) {
        echo "Found {$nullCount} records without shipping_line_id.\n";
        echo "Attempting to populate shipping_line_id from staff user hierarchy...\n";
        
        // Try to find shipping line through staff user's admin
        $sql = "
            UPDATE shipping_line_terminal_allocations ta
            INNER JOIN staff_users su ON ta.staff_user_id = su.id
            INNER JOIN users u ON su.id = u.id
            LEFT JOIN shipping_lines sl ON u.shipping_line_id = sl.id
            SET ta.shipping_line_id = sl.id
            WHERE ta.shipping_line_id IS NULL AND sl.id IS NOT NULL
        ";
        
        $updated = $conn->executeStatement($sql);
        echo "✓ Updated {$updated} records.\n";
        
        // Check again
        $result = $conn->fetchAssociative("
            SELECT COUNT(*) as count 
            FROM shipping_line_terminal_allocations 
            WHERE shipping_line_id IS NULL
        ");
        $remainingNull = $result['count'];
        
        if ($remainingNull > 0) {
            echo "\n⚠ WARNING: {$remainingNull} records still have NULL shipping_line_id.\n";
            echo "These records need manual review:\n";
            
            $records = $conn->fetchAllAssociative("
                SELECT ta.id, ta.staff_user_id, su.email, t.name as terminal_name
                FROM shipping_line_terminal_allocations ta
                LEFT JOIN staff_users su ON ta.staff_user_id = su.id
                LEFT JOIN terminals t ON ta.terminal_id = t.id
                WHERE ta.shipping_line_id IS NULL
            ");
            
            foreach ($records as $record) {
                echo "  - ID: {$record['id']}, Staff: {$record['email']}, Terminal: {$record['terminal_name']}\n";
            }
            echo "\n";
        } else {
            echo "✓ All records now have shipping_line_id set.\n\n";
        }
    } else {
        echo "✓ All records already have shipping_line_id set.\n\n";
    }
    
    // Step 5: Update unique constraint
    echo "Step 5: Updating unique constraint...\n";
    
    // Check if old constraint exists
    $sql = "SHOW INDEX FROM shipping_line_terminal_allocations 
            WHERE Key_name = 'unique_staff_terminal'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "Dropping old unique constraint...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations
            DROP INDEX unique_staff_terminal
        ");
        echo "✓ Old constraint dropped.\n";
    }
    
    // Check if new constraint exists
    $sql = "SHOW INDEX FROM shipping_line_terminal_allocations 
            WHERE Key_name = 'unique_shipping_line_staff_terminal'";
    $result = $conn->fetchAssociative($sql);
    
    if ($result) {
        echo "✓ New unique constraint already exists.\n\n";
    } else {
        echo "Adding new unique constraint...\n";
        $conn->executeStatement("
            ALTER TABLE shipping_line_terminal_allocations
            ADD UNIQUE KEY unique_shipping_line_staff_terminal (shipping_line_id, staff_user_id, terminal_id)
        ");
        echo "✓ New constraint added successfully.\n\n";
    }
    
    // Step 6: Verification
    echo "Step 6: Verification...\n";
    $sql = "
        SELECT 
            ta.id,
            sl.brand_name as shipping_line,
            su.email as staff_email,
            t.name as terminal_name,
            ta.capacity_20ft,
            ta.capacity_40ft,
            ta.allocated_capacity
        FROM shipping_line_terminal_allocations ta
        LEFT JOIN shipping_lines sl ON ta.shipping_line_id = sl.id
        LEFT JOIN staff_users su ON ta.staff_user_id = su.id
        LEFT JOIN terminals t ON ta.terminal_id = t.id
        ORDER BY sl.brand_name, t.name
        LIMIT 10
    ";
    
    $records = $conn->fetchAllAssociative($sql);
    
    if (count($records) > 0) {
        echo "\nSample records (showing first 10):\n";
        echo str_repeat("-", 120) . "\n";
        printf("%-5s %-25s %-30s %-25s %-10s %-10s %-10s\n", 
            "ID", "Shipping Line", "Staff Email", "Terminal", "20ft", "40ft", "Allocated");
        echo str_repeat("-", 120) . "\n";
        
        foreach ($records as $record) {
            printf("%-5s %-25s %-30s %-25s %-10s %-10s %-10s\n",
                $record['id'],
                substr($record['shipping_line'] ?? 'NULL', 0, 25),
                substr($record['staff_email'] ?? 'NULL', 0, 30),
                substr($record['terminal_name'] ?? 'NULL', 0, 25),
                $record['capacity_20ft'],
                $record['capacity_40ft'],
                $record['allocated_capacity']
            );
        }
        echo str_repeat("-", 120) . "\n";
    }
    
    // Summary
    $totalRecords = $conn->fetchOne("SELECT COUNT(*) FROM shipping_line_terminal_allocations");
    echo "\n=== Migration Summary ===\n";
    echo "Total allocations: {$totalRecords}\n";
    echo "✓ Migration completed successfully!\n\n";
    
    echo "Next steps:\n";
    echo "1. Verify the data looks correct in the sample above\n";
    echo "2. Test the NOA creation form at /manifest-workflow/upload\n";
    echo "3. Verify CY allocations show the correct shipping line\n";
    echo "4. Update any admin interfaces that manage terminal allocations\n\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
