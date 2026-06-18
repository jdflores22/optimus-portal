<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Point billing template bill-to placeholders at consignee instead of broker';
    }

    public function up(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, layout FROM document_template_configurations WHERE id = 5'
        );

        if ($row === false || empty($row['layout'])) {
            return;
        }

        $layout = $row['layout'];
        $layout = str_replace('"placeholder":"broker.name"', '"placeholder":"consignee.name"', $layout);
        $layout = str_replace('"placeholder":"broker.address"', '"placeholder":"consignee.address"', $layout);
        $layout = str_replace('"placeholder":"broker.email"', '"placeholder":"consignee.email"', $layout);

        $this->connection->executeStatement(
            'UPDATE document_template_configurations SET layout = ? WHERE id = ?',
            [$layout, $row['id']],
        );
    }

    public function down(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, layout FROM document_template_configurations WHERE id = 5'
        );

        if ($row === false || empty($row['layout'])) {
            return;
        }

        $layout = $row['layout'];
        $layout = str_replace('"placeholder":"consignee.name"', '"placeholder":"broker.name"', $layout);
        $layout = str_replace('"placeholder":"consignee.address"', '"placeholder":"broker.address"', $layout);
        $layout = str_replace('"placeholder":"consignee.email"', '"placeholder":"broker.email"', $layout);

        $this->connection->executeStatement(
            'UPDATE document_template_configurations SET layout = ? WHERE id = ?',
            [$layout, $row['id']],
        );
    }
}
