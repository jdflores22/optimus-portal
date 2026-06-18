<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix billing template placeholder paths and normalize generated-by field binding';
    }

    public function up(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT layout FROM document_template_configurations WHERE id = 5'
        );

        if ($row === false) {
            return;
        }

        $layout = json_decode((string) $row['layout'], true);
        if (!is_array($layout) || !isset($layout['elements']) || !is_array($layout['elements'])) {
            return;
        }

        $changed = false;
        foreach ($layout['elements'] as &$element) {
            if (!is_array($element)) {
                continue;
            }

            if (($element['placeholder'] ?? '') === '{{ generated.by }}') {
                $element['placeholder'] = 'generated.by';
                $changed = true;
            }
        }
        unset($element);

        if (!$changed) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE document_template_configurations SET layout = ? WHERE id = 5',
            [json_encode($layout, JSON_THROW_ON_ERROR)],
        );
    }

    public function down(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT layout FROM document_template_configurations WHERE id = 5'
        );

        if ($row === false) {
            return;
        }

        $layout = json_decode((string) $row['layout'], true);
        if (!is_array($layout) || !isset($layout['elements']) || !is_array($layout['elements'])) {
            return;
        }

        $changed = false;
        foreach ($layout['elements'] as &$element) {
            if (!is_array($element)) {
                continue;
            }

            if (($element['placeholder'] ?? '') === 'generated.by' && ($element['id'] ?? '') === 'el_274fe56a') {
                $element['placeholder'] = '{{ generated.by }}';
                $changed = true;
            }
        }
        unset($element);

        if (!$changed) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE document_template_configurations SET layout = ? WHERE id = 5',
            [json_encode($layout, JSON_THROW_ON_ERROR)],
        );
    }
}
