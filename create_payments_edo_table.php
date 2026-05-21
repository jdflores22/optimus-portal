<?php

// Direct database connection from .env
$host = '127.0.0.1';
$dbname = 'optimus_portal';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

echo "=== Creating payments_edo table ===\n\n";

try {
    // Create payments_edo table
    $sql = "CREATE TABLE IF NOT EXISTS payments_edo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        edo_id INT DEFAULT NULL,
        manifest_id INT NOT NULL,
        shipping_line_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        receipt_file_path VARCHAR(500) DEFAULT NULL,
        official_receipt_path VARCHAR(500) DEFAULT NULL,
        status VARCHAR(255) NOT NULL,
        submitted_by_id INT NOT NULL,
        validated_by_id INT DEFAULT NULL,
        validated_at DATETIME DEFAULT NULL,
        rejection_reason LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT fk_payments_edo_edo FOREIGN KEY (edo_id) REFERENCES electronic_delivery_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_payments_edo_manifest FOREIGN KEY (manifest_id) REFERENCES manifests(id) ON DELETE CASCADE,
        CONSTRAINT fk_payments_edo_shipping_line FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id),
        CONSTRAINT fk_payments_edo_submitted_by FOREIGN KEY (submitted_by_id) REFERENCES users(id),
        CONSTRAINT fk_payments_edo_validated_by FOREIGN KEY (validated_by_id) REFERENCES users(id),
        INDEX idx_payments_edo_edo_id (edo_id),
        INDEX idx_payments_edo_manifest_id (manifest_id),
        INDEX idx_payments_edo_status (status),
        INDEX idx_payments_edo_submitted_by (submitted_by_id),
        INDEX idx_payments_edo_validated_by (validated_by_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    echo "✓ payments_edo table created successfully\n\n";
    
    // Verify table structure
    $sql = "DESCRIBE payments_edo";
    $stmt = $pdo->query($sql);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Table structure:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']}) {$col['Null']} {$col['Key']}\n";
    }
    
    echo "\n✓ Table created and verified successfully!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
