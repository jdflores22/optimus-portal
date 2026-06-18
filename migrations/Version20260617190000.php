<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Enum\DocumentTemplateType;
use App\Form\DocumentBlockTypes;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed active EDO document template (#8) with the standard electronic delivery order layout';
    }

    public function up(Schema $schema): void
    {
        $layout = DocumentBlockTypes::defaultEdoLayout();

        $this->connection->executeStatement(
            'UPDATE document_template_configurations SET layout = ?, orientation = ? WHERE id = 8 AND document_type = ?',
            [
                json_encode($layout, JSON_THROW_ON_ERROR),
                'landscape',
                DocumentTemplateType::EDO->value,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        // Layout is user-editable; no safe automatic rollback.
    }
}
