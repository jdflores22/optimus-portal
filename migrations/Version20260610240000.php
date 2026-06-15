<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add provinces table and link cities to provinces for address picker cascade.
 */
final class Version20260610240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create provinces table, seed Philippine provinces, and link cities to provinces';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE provinces (
            id INT AUTO_INCREMENT NOT NULL,
            region_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_4ADAD40B98260155 (region_id),
            CONSTRAINT FK_4ADAD40B98260155 FOREIGN KEY (region_id) REFERENCES regions (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE cities ADD province_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cities ADD CONSTRAINT FK_D95DB16BE946114A FOREIGN KEY (province_id) REFERENCES provinces (id)');
        $this->addSql('CREATE INDEX IDX_D95DB16BE946114A ON cities (province_id)');

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($this->getProvincesByRegion() as $regionId => $provinces) {
            foreach ($provinces as $province) {
                $this->addSql(
                    'INSERT INTO provinces (region_id, name, code, created_at) VALUES (:regionId, :name, :code, :createdAt)',
                    [
                        'regionId' => $regionId,
                        'name' => $province['name'],
                        'code' => $province['code'],
                        'createdAt' => $now,
                    ]
                );
            }
        }

        foreach ($this->getCityProvinceMap() as $regionId => $cityMap) {
            foreach ($cityMap as $cityName => $provinceName) {
                $this->addSql(
                    'UPDATE cities c
                     INNER JOIN provinces p ON p.region_id = :regionId AND p.name = :provinceName
                     SET c.province_id = p.id
                     WHERE c.region_id = :regionId AND c.name = :cityName',
                    [
                        'regionId' => $regionId,
                        'provinceName' => $provinceName,
                        'cityName' => $cityName,
                    ]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cities DROP FOREIGN KEY FK_D95DB16BE946114A');
        $this->addSql('DROP INDEX IDX_D95DB16BE946114A ON cities');
        $this->addSql('ALTER TABLE cities DROP province_id');
        $this->addSql('DROP TABLE provinces');
    }

    /**
     * @return array<int, list<array{name: string, code: string}>>
     */
    private function getProvincesByRegion(): array
    {
        return [
            1 => [['name' => 'Metro Manila', 'code' => 'NCR-MM']],
            2 => [
                ['name' => 'Abra', 'code' => 'ABR'],
                ['name' => 'Apayao', 'code' => 'APA'],
                ['name' => 'Benguet', 'code' => 'BEN'],
                ['name' => 'Ifugao', 'code' => 'IFU'],
                ['name' => 'Kalinga', 'code' => 'KAL'],
                ['name' => 'Mountain Province', 'code' => 'MOU'],
            ],
            3 => [
                ['name' => 'Ilocos Norte', 'code' => 'ILN'],
                ['name' => 'Ilocos Sur', 'code' => 'ILS'],
                ['name' => 'La Union', 'code' => 'LUN'],
                ['name' => 'Pangasinan', 'code' => 'PAN'],
            ],
            4 => [
                ['name' => 'Batanes', 'code' => 'BTN'],
                ['name' => 'Cagayan', 'code' => 'CAG'],
                ['name' => 'Isabela', 'code' => 'ISA'],
                ['name' => 'Nueva Vizcaya', 'code' => 'NUV'],
                ['name' => 'Quirino', 'code' => 'QUI'],
            ],
            5 => [
                ['name' => 'Aurora', 'code' => 'AUR'],
                ['name' => 'Bataan', 'code' => 'BAN'],
                ['name' => 'Bulacan', 'code' => 'BUL'],
                ['name' => 'Nueva Ecija', 'code' => 'NUE'],
                ['name' => 'Pampanga', 'code' => 'PAM'],
                ['name' => 'Tarlac', 'code' => 'TAR'],
                ['name' => 'Zambales', 'code' => 'ZAM'],
            ],
            6 => [
                ['name' => 'Batangas', 'code' => 'BTG'],
                ['name' => 'Cavite', 'code' => 'CAV'],
                ['name' => 'Laguna', 'code' => 'LAG'],
                ['name' => 'Quezon', 'code' => 'QUE'],
                ['name' => 'Rizal', 'code' => 'RIZ'],
            ],
            7 => [
                ['name' => 'Marinduque', 'code' => 'MAD'],
                ['name' => 'Occidental Mindoro', 'code' => 'MDC'],
                ['name' => 'Oriental Mindoro', 'code' => 'MDR'],
                ['name' => 'Palawan', 'code' => 'PLW'],
                ['name' => 'Romblon', 'code' => 'ROM'],
            ],
            8 => [
                ['name' => 'Albay', 'code' => 'ALB'],
                ['name' => 'Camarines Norte', 'code' => 'CAN'],
                ['name' => 'Camarines Sur', 'code' => 'CAS'],
                ['name' => 'Catanduanes', 'code' => 'CAT'],
                ['name' => 'Masbate', 'code' => 'MAS'],
                ['name' => 'Sorsogon', 'code' => 'SOR'],
            ],
            9 => [
                ['name' => 'Aklan', 'code' => 'AKL'],
                ['name' => 'Antique', 'code' => 'ANT'],
                ['name' => 'Capiz', 'code' => 'CAP'],
                ['name' => 'Guimaras', 'code' => 'GUI'],
                ['name' => 'Iloilo', 'code' => 'ILI'],
                ['name' => 'Negros Occidental', 'code' => 'NEC'],
            ],
            10 => [
                ['name' => 'Bohol', 'code' => 'BOH'],
                ['name' => 'Cebu', 'code' => 'CEB'],
                ['name' => 'Negros Oriental', 'code' => 'NER'],
                ['name' => 'Siquijor', 'code' => 'SIG'],
            ],
            11 => [
                ['name' => 'Biliran', 'code' => 'BIL'],
                ['name' => 'Eastern Samar', 'code' => 'EAS'],
                ['name' => 'Leyte', 'code' => 'LEY'],
                ['name' => 'Northern Samar', 'code' => 'NSA'],
                ['name' => 'Samar', 'code' => 'WSA'],
                ['name' => 'Southern Leyte', 'code' => 'SLE'],
            ],
            12 => [
                ['name' => 'Zamboanga del Norte', 'code' => 'ZAN'],
                ['name' => 'Zamboanga del Sur', 'code' => 'ZAS'],
                ['name' => 'Zamboanga Sibugay', 'code' => 'ZSI'],
                ['name' => 'Basilan', 'code' => 'BAS'],
            ],
            13 => [
                ['name' => 'Bukidnon', 'code' => 'BUK'],
                ['name' => 'Camiguin', 'code' => 'CAM'],
                ['name' => 'Lanao del Norte', 'code' => 'LAN'],
                ['name' => 'Misamis Occidental', 'code' => 'MSC'],
                ['name' => 'Misamis Oriental', 'code' => 'MSR'],
            ],
            14 => [
                ['name' => 'Davao de Oro', 'code' => 'COM'],
                ['name' => 'Davao del Norte', 'code' => 'DAV'],
                ['name' => 'Davao del Sur', 'code' => 'DAS'],
                ['name' => 'Davao Occidental', 'code' => 'DAC'],
                ['name' => 'Davao Oriental', 'code' => 'DAO'],
            ],
            15 => [
                ['name' => 'Cotabato', 'code' => 'NCO'],
                ['name' => 'Sarangani', 'code' => 'SAR'],
                ['name' => 'South Cotabato', 'code' => 'SCO'],
                ['name' => 'Sultan Kudarat', 'code' => 'SUK'],
            ],
            16 => [
                ['name' => 'Agusan del Norte', 'code' => 'AGN'],
                ['name' => 'Agusan del Sur', 'code' => 'AGS'],
                ['name' => 'Dinagat Islands', 'code' => 'DIN'],
                ['name' => 'Surigao del Norte', 'code' => 'SUN'],
                ['name' => 'Surigao del Sur', 'code' => 'SUR'],
            ],
            17 => [
                ['name' => 'Basilan', 'code' => 'BAS-BARMM'],
                ['name' => 'Lanao del Sur', 'code' => 'LAS'],
                ['name' => 'Maguindanao del Norte', 'code' => 'MGN'],
                ['name' => 'Maguindanao del Sur', 'code' => 'MGS'],
                ['name' => 'Special Geographic Area', 'code' => 'SGA'],
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getCityProvinceMap(): array
    {
        return [
            1 => [
                'Manila' => 'Metro Manila',
                'Quezon City' => 'Metro Manila',
                'Caloocan' => 'Metro Manila',
                'Las Piñas' => 'Metro Manila',
                'Makati' => 'Metro Manila',
                'Malabon' => 'Metro Manila',
                'Mandaluyong' => 'Metro Manila',
                'Marikina' => 'Metro Manila',
                'Muntinlupa' => 'Metro Manila',
                'Navotas' => 'Metro Manila',
                'Parañaque' => 'Metro Manila',
                'Pasay' => 'Metro Manila',
                'Pasig' => 'Metro Manila',
                'Pateros' => 'Metro Manila',
                'San Juan' => 'Metro Manila',
                'Taguig' => 'Metro Manila',
                'Valenzuela' => 'Metro Manila',
            ],
            2 => [
                'Baguio' => 'Benguet',
                'Tabuk' => 'Kalinga',
                'Bontoc' => 'Mountain Province',
                'Buguias' => 'Benguet',
                'Itogon' => 'Benguet',
                'Kabayan' => 'Benguet',
                'La Trinidad' => 'Benguet',
                'Mankayan' => 'Benguet',
                'Sablan' => 'Benguet',
                'Tuba' => 'Benguet',
            ],
            3 => [
                'Alaminos' => 'Pangasinan',
                'Batac' => 'Ilocos Norte',
                'Candon' => 'Ilocos Sur',
                'Laoag' => 'Ilocos Norte',
                'San Carlos' => 'Pangasinan',
                'San Fernando' => 'La Union',
                'Urdaneta' => 'Pangasinan',
                'Vigan' => 'Ilocos Sur',
                'Dagupan' => 'Pangasinan',
            ],
            4 => [
                'Cauayan' => 'Isabela',
                'Ilagan' => 'Isabela',
                'Santiago' => 'Isabela',
                'Tuguegarao' => 'Cagayan',
            ],
            5 => [
                'Angeles' => 'Pampanga',
                'Balanga' => 'Bataan',
                'Cabanatuan' => 'Nueva Ecija',
                'Gapan' => 'Nueva Ecija',
                'Mabalacat' => 'Pampanga',
                'Malolos' => 'Bulacan',
                'Meycauayan' => 'Bulacan',
                'Muñoz' => 'Nueva Ecija',
                'Olongapo' => 'Zambales',
                'Palayan' => 'Nueva Ecija',
                'San Fernando' => 'Pampanga',
                'San Jose' => 'Nueva Ecija',
                'San Jose del Monte' => 'Bulacan',
                'Tarlac City' => 'Tarlac',
            ],
            6 => [
                'Antipolo' => 'Rizal',
                'Bacoor' => 'Cavite',
                'Batangas City' => 'Batangas',
                'Biñan' => 'Laguna',
                'Cabuyao' => 'Laguna',
                'Calamba' => 'Laguna',
                'Cavite City' => 'Cavite',
                'Dasmariñas' => 'Cavite',
                'General Trias' => 'Cavite',
                'Imus' => 'Cavite',
                'Lipa' => 'Batangas',
                'Lucena' => 'Quezon',
                'San Pablo' => 'Laguna',
                'San Pedro' => 'Laguna',
                'Santa Rosa' => 'Laguna',
                'Tagaytay' => 'Cavite',
                'Tanauan' => 'Batangas',
                'Trece Martires' => 'Cavite',
            ],
            7 => [
                'Calapan' => 'Oriental Mindoro',
                'Puerto Princesa' => 'Palawan',
            ],
            8 => [
                'Iriga' => 'Camarines Sur',
                'Legazpi' => 'Albay',
                'Ligao' => 'Albay',
                'Masbate City' => 'Masbate',
                'Naga' => 'Camarines Sur',
                'Sorsogon City' => 'Sorsogon',
                'Tabaco' => 'Albay',
            ],
            9 => [
                'Bacolod' => 'Negros Occidental',
                'Bago' => 'Negros Occidental',
                'Cadiz' => 'Negros Occidental',
                'Escalante' => 'Negros Occidental',
                'Himamaylan' => 'Negros Occidental',
                'Iloilo City' => 'Iloilo',
                'Kabankalan' => 'Negros Occidental',
                'La Carlota' => 'Negros Occidental',
                'Passi' => 'Iloilo',
                'Roxas' => 'Capiz',
                'Sagay' => 'Negros Occidental',
                'San Carlos' => 'Negros Occidental',
                'Silay' => 'Negros Occidental',
                'Sipalay' => 'Negros Occidental',
                'Talisay' => 'Negros Occidental',
                'Victorias' => 'Negros Occidental',
            ],
            10 => [
                'Bais' => 'Negros Oriental',
                'Bayawan' => 'Negros Oriental',
                'Bogo' => 'Cebu',
                'Canlaon' => 'Negros Oriental',
                'Carcar' => 'Cebu',
                'Cebu City' => 'Cebu',
                'Danao' => 'Cebu',
                'Dumaguete' => 'Negros Oriental',
                'Guihulngan' => 'Negros Oriental',
                'Lapu-Lapu' => 'Cebu',
                'Mandaue' => 'Cebu',
                'Naga' => 'Cebu',
                'Talisay' => 'Cebu',
                'Tanjay' => 'Negros Oriental',
                'Toledo' => 'Cebu',
                'Tagbilaran' => 'Bohol',
            ],
            11 => [
                'Baybay' => 'Leyte',
                'Borongan' => 'Eastern Samar',
                'Calbayog' => 'Samar',
                'Catbalogan' => 'Samar',
                'Maasin' => 'Southern Leyte',
                'Ormoc' => 'Leyte',
                'Tacloban' => 'Leyte',
            ],
            12 => [
                'Dapitan' => 'Zamboanga del Norte',
                'Dipolog' => 'Zamboanga del Norte',
                'Isabela' => 'Basilan',
                'Pagadian' => 'Zamboanga del Sur',
                'Zamboanga City' => 'Zamboanga del Sur',
            ],
            13 => [
                'Cagayan de Oro' => 'Misamis Oriental',
                'El Salvador' => 'Misamis Oriental',
                'Gingoog' => 'Misamis Oriental',
                'Iligan' => 'Lanao del Norte',
                'Malaybalay' => 'Bukidnon',
                'Oroquieta' => 'Misamis Occidental',
                'Ozamiz' => 'Misamis Occidental',
                'Tangub' => 'Misamis Occidental',
                'Valencia' => 'Bukidnon',
            ],
            14 => [
                'Davao City' => 'Davao del Sur',
                'Digos' => 'Davao del Sur',
                'Mati' => 'Davao Oriental',
                'Panabo' => 'Davao del Norte',
                'Samal' => 'Davao del Norte',
                'Tagum' => 'Davao del Norte',
            ],
            15 => [
                'General Santos' => 'South Cotabato',
                'Koronadal' => 'South Cotabato',
                'Kidapawan' => 'Cotabato',
                'Tacurong' => 'Sultan Kudarat',
            ],
            16 => [
                'Bayugan' => 'Agusan del Sur',
                'Bislig' => 'Surigao del Sur',
                'Butuan' => 'Agusan del Norte',
                'Cabadbaran' => 'Agusan del Norte',
                'Surigao City' => 'Surigao del Norte',
                'Tandag' => 'Surigao del Sur',
            ],
            17 => [
                'Cotabato City' => 'Maguindanao del Sur',
                'Lamitan' => 'Basilan',
                'Marawi' => 'Lanao del Sur',
            ],
        ];
    }
}
