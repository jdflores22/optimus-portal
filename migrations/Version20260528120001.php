<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data migration to populate version numbers and previous_payment_id for existing payments
 * 
 * This migration:
 * - Populates version numbers based on created_at order within each manifest_id + payment_type group
 * - Sets previous_payment_id relationships between consecutive versions
 * - Includes validation queries to verify data integrity
 * - Provides rollback capability
 */
final class Version20260528120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Populate version numbers and previous_payment_id relationships for existing payments';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Populate version numbers based on created_at order
        // Group by manifest_id and payment_type, order by created_at
        $this->addSql("
            SET @version = 0;
            SET @manifest = 0;
            SET @type = '';
            
            UPDATE payments p
            INNER JOIN (
                SELECT 
                    id,
                    @version := IF(@manifest = manifest_id AND @type = payment_type, @version + 1, 1) AS new_version,
                    @manifest := manifest_id AS current_manifest,
                    @type := payment_type AS current_type
                FROM payments
                ORDER BY manifest_id, payment_type, created_at
            ) AS versions ON p.id = versions.id
            SET p.version = versions.new_version
        ");
        
        // Step 2: Set previous_payment_id relationships
        // Link each payment to its immediate predecessor (version - 1)
        $this->addSql("
            UPDATE payments p1
            INNER JOIN payments p2 
                ON p1.manifest_id = p2.manifest_id 
                AND p1.payment_type = p2.payment_type
                AND p1.version = p2.version + 1
            SET p1.previous_payment_id = p2.id
        ");
        
        // Step 3: Validation queries (executed as comments for documentation)
        // These can be run manually to verify data integrity
        
        // Validation 1: Check that all version 1 payments have NULL previous_payment_id
        $this->addSql("
            -- Validation Query 1: Verify version 1 payments have no previous payment
            -- Expected result: 0 rows
            -- SELECT id, manifest_id, payment_type, version, previous_payment_id 
            -- FROM payments 
            -- WHERE version = 1 AND previous_payment_id IS NOT NULL
        ");
        
        // Validation 2: Check that all version > 1 payments have a previous_payment_id
        $this->addSql("
            -- Validation Query 2: Verify version > 1 payments have previous payment
            -- Expected result: 0 rows
            -- SELECT id, manifest_id, payment_type, version, previous_payment_id 
            -- FROM payments 
            -- WHERE version > 1 AND previous_payment_id IS NULL
        ");
        
        // Validation 3: Check version sequence integrity
        $this->addSql("
            -- Validation Query 3: Verify version sequences are continuous
            -- Expected result: 0 rows with gaps
            -- SELECT 
            --     manifest_id, 
            --     payment_type, 
            --     GROUP_CONCAT(version ORDER BY version) as versions
            -- FROM payments
            -- GROUP BY manifest_id, payment_type
            -- HAVING versions NOT REGEXP '^1(,2)?(,3)?(,4)?(,5)?(,6)?(,7)?(,8)?(,9)?(,10)?$'
        ");
        
        // Validation 4: Check previous_payment_id references are valid
        $this->addSql("
            -- Validation Query 4: Verify all previous_payment_id references exist
            -- Expected result: 0 rows
            -- SELECT p1.id, p1.previous_payment_id
            -- FROM payments p1
            -- LEFT JOIN payments p2 ON p1.previous_payment_id = p2.id
            -- WHERE p1.previous_payment_id IS NOT NULL AND p2.id IS NULL
        ");
        
        // Validation 5: Check that previous payment is from same manifest and type
        $this->addSql("
            -- Validation Query 5: Verify previous payment is from same manifest/type
            -- Expected result: 0 rows
            -- SELECT p1.id, p1.manifest_id, p1.payment_type, p2.manifest_id, p2.payment_type
            -- FROM payments p1
            -- INNER JOIN payments p2 ON p1.previous_payment_id = p2.id
            -- WHERE p1.manifest_id != p2.manifest_id OR p1.payment_type != p2.payment_type
        ");
    }

    public function down(Schema $schema): void
    {
        // Rollback: Reset version to 1 and clear previous_payment_id
        $this->addSql('UPDATE payments SET version = 1, previous_payment_id = NULL');
    }
    
    /**
     * This method is called after the migration is executed
     * We can use it to run validation queries and report results
     */
    public function postUp(Schema $schema): void
    {
        $connection = $this->connection;
        
        // Run validation queries and log results
        $this->write('Running data integrity validations...');
        
        // Validation 1: Version 1 payments should have NULL previous_payment_id
        $result1 = $connection->fetchOne(
            'SELECT COUNT(*) FROM payments WHERE version = 1 AND previous_payment_id IS NOT NULL'
        );
        $this->write("Validation 1 (v1 with previous_payment_id): {$result1} violations (expected: 0)");
        
        // Validation 2: Version > 1 payments should have previous_payment_id
        $result2 = $connection->fetchOne(
            'SELECT COUNT(*) FROM payments WHERE version > 1 AND previous_payment_id IS NULL'
        );
        $this->write("Validation 2 (v>1 without previous_payment_id): {$result2} violations (expected: 0)");
        
        // Validation 3: Check for invalid previous_payment_id references
        $result3 = $connection->fetchOne(
            'SELECT COUNT(*) 
             FROM payments p1
             LEFT JOIN payments p2 ON p1.previous_payment_id = p2.id
             WHERE p1.previous_payment_id IS NOT NULL AND p2.id IS NULL'
        );
        $this->write("Validation 3 (invalid previous_payment_id references): {$result3} violations (expected: 0)");
        
        // Validation 4: Check manifest/type consistency
        $result4 = $connection->fetchOne(
            'SELECT COUNT(*)
             FROM payments p1
             INNER JOIN payments p2 ON p1.previous_payment_id = p2.id
             WHERE p1.manifest_id != p2.manifest_id OR p1.payment_type != p2.payment_type'
        );
        $this->write("Validation 4 (manifest/type mismatch): {$result4} violations (expected: 0)");
        
        // Summary statistics
        $totalPayments = $connection->fetchOne('SELECT COUNT(*) FROM payments');
        $version1Count = $connection->fetchOne('SELECT COUNT(*) FROM payments WHERE version = 1');
        $versionGt1Count = $connection->fetchOne('SELECT COUNT(*) FROM payments WHERE version > 1');
        $maxVersion = $connection->fetchOne('SELECT MAX(version) FROM payments');
        
        $this->write('');
        $this->write('Migration Summary:');
        $this->write("- Total payments: {$totalPayments}");
        $this->write("- Version 1 payments: {$version1Count}");
        $this->write("- Resubmitted payments (v>1): {$versionGt1Count}");
        $this->write("- Maximum version number: {$maxVersion}");
        
        // Check if any validations failed
        if ($result1 > 0 || $result2 > 0 || $result3 > 0 || $result4 > 0) {
            $this->write('');
            $this->write('WARNING: Data integrity violations detected! Please review the validation results above.');
        } else {
            $this->write('');
            $this->write('SUCCESS: All data integrity validations passed!');
        }
    }
}
