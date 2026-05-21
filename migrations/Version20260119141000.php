<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260119141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change payment verification from OneToOne to ManyToOne with shipment';
    }

    public function up(Schema $schema): void
    {
        // Drop the unique constraint on shipment_id to allow multiple payments per shipment
        $this->addSql('ALTER TABLE payment_verifications DROP INDEX UNIQ_7182EE8B7BE036FC');
        $this->addSql('ALTER TABLE payment_verifications ADD INDEX IDX_7182EE8B7BE036FC (shipment_id)');
    }

    public function down(Schema $schema): void
    {
        // Add back the unique constraint
        $this->addSql('ALTER TABLE payment_verifications ADD CONSTRAINT UNIQ_7182EE8B7BE036FC UNIQUE (shipment_id)');
    }
}