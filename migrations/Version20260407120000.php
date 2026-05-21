<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for eDO Release Workflow schema changes
 * 
 * Creates:
 * - payment_fee_configurations table
 * - edo_release_history table
 * - Adds columns to electronic_delivery_orders table (status, released_by_id, released_at, rejection_reason)
 * - Adds indexes for performance
 * 
 * Requirements: 2.1, 4.2, 5.1, 16.3
 */
final class Version20260407120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create schema for eDO Release Workflow: payment_fee_configurations, edo_release_history tables and extend electronic_delivery_orders';
    }

    public function up(Schema $schema): void
    {
        // Create payment_fee_configurations table
        $this->addSql('CREATE TABLE IF NOT EXISTS payment_fee_configurations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fee_type VARCHAR(50) NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            configured_by_id INT NOT NULL,
            configured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            previous_amount DECIMAL(10, 2) NULL,
            CONSTRAINT fk_payment_fee_configurations_configured_by 
                FOREIGN KEY (configured_by_id) REFERENCES users(id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_fee_type ON payment_fee_configurations(fee_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_configured_at ON payment_fee_configurations(configured_at)');

        // Create edo_release_history table
        $this->addSql('CREATE TABLE IF NOT EXISTS edo_release_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            edo_id INT NOT NULL,
            from_status VARCHAR(50) NOT NULL,
            to_status VARCHAR(50) NOT NULL,
            actor_id INT NOT NULL,
            rejection_reason TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_edo_release_history_edo 
                FOREIGN KEY (edo_id) REFERENCES electronic_delivery_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_edo_release_history_actor 
                FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_edo_id ON edo_release_history(edo_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_created_at ON edo_release_history(created_at)');

        // Add columns to electronic_delivery_orders table
        // Note: MySQL doesn't support ADD COLUMN IF NOT EXISTS, so we check manually
        $this->addSql("
            SET @col_exists = (
                SELECT COUNT(*) 
                FROM information_schema.columns 
                WHERE table_schema = DATABASE() 
                AND table_name = 'electronic_delivery_orders' 
                AND column_name = 'status'
            )
        ");
        
        $this->addSql("
            SET @sql = IF(@col_exists = 0,
                'ALTER TABLE electronic_delivery_orders 
                ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT \"pending_release\",
                ADD COLUMN released_by_id INT NULL,
                ADD COLUMN released_at DATETIME NULL,
                ADD COLUMN rejection_reason TEXT NULL',
                'SELECT \"Columns already exist\" as message'
            )
        ");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        // Add foreign key for released_by_id
        $this->addSql("
            SET @fk_exists = (
                SELECT COUNT(*) 
                FROM information_schema.table_constraints 
                WHERE table_schema = DATABASE() 
                AND table_name = 'electronic_delivery_orders' 
                AND constraint_name = 'fk_electronic_delivery_orders_released_by'
            )
        ");
        
        $this->addSql("
            SET @sql = IF(@fk_exists = 0,
                'ALTER TABLE electronic_delivery_orders 
                ADD CONSTRAINT fk_electronic_delivery_orders_released_by 
                FOREIGN KEY (released_by_id) REFERENCES users(id) ON DELETE SET NULL',
                'SELECT \"Foreign key already exists\" as message'
            )
        ");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        // Add indexes for performance
        $this->addSql("
            SET @idx_exists = (
                SELECT COUNT(*) 
                FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = 'electronic_delivery_orders' 
                AND index_name = 'idx_edos_status'
            )
        ");
        
        $this->addSql("
            SET @sql = IF(@idx_exists = 0,
                'CREATE INDEX idx_edos_status ON electronic_delivery_orders(status)',
                'SELECT \"Index already exists\" as message'
            )
        ");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("
            SET @idx_exists = (
                SELECT COUNT(*) 
                FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = 'electronic_delivery_orders' 
                AND index_name = 'idx_edos_released_at'
            )
        ");
        
        $this->addSql("
            SET @sql = IF(@idx_exists = 0,
                'CREATE INDEX idx_edos_released_at ON electronic_delivery_orders(released_at)',
                'SELECT \"Index already exists\" as message'
            )
        ");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes from electronic_delivery_orders
        $this->addSql('DROP INDEX IF EXISTS idx_edos_status');
        $this->addSql('DROP INDEX IF EXISTS idx_edos_released_at');

        // Drop foreign key and columns from electronic_delivery_orders
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP CONSTRAINT IF EXISTS fk_electronic_delivery_orders_released_by');
        $this->addSql('ALTER TABLE electronic_delivery_orders 
            DROP COLUMN IF EXISTS status,
            DROP COLUMN IF EXISTS released_by_id,
            DROP COLUMN IF EXISTS released_at,
            DROP COLUMN IF EXISTS rejection_reason'
        );

        // Drop edo_release_history table
        $this->addSql('DROP TABLE IF EXISTS edo_release_history');

        // Drop payment_fee_configurations table
        $this->addSql('DROP TABLE IF EXISTS payment_fee_configurations');
    }
}
