<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-barangays',
    description: 'Import barangays from open-admin-data PSGC JSON for cities in the database',
)]
class SeedBarangaysCommand extends Command
{
  private const DATA_BASE_URL = 'https://raw.githubusercontent.com/open-admin-data/philippines-administrative-divisions/main/data/barangay-by-region/';

    /** @var array<string, string> */
    private const REGION_FILES = [
        'NCR' => 'national-capital-region-NCR.json',
        'CAR' => 'cordillera-administrative-region-CAR.json',
        'REGION-1' => 'ilocos-region-R01.json',
        'REGION-2' => 'cagayan-valley-R02.json',
        'REGION-3' => 'central-luzon-R03.json',
        'REGION-4A' => 'calabarzon-R04A.json',
        'REGION-4B' => 'mimaropa-region-R17.json',
        'REGION-5' => 'bicol-region-R05.json',
        'REGION-6' => 'western-visayas-R06.json',
        'REGION-7' => 'central-visayas-R07.json',
        'REGION-8' => 'eastern-visayas-R08.json',
        'REGION-9' => 'zamboanga-peninsula-R09.json',
        'REGION-10' => 'northern-mindanao-R10.json',
        'REGION-11' => 'davao-region-R11.json',
        'REGION-12' => 'soccsksargen-R12.json',
        'REGION-13' => 'caraga-R13.json',
        'BARMM' => 'autonomous-region-in-muslim-mindanao-ARMM.json',
    ];

    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('truncate', null, InputOption::VALUE_NONE, 'Delete existing barangays before import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('truncate')) {
            $this->connection->executeStatement('DELETE FROM barangays');
            $io->writeln('Cleared existing barangays.');
        }

        $cities = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name, c.region_id, r.code AS region_code
             FROM cities c
             INNER JOIN regions r ON r.id = c.region_id
             ORDER BY c.id'
        );

        if ($cities === []) {
            $io->warning('No cities found. Seed cities first.');

            return Command::FAILURE;
        }

        $cityLookup = [];
        foreach ($cities as $city) {
            $cityLookup[$city['region_code']][$this->normalizeCityName($city['name'])] = (int) $city['id'];
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $inserted = 0;
        $skipped = 0;

        foreach (self::REGION_FILES as $regionCode => $fileName) {
            if (!isset($cityLookup[$regionCode])) {
                continue;
            }

            $url = self::DATA_BASE_URL . $fileName;
            $io->writeln(sprintf('Fetching %s ...', $fileName));
            $json = @file_get_contents($url);
            if ($json === false) {
                $io->warning(sprintf('Could not download %s', $url));
                continue;
            }

            $records = json_decode($json, true);
            if (!is_array($records)) {
                $io->warning(sprintf('Invalid JSON in %s', $fileName));
                continue;
            }

            $batch = [];
            foreach ($records as $record) {
                $parentName = $record['parent']['name']['en'] ?? null;
                $barangayName = $record['name']['en'] ?? null;
                if (!$parentName || !$barangayName) {
                    ++$skipped;
                    continue;
                }

                $normalizedParent = $this->normalizeCityName($parentName);
                $cityId = $cityLookup[$regionCode][$normalizedParent] ?? null;
                if ($cityId === null) {
                    ++$skipped;
                    continue;
                }

                $batch[] = [
                    'city_id' => $cityId,
                    'name' => $barangayName,
                    'code' => $record['code']['id'] ?? null,
                    'created_at' => $now,
                ];
            }

            foreach (array_chunk($batch, 500) as $chunk) {
                foreach ($chunk as $row) {
                    $this->connection->insert('barangays', $row);
                    ++$inserted;
                }
            }

            $io->writeln(sprintf('  Imported %d barangays for %s', count($batch), $regionCode));
        }

        $io->success(sprintf('Barangay import complete. Inserted: %d, skipped (unmatched): %d', $inserted, $skipped));

        return Command::SUCCESS;
    }

    private function normalizeCityName(string $name): string
    {
        $name = trim($name);
        foreach (['Science City of ', 'City of '] as $prefix) {
            if (str_starts_with(mb_strtolower($name), mb_strtolower($prefix))) {
                $name = substr($name, strlen($prefix));
                break;
            }
        }
        if (str_ends_with($name, ' City')) {
            $name = substr($name, 0, -5);
        }

        return mb_strtolower($name);
    }
}
