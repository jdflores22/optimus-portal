<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Enum\DocumentTemplateType;
use App\Form\DocumentBlockTypes;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed active Billing document template (#5) with the standard invoice layout';
    }

    public function up(Schema $schema): void
    {
        $layout = DocumentBlockTypes::defaultLayout(DocumentTemplateType::BILLING);

        $this->connection->executeStatement(
            'UPDATE document_template_configurations SET layout = ? WHERE id = 5 AND document_type = ?',
            [
                json_encode($layout, JSON_THROW_ON_ERROR),
                DocumentTemplateType::BILLING->value,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        // Layout is user-editable; no safe automatic rollback.
    }
}
