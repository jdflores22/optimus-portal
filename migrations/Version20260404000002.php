<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create regions and cities tables with Philippine data
 */
final class Version20260404000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create regions and cities tables with Philippine regions and cities/municipalities';
    }

    public function up(Schema $schema): void
    {
        // Create regions table
        $this->addSql('CREATE TABLE regions (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_A26779F377153098 (code)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create cities table
        $this->addSql('CREATE TABLE cities (
            id INT AUTO_INCREMENT NOT NULL,
            region_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_D95DB16B98260155 (region_id),
            CONSTRAINT FK_D95DB16B98260155 FOREIGN KEY (region_id) REFERENCES regions (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Insert Philippine Regions
        $this->addSql("INSERT INTO regions (name, code, created_at) VALUES
            ('National Capital Region (NCR)', 'NCR', NOW()),
            ('Cordillera Administrative Region (CAR)', 'CAR', NOW()),
            ('Region I (Ilocos Region)', 'REGION-1', NOW()),
            ('Region II (Cagayan Valley)', 'REGION-2', NOW()),
            ('Region III (Central Luzon)', 'REGION-3', NOW()),
            ('Region IV-A (CALABARZON)', 'REGION-4A', NOW()),
            ('Region IV-B (MIMAROPA)', 'REGION-4B', NOW()),
            ('Region V (Bicol Region)', 'REGION-5', NOW()),
            ('Region VI (Western Visayas)', 'REGION-6', NOW()),
            ('Region VII (Central Visayas)', 'REGION-7', NOW()),
            ('Region VIII (Eastern Visayas)', 'REGION-8', NOW()),
            ('Region IX (Zamboanga Peninsula)', 'REGION-9', NOW()),
            ('Region X (Northern Mindanao)', 'REGION-10', NOW()),
            ('Region XI (Davao Region)', 'REGION-11', NOW()),
            ('Region XII (SOCCSKSARGEN)', 'REGION-12', NOW()),
            ('Region XIII (Caraga)', 'REGION-13', NOW()),
            ('Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)', 'BARMM', NOW())
        ");

        // Insert NCR Cities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (1, 'Manila', 'City', NOW()),
            (1, 'Quezon City', 'City', NOW()),
            (1, 'Caloocan', 'City', NOW()),
            (1, 'Las Piñas', 'City', NOW()),
            (1, 'Makati', 'City', NOW()),
            (1, 'Malabon', 'City', NOW()),
            (1, 'Mandaluyong', 'City', NOW()),
            (1, 'Marikina', 'City', NOW()),
            (1, 'Muntinlupa', 'City', NOW()),
            (1, 'Navotas', 'City', NOW()),
            (1, 'Parañaque', 'City', NOW()),
            (1, 'Pasay', 'City', NOW()),
            (1, 'Pasig', 'City', NOW()),
            (1, 'Pateros', 'Municipality', NOW()),
            (1, 'San Juan', 'City', NOW()),
            (1, 'Taguig', 'City', NOW()),
            (1, 'Valenzuela', 'City', NOW())
        ");

        // Insert CAR Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (2, 'Baguio', 'City', NOW()),
            (2, 'Tabuk', 'City', NOW()),
            (2, 'Bontoc', 'Municipality', NOW()),
            (2, 'Buguias', 'Municipality', NOW()),
            (2, 'Itogon', 'Municipality', NOW()),
            (2, 'Kabayan', 'Municipality', NOW()),
            (2, 'La Trinidad', 'Municipality', NOW()),
            (2, 'Mankayan', 'Municipality', NOW()),
            (2, 'Sablan', 'Municipality', NOW()),
            (2, 'Tuba', 'Municipality', NOW())
        ");

        // Insert Region I Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (3, 'Alaminos', 'City', NOW()),
            (3, 'Batac', 'City', NOW()),
            (3, 'Candon', 'City', NOW()),
            (3, 'Laoag', 'City', NOW()),
            (3, 'San Carlos', 'City', NOW()),
            (3, 'San Fernando', 'City', NOW()),
            (3, 'Urdaneta', 'City', NOW()),
            (3, 'Vigan', 'City', NOW()),
            (3, 'Dagupan', 'City', NOW())
        ");

        // Insert Region II Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (4, 'Cauayan', 'City', NOW()),
            (4, 'Ilagan', 'City', NOW()),
            (4, 'Santiago', 'City', NOW()),
            (4, 'Tuguegarao', 'City', NOW())
        ");

        // Insert Region III Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (5, 'Angeles', 'City', NOW()),
            (5, 'Balanga', 'City', NOW()),
            (5, 'Cabanatuan', 'City', NOW()),
            (5, 'Gapan', 'City', NOW()),
            (5, 'Mabalacat', 'City', NOW()),
            (5, 'Malolos', 'City', NOW()),
            (5, 'Meycauayan', 'City', NOW()),
            (5, 'Muñoz', 'City', NOW()),
            (5, 'Olongapo', 'City', NOW()),
            (5, 'Palayan', 'City', NOW()),
            (5, 'San Fernando', 'City', NOW()),
            (5, 'San Jose', 'City', NOW()),
            (5, 'San Jose del Monte', 'City', NOW()),
            (5, 'Tarlac City', 'City', NOW())
        ");

        // Insert Region IV-A (CALABARZON) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (6, 'Antipolo', 'City', NOW()),
            (6, 'Bacoor', 'City', NOW()),
            (6, 'Batangas City', 'City', NOW()),
            (6, 'Biñan', 'City', NOW()),
            (6, 'Cabuyao', 'City', NOW()),
            (6, 'Calamba', 'City', NOW()),
            (6, 'Cavite City', 'City', NOW()),
            (6, 'Dasmariñas', 'City', NOW()),
            (6, 'General Trias', 'City', NOW()),
            (6, 'Imus', 'City', NOW()),
            (6, 'Lipa', 'City', NOW()),
            (6, 'Lucena', 'City', NOW()),
            (6, 'San Pablo', 'City', NOW()),
            (6, 'San Pedro', 'City', NOW()),
            (6, 'Santa Rosa', 'City', NOW()),
            (6, 'Tagaytay', 'City', NOW()),
            (6, 'Tanauan', 'City', NOW()),
            (6, 'Trece Martires', 'City', NOW())
        ");

        // Insert Region IV-B (MIMAROPA) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (7, 'Calapan', 'City', NOW()),
            (7, 'Puerto Princesa', 'City', NOW())
        ");

        // Insert Region V (Bicol) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (8, 'Iriga', 'City', NOW()),
            (8, 'Legazpi', 'City', NOW()),
            (8, 'Ligao', 'City', NOW()),
            (8, 'Masbate City', 'City', NOW()),
            (8, 'Naga', 'City', NOW()),
            (8, 'Sorsogon City', 'City', NOW()),
            (8, 'Tabaco', 'City', NOW())
        ");

        // Insert Region VI (Western Visayas) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (9, 'Bacolod', 'City', NOW()),
            (9, 'Bago', 'City', NOW()),
            (9, 'Cadiz', 'City', NOW()),
            (9, 'Escalante', 'City', NOW()),
            (9, 'Himamaylan', 'City', NOW()),
            (9, 'Iloilo City', 'City', NOW()),
            (9, 'Kabankalan', 'City', NOW()),
            (9, 'La Carlota', 'City', NOW()),
            (9, 'Passi', 'City', NOW()),
            (9, 'Roxas', 'City', NOW()),
            (9, 'Sagay', 'City', NOW()),
            (9, 'San Carlos', 'City', NOW()),
            (9, 'Silay', 'City', NOW()),
            (9, 'Sipalay', 'City', NOW()),
            (9, 'Talisay', 'City', NOW()),
            (9, 'Victorias', 'City', NOW())
        ");

        // Insert Region VII (Central Visayas) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (10, 'Bais', 'City', NOW()),
            (10, 'Bayawan', 'City', NOW()),
            (10, 'Bogo', 'City', NOW()),
            (10, 'Canlaon', 'City', NOW()),
            (10, 'Carcar', 'City', NOW()),
            (10, 'Cebu City', 'City', NOW()),
            (10, 'Danao', 'City', NOW()),
            (10, 'Dumaguete', 'City', NOW()),
            (10, 'Guihulngan', 'City', NOW()),
            (10, 'Lapu-Lapu', 'City', NOW()),
            (10, 'Mandaue', 'City', NOW()),
            (10, 'Naga', 'City', NOW()),
            (10, 'Talisay', 'City', NOW()),
            (10, 'Tanjay', 'City', NOW()),
            (10, 'Toledo', 'City', NOW()),
            (10, 'Tagbilaran', 'City', NOW())
        ");

        // Insert Region VIII (Eastern Visayas) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (11, 'Baybay', 'City', NOW()),
            (11, 'Borongan', 'City', NOW()),
            (11, 'Calbayog', 'City', NOW()),
            (11, 'Catbalogan', 'City', NOW()),
            (11, 'Maasin', 'City', NOW()),
            (11, 'Ormoc', 'City', NOW()),
            (11, 'Tacloban', 'City', NOW())
        ");

        // Insert Region IX (Zamboanga Peninsula) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (12, 'Dapitan', 'City', NOW()),
            (12, 'Dipolog', 'City', NOW()),
            (12, 'Isabela', 'City', NOW()),
            (12, 'Pagadian', 'City', NOW()),
            (12, 'Zamboanga City', 'City', NOW())
        ");

        // Insert Region X (Northern Mindanao) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (13, 'Cagayan de Oro', 'City', NOW()),
            (13, 'El Salvador', 'City', NOW()),
            (13, 'Gingoog', 'City', NOW()),
            (13, 'Iligan', 'City', NOW()),
            (13, 'Malaybalay', 'City', NOW()),
            (13, 'Oroquieta', 'City', NOW()),
            (13, 'Ozamiz', 'City', NOW()),
            (13, 'Tangub', 'City', NOW()),
            (13, 'Valencia', 'City', NOW())
        ");

        // Insert Region XI (Davao Region) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (14, 'Davao City', 'City', NOW()),
            (14, 'Digos', 'City', NOW()),
            (14, 'Mati', 'City', NOW()),
            (14, 'Panabo', 'City', NOW()),
            (14, 'Samal', 'City', NOW()),
            (14, 'Tagum', 'City', NOW())
        ");

        // Insert Region XII (SOCCSKSARGEN) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (15, 'General Santos', 'City', NOW()),
            (15, 'Koronadal', 'City', NOW()),
            (15, 'Kidapawan', 'City', NOW()),
            (15, 'Tacurong', 'City', NOW())
        ");

        // Insert Region XIII (Caraga) Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (16, 'Bayugan', 'City', NOW()),
            (16, 'Bislig', 'City', NOW()),
            (16, 'Butuan', 'City', NOW()),
            (16, 'Cabadbaran', 'City', NOW()),
            (16, 'Surigao City', 'City', NOW()),
            (16, 'Tandag', 'City', NOW())
        ");

        // Insert BARMM Cities/Municipalities
        $this->addSql("INSERT INTO cities (region_id, name, type, created_at) VALUES
            (17, 'Cotabato City', 'City', NOW()),
            (17, 'Lamitan', 'City', NOW()),
            (17, 'Marawi', 'City', NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cities DROP FOREIGN KEY FK_D95DB16B98260155');
        $this->addSql('DROP TABLE cities');
        $this->addSql('DROP TABLE regions');
    }
}
