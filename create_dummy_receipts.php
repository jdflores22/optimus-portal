<?php

$mysqli = new mysqli('127.0.0.1', 'root', '', 'optimus_portal');

if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error);
}

echo "=== Creating Dummy Receipt Files ===\n\n";

// Create directory structure
$baseDir = 'var/share/payment-receipts/2026/04';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
    echo "Created directory: $baseDir\n\n";
}

// Get all pending validation payments
$result = $mysqli->query("
    SELECT id, edo_id, receipt_file_path
    FROM payments_edo
    WHERE status = 'pending_validation'
    ORDER BY id
");

while ($row = $result->fetch_assoc()) {
    $filePath = 'var/share/' . $row['receipt_file_path'];
    
    // Create a dummy PDF file
    $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /MediaBox [0 0 612 792] /Contents 4 0 R >>\nendobj\n4 0 obj\n<< /Length 44 >>\nstream\nBT\n/F1 24 Tf\n100 700 Td\n(Payment Receipt #" . $row['id'] . ") Tj\nET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\n0000000317 00000 n\ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n410\n%%EOF";
    
    // For PNG files, create a simple 1x1 pixel PNG
    if (str_ends_with($filePath, '.png')) {
        $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }
    
    file_put_contents($filePath, $content);
    echo "Created: $filePath\n";
}

echo "\n✓ All dummy receipt files created successfully!\n";

$mysqli->close();
