<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add foreign key relationships from containers to container_types and container_sizes
 */
final class Version20260404150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add foreign key relationships from containers table to container_types and container_sizes tables';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Add new foreign key columns (nullable initially for data migration)
        $this->addSql('ALTER TABLE containers ADD COLUMN container_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE containers ADD COLUMN container_size_id INT DEFAULT NULL');

        // Step 2: Migrate existing data by matching VARCHAR values to codes
        // Map container types (assuming existing data uses codes like 'DRY', 'REEFER', etc.)
        $this->addSql('
            UPDATE containers c
            INNER JOIN container_types ct ON c.type = ct.code
            SET c.container_type_id = ct.id
        ');

        // Map container sizes (assuming existing data uses codes like '20FT', '40FT', etc.)
        $this->addSql('
            UPDATE containers c
            INNER JOIN container_sizes cs ON c.size = cs.code
            SET c.container_size_id = cs.id
        ');

        // Step 3: Add foreign key constraints
        $this->addSql('
            ALTER TABLE containers 
            ADD CONSTRAINT FK_containers_container_type 
            FOREIGN KEY (container_type_id) REFERENCES container_types (id) ON DELETE RESTRICT
        ');

        $this->addSql('
            ALTER TABLE containers 
            ADD CONSTRAINT FK_containers_container_size 
            FOREIGN KEY (container_size_id) REFERENCES container_sizes (id) ON DELETE RESTRICT
        ');

        // Step 4: Add indexes for performance
        $this->addSql('CREATE INDEX IDX_containers_type ON containers (container_type_id)');
        $this->addSql('CREATE INDEX IDX_containers_size ON containers (container_size_id)');

        // Step 5: Make foreign key columns NOT NULL (after data migration)
        $this->addSql('ALTER TABLE containers MODIFY container_type_id INT NOT NULL');
        $this->addSql('ALTER TABLE containers MODIFY container_size_id INT NOT NULL');

        // Step 6: Drop old VARCHAR columns (keep them for now, commented out for safety)
        // Uncomment these lines after verifying the migration works correctly
        // $this->addSql('ALTER TABLE containers DROP COLUMN type');
        // $this->addSql('ALTER TABLE containers DROP COLUMN size');
    }

    public function down(Schema $schema): void
    {
        // Restore VARCHAR columns if they were dropped
        // $this->addSql('ALTER TABLE containers ADD COLUMN type VARCHAR(20) NOT NULL');
        // $this->addSql('ALTER TABLE containers ADD COLUMN size VARCHAR(10) NOT NULL');

        // Restore data from foreign keys back to VARCHAR
        // $this->addSql('
        //     UPDATE containers c
        //     INNER JOIN container_types ct ON c.container_type_id = ct.id
        //     SET c.type = ct.code
        // ');

        // $this->addSql('
        //     UPDATE containers c
        //     INNER JOIN container_sizes cs ON c.container_size_id = cs.id
        //     SET c.size = cs.code
        // ');

        // Drop foreign key constraints
        $this->addSql('ALTER TABLE containers DROP FOREIGN KEY FK_containers_container_type');
        $this->addSql('ALTER TABLE containers DROP FOREIGN KEY FK_containers_container_size');

        // Drop indexes
        $this->addSql('DROP INDEX IDX_containers_type ON containers');
        $this->addSql('DROP INDEX IDX_containers_size ON containers');

        // Drop foreign key columns
        $this->addSql('ALTER TABLE containers DROP COLUMN container_type_id');
        $this->addSql('ALTER TABLE containers DROP COLUMN container_size_id');
    }
}
