<?php

require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$connectionParams = [
    'dbname' => 'optimus',
    'user' => 'root',
    'password' => '',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
];

try {
    $conn = DriverManager::getConnection($connectionParams);
    
    echo "Connected to database successfully.\n\n";
    
    // Check if table exists
    $checkSql = "SHOW TABLES LIKE 'noa'";
    $result = $conn->executeQuery($checkSql);
    
    if ($result->rowCount() > 0) {
        echo "Table 'noa' already exists.\n";
        
        // Check if pdf_path column exists
        $checkCol = "SHOW COLUMNS FROM `noa` LIKE 'pdf_path'";
        $colResult = $conn->executeQuery($checkCol);
        
        if ($colResult->rowCount() == 0) {
            echo "Adding 'pdf_path' column...\n";
            $conn->executeStatement("ALTER TABLE `noa` ADD COLUMN `pdf_path` VARCHAR(500) NULL");
            echo "✓ Column 'pdf_path' added successfully.\n";
        } else {
            echo "✓ Column 'pdf_path' already exists.\n";
        }
    } else {
        echo "Creating 'noa' table...\n";
        
        $createSql = "CREATE TABLE noa (
            id INT AUTO_INCREMENT NOT NULL,
            noa_number VARCHAR(50) NOT NULL,
            bl_number VARCHAR(50) NOT NULL,
            vessel_number VARCHAR(50) NOT NULL,
            eta DATETIME NOT NULL,
            cy_location VARCHAR(100) NOT NULL,
            consignee_id INT NOT NULL,
            created_by_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            pdf_path VARCHAR(500) NULL,
            UNIQUE INDEX UNIQ_noa_noa_number (noa_number),
            INDEX idx_noa_noa_number (noa_number),
            INDEX idx_noa_bl_number (bl_number),
            INDEX idx_noa_consignee (consignee_id),
            INDEX idx_noa_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB";
        
        $conn->executeStatement($createSql);
        echo "✓ Table 'noa' created successfully.\n";
        
        // Add foreign keys
        echo "Adding foreign keys...\n";
        
        // Check if consignees table exists
        $checkConsignees = "SHOW TABLES LIKE 'consignees'";
        $consigneesResult = $conn->executeQuery($checkConsignees);
        
        if ($consigneesResult->rowCount() > 0) {
            $conn->executeStatement("ALTER TABLE noa 
                ADD CONSTRAINT FK_noa_consignee 
                FOREIGN KEY (consignee_id) REFERENCES consignees (id) ON DELETE RESTRICT");
            echo "✓ Consignee foreign key added.\n";
        } else {
            echo "⚠ Consignees table not found, skipping consignee foreign key.\n";
        }
        
        $conn->executeStatement("ALTER TABLE noa 
            ADD CONSTRAINT FK_noa_created_by 
            FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT");
        
        echo "✓ Created by foreign key added successfully.\n";
    }
    
    echo "\nDone!\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
