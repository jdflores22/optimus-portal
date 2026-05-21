<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix electronic_delivery_orders foreign key to reference payments table instead of payment_verifications
 */
final class Version20260406120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix electronic_delivery_orders foreign key to reference payments table';
    }

    public function up(Schema $schema): void
    {
        // Drop the old foreign key constraint that references payment_verifications
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP FOREIGN KEY FK_3631ED274C3A3BB');
        
        // Add the correct foreign key constraint that references payments
        $this->addSql('ALTER TABLE electronic_delivery_orders ADD CONSTRAINT FK_edos_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // Drop the new foreign key
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP FOREIGN KEY FK_edos_payment');
        
        // Restore the old foreign key (for rollback purposes only)
        $this->addSql('ALTER TABLE electronic_delivery_orders ADD CONSTRAINT FK_3631ED274C3A3BB FOREIGN KEY (payment_id) REFERENCES payment_verifications (id)');
    }
}
