<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add currency column to payments table
 * Stores the billing currency (USD or PHP) with each payment for proper validation
 */
final class Version20260602120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add currency column (VARCHAR(3), NOT NULL, default PHP) to payments table';
    }

    public function up(Schema $schema): void
    {
        // Add currency column with default value of 'PHP' for historical data compatibility
        $this->addSql("ALTER TABLE payments ADD currency VARCHAR(3) NOT NULL DEFAULT 'PHP'");
        
        // Add index for performance (currency is used in queries and filtering)
        $this->addSql('CREATE INDEX idx_payments_currency ON payments (currency)');
    }

    public function down(Schema $schema): void
    {
        // Drop index
        $this->addSql('DROP INDEX idx_payments_currency ON payments');
        
        // Drop currency column
        $this->addSql('ALTER TABLE payments DROP currency');
    }
}
