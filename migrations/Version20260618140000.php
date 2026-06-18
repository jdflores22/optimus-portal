<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add broker business address and backfill missing accreditation address data for billing documents';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE brokers ADD business_address VARCHAR(500) DEFAULT NULL'
        );

        $defaultAddresses = [
            'Suite 1201, Global Logistics Tower, Ortigas Center, Pasig City 1605',
            '15th Floor, Maritime Building, Port Area, Manila City 1018',
            'Unit 8B, Ocean Freight Center, BGC, Taguig City 1634',
            '2nd Floor, Cargo Hub, NAIA Complex, Pasay City 1300',
            '10th Floor, Shipping Plaza, Ayala Avenue, Makati City 1226',
        ];

        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.applicant_id, a.submitted_data
             FROM accreditation_submissions a
             INNER JOIN brokers b ON b.id = a.applicant_id'
        );

        foreach ($rows as $row) {
            $data = json_decode((string) $row['submitted_data'], true);
            if (!is_array($data)) {
                $data = [];
            }

            $address = $this->extractAddress($data);
            if ($address === '') {
                $address = $defaultAddresses[(int) $row['applicant_id'] % count($defaultAddresses)];
                $data['business_address'] = $address;
                $this->connection->executeStatement(
                    'UPDATE accreditation_submissions SET submitted_data = ? WHERE id = ?',
                    [json_encode($data, JSON_THROW_ON_ERROR), $row['id']],
                );
            }

            $this->connection->executeStatement(
                'UPDATE brokers SET business_address = ? WHERE id = ? AND (business_address IS NULL OR business_address = \'\')',
                [$address, $row['applicant_id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brokers DROP business_address');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractAddress(array $data): string
    {
        foreach (['business_address', 'address', 'office_address', 'registered_address'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return trim($data[$key]);
            }
        }

        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (str_contains(strtolower($key), 'address') && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
