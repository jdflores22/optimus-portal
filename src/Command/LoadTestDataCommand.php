<?php

namespace App\Command;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\AccreditationSubmission;
use App\Entity\ShipmentRecord;
use App\Entity\FormConfiguration;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:load-test-data',
    description: 'Load test data for development',
)]
class LoadTestDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Loading Sample Data');

        // Realistic broker names and companies
        $brokerData = [
            ['name' => 'Michael Rodriguez', 'email' => 'michael.rodriguez@globallogistics.com', 'company' => 'Global Logistics Solutions'],
            ['name' => 'Sarah Chen', 'email' => 'sarah.chen@maritimebrokers.com', 'company' => 'Maritime Brokers International'],
            ['name' => 'David Thompson', 'email' => 'david.thompson@oceanfreight.com', 'company' => 'Ocean Freight Services'],
            ['name' => 'Maria Santos', 'email' => 'maria.santos@cargoexperts.com', 'company' => 'Cargo Experts Philippines'],
            ['name' => 'James Wilson', 'email' => 'james.wilson@shippingpro.com', 'company' => 'Shipping Pro Logistics']
        ];

        // Realistic consignee business names
        $consigneeData = [
            ['business' => 'Pacific Electronics Trading Corp', 'email' => 'procurement@pacificelectronics.ph'],
            ['business' => 'Manila Textile Manufacturing Inc', 'email' => 'imports@manilatextile.com'],
            ['business' => 'Cebu Automotive Parts Ltd', 'email' => 'logistics@cebuautoparts.ph'],
            ['business' => 'Davao Agricultural Supplies Co', 'email' => 'operations@davaoagri.com'],
            ['business' => 'Makati Fashion Imports Inc', 'email' => 'shipping@makatifashion.ph'],
            ['business' => 'Quezon Industrial Equipment Corp', 'email' => 'receiving@quezonindustrial.com'],
            ['business' => 'Iloilo Food Processing Ltd', 'email' => 'imports@iloilofood.ph'],
            ['business' => 'Baguio Mining Equipment Inc', 'email' => 'procurement@baguiomining.com'],
            ['business' => 'Subic Bay Trading Company', 'email' => 'logistics@subictrading.ph'],
            ['business' => 'Batangas Steel Works Corp', 'email' => 'materials@batangassteel.com']
        ];

        // Create 5 Brokers
        $brokers = [];
        $io->section('Creating 5 Brokers');
        for ($i = 0; $i < 5; $i++) {
            $existingBroker = $this->entityManager->getRepository(Broker::class)->findOneBy(['email' => $brokerData[$i]['email']]);
            if ($existingBroker) {
                $brokers[] = $existingBroker;
                $io->text("Broker {$brokerData[$i]['name']} already exists, skipping...");
                continue;
            }

            $broker = new Broker();
            $broker->setEmail($brokerData[$i]['email']);
            $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'password123'));
            $broker->setFullName($brokerData[$i]['name']);
            $broker->setRole(UserRole::BROKER);
            $broker->setStatus(AccountStatus::APPROVED);
            
            $this->entityManager->persist($broker);
            $brokers[] = $broker;
            $io->text("Created Broker: {$brokerData[$i]['name']} ({$brokerData[$i]['company']})");
        }

        // Create 10 Consignees
        $consignees = [];
        $io->section('Creating 10 Consignees');
        for ($i = 0; $i < 10; $i++) {
            $existingConsignee = $this->entityManager->getRepository(Consignee::class)->findOneBy(['email' => $consigneeData[$i]['email']]);
            if ($existingConsignee) {
                $consignees[] = $existingConsignee;
                $io->text("Consignee {$consigneeData[$i]['business']} already exists, skipping...");
                continue;
            }

            $consignee = new Consignee();
            $consignee->setEmail($consigneeData[$i]['email']);
            $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'password123'));
            $consignee->setBusinessName($consigneeData[$i]['business']);
            $consignee->setRole(UserRole::CONSIGNEE);
            $consignee->setStatus(AccountStatus::APPROVED);
            
            // Link most consignees to brokers (8 out of 10)
            if ($i < 8 && !empty($brokers)) {
                $brokerIndex = $i % count($brokers);
                $consignee->setLinkedBroker($brokers[$brokerIndex]);
                $io->text("Created Consignee: {$consigneeData[$i]['business']} (linked to {$brokerData[$brokerIndex]['name']})");
            } else {
                $io->text("Created Consignee: {$consigneeData[$i]['business']} (no broker link)");
            }
            
            $this->entityManager->persist($consignee);
            $consignees[] = $consignee;
        }

        // Create some SL Staff users who should be creating shipment records
        $slStaffData = [
            ['name' => 'Carlos Mendoza', 'email' => 'carlos.mendoza@shippinglines.com', 'department' => 'Operations'],
            ['name' => 'Lisa Wang', 'email' => 'lisa.wang@shippinglines.com', 'department' => 'Manifest Processing'],
            ['name' => 'Roberto Santos', 'email' => 'roberto.santos@shippinglines.com', 'department' => 'Port Operations']
        ];

        $slStaff = [];
        $io->section('Creating 3 Shipping Lines Staff');
        for ($i = 0; $i < 3; $i++) {
            $existingStaff = $this->entityManager->getRepository(\App\Entity\StaffUser::class)->findOneBy(['email' => $slStaffData[$i]['email']]);
            if ($existingStaff) {
                $slStaff[] = $existingStaff;
                $io->text("SL Staff {$slStaffData[$i]['name']} already exists, skipping...");
                continue;
            }

            $staff = new \App\Entity\StaffUser();
            $staff->setEmail($slStaffData[$i]['email']);
            $staff->setPasswordHash($this->passwordHasher->hashPassword($staff, 'password123'));
            $staff->setFirstName(explode(' ', $slStaffData[$i]['name'])[0]);
            $staff->setLastName(explode(' ', $slStaffData[$i]['name'])[1]);
            $staff->setDepartment($slStaffData[$i]['department']);
            $staff->setRole(UserRole::SL_STAFF);
            $staff->setStatus(AccountStatus::APPROVED);
            
            $this->entityManager->persist($staff);
            $slStaff[] = $staff;
            $io->text("Created SL Staff: {$slStaffData[$i]['name']} ({$slStaffData[$i]['department']})");
        }

        $this->entityManager->flush();

        // Create Form Configurations if they don't exist
        $io->section('Setting up Form Configurations');
        $brokerForm = $this->entityManager->getRepository(FormConfiguration::class)->findOneBy(['type' => FormType::BROKER]);
        if (!$brokerForm) {
            $brokerForm = new FormConfiguration();
            $brokerForm->setName('Broker Registration Form');
            $brokerForm->setType(FormType::BROKER);
            $brokerForm->setVersion(1);
            $brokerForm->setFields([
                'fields' => [
                    [
                        'id' => 'business_name',
                        'label' => 'Business Name',
                        'type' => 'text',
                        'required' => true
                    ],
                    [
                        'id' => 'license_number',
                        'label' => 'License Number',
                        'type' => 'text',
                        'required' => true
                    ]
                ]
            ]);
            $brokerForm->setIsActive(true);
            $this->entityManager->persist($brokerForm);
            $io->text('Created Broker Form Configuration');
        } else {
            $io->text('Broker Form Configuration already exists');
        }

        $consigneeForm = $this->entityManager->getRepository(FormConfiguration::class)->findOneBy(['type' => FormType::CONSIGNEE]);
        if (!$consigneeForm) {
            $consigneeForm = new FormConfiguration();
            $consigneeForm->setName('Consignee Registration Form');
            $consigneeForm->setType(FormType::CONSIGNEE);
            $consigneeForm->setVersion(1);
            $consigneeForm->setFields([
                'fields' => [
                    [
                        'id' => 'business_name',
                        'label' => 'Business Name',
                        'type' => 'text',
                        'required' => true
                    ],
                    [
                        'id' => 'business_address',
                        'label' => 'Business Address',
                        'type' => 'textarea',
                        'required' => true
                    ]
                ]
            ]);
            $consigneeForm->setIsActive(true);
            $this->entityManager->persist($consigneeForm);
            $io->text('Created Consignee Form Configuration');
        } else {
            $io->text('Consignee Form Configuration already exists');
        }

        $this->entityManager->flush();

        // Create 5 Accreditation Submissions
        $io->section('Creating 5 Accreditation Submissions');
        $statuses = [
            AccreditationStatus::PENDING,
            AccreditationStatus::APPROVED,
            AccreditationStatus::COMPLIANCE_REQUIRED,
            AccreditationStatus::DENIED,
            AccreditationStatus::REJECTED
        ];

        // 3 broker accreditations
        for ($i = 0; $i < 3 && $i < count($brokers); $i++) {
            $existingSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)->findOneBy(['applicant' => $brokers[$i]]);
            if ($existingSubmission) {
                $io->text("Broker {$brokerData[$i]['name']} already has accreditation submission, skipping...");
                continue;
            }

            $submission = new AccreditationSubmission();
            $submission->setApplicant($brokers[$i]);
            $submission->setFormConfig($brokerForm);
            $submission->setSubmittedData([
                'business_name' => $brokerData[$i]['company'],
                'license_number' => "BRK" . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'business_address' => $this->getBrokerAddress($i),
            ]);
            $submission->setStatus($statuses[$i]);
            
            if ($statuses[$i] !== AccreditationStatus::PENDING) {
                $submission->setEvaluatedAt(new \DateTime('-' . rand(1, 30) . ' days'));
            }
            
            if ($statuses[$i] === AccreditationStatus::DENIED || $statuses[$i] === AccreditationStatus::REJECTED) {
                $submission->setDenialReason("Documentation incomplete or requirements not met for broker license verification.");
            }
            
            $this->entityManager->persist($submission);
            $io->text("Created Broker Accreditation for {$brokerData[$i]['name']} with status: " . $statuses[$i]->value);
        }

        // 2 consignee accreditations
        for ($i = 0; $i < 2 && $i < count($consignees); $i++) {
            $existingSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)->findOneBy(['applicant' => $consignees[$i]]);
            if ($existingSubmission) {
                $io->text("Consignee {$consigneeData[$i]['business']} already has accreditation submission, skipping...");
                continue;
            }

            $submission = new AccreditationSubmission();
            $submission->setApplicant($consignees[$i]);
            $submission->setFormConfig($consigneeForm);
            $submission->setSubmittedData([
                'business_name' => $consigneeData[$i]['business'],
                'business_address' => $this->getRandomAddress($i)
            ]);
            $submission->setStatus($statuses[$i + 3]);
            
            if ($statuses[$i + 3] !== AccreditationStatus::PENDING) {
                $submission->setEvaluatedAt(new \DateTime('-' . rand(1, 30) . ' days'));
            }
            
            if ($statuses[$i + 3] === AccreditationStatus::DENIED || $statuses[$i + 3] === AccreditationStatus::REJECTED) {
                $submission->setDenialReason("Business registration documents require additional verification or compliance updates.");
            }
            
            $this->entityManager->persist($submission);
            $io->text("Created Consignee Accreditation for {$consigneeData[$i]['business']} with status: " . $statuses[$i + 3]->value);
        }

        // Create 20 Shipment Records
        $io->section('Creating 20 Shipment Records');
        $shipmentStatuses = [
            'Pending Arrival',
            'Arrived',
            'Customs Cleared',
            'Ready for Pickup',
            'Delivered'
        ];

        // Realistic vessel names
        $vessels = [
            'MV Ever Golden', 'MV Pacific Navigator', 'MV Manila Bay Express', 
            'MV Cebu Princess', 'MV Davao Voyager', 'MV Asian Pioneer',
            'MV Ocean Harmony', 'MV Star Carrier', 'MV Blue Horizon',
            'MV Trade Wind'
        ];

        // Origin ports with realistic details
        $originPorts = [
            ['name' => 'Shanghai', 'country' => 'China'],
            ['name' => 'Singapore', 'country' => 'Singapore'],
            ['name' => 'Hamburg', 'country' => 'Germany'],
            ['name' => 'Rotterdam', 'country' => 'Netherlands'],
            ['name' => 'Hong Kong', 'country' => 'Hong Kong'],
            ['name' => 'Busan', 'country' => 'South Korea'],
            ['name' => 'Yokohama', 'country' => 'Japan'],
            ['name' => 'Kaohsiung', 'country' => 'Taiwan']
        ];

        // Realistic cargo descriptions
        $cargoDescriptions = [
            'Electronic Components and Semiconductors',
            'Textile Fabrics and Garment Materials',
            'Automotive Parts and Accessories',
            'Agricultural Machinery and Equipment',
            'Industrial Manufacturing Equipment',
            'Processed Food Products and Beverages',
            'Steel and Metal Raw Materials',
            'Consumer Electronics and Appliances',
            'Chemical Products and Plastics',
            'Furniture and Home Furnishings',
            'Medical Equipment and Supplies',
            'Construction Materials and Tools',
            'Sporting Goods and Equipment',
            'Books and Educational Materials',
            'Cosmetics and Personal Care Products'
        ];

        // Shipping line prefixes for realistic B/L numbers
        $shippingLines = ['OOLU', 'MSCU', 'CMAU', 'COSCO', 'EVER', 'HAPAG', 'YANG', 'WANHAI'];

        for ($i = 1; $i <= 20; $i++) {
            $manifestNumber = "MNL" . date('Y') . str_pad($i, 4, '0', STR_PAD_LEFT);
            $existingShipment = $this->entityManager->getRepository(ShipmentRecord::class)->findOneBy(['manifestNumber' => $manifestNumber]);
            if ($existingShipment) {
                $io->text("Shipment {$manifestNumber} already exists, skipping...");
                continue;
            }

            $shipment = new ShipmentRecord();
            
            // Basic shipment info with realistic formatting
            $shipment->setManifestNumber($manifestNumber);
            
            // Realistic B/L number format
            $shippingLinePrefix = $shippingLines[($i - 1) % count($shippingLines)];
            $shipment->setBlNo($shippingLinePrefix . date('y') . str_pad($i, 6, '0', STR_PAD_LEFT));
            
            // Realistic container number format (4 letters + 7 digits)
            $containerPrefixes = ['MSKU', 'GESU', 'TCLU', 'FCIU', 'TEMU', 'HJMU', 'PONU', 'NYKU'];
            $containerPrefix = $containerPrefixes[($i - 1) % count($containerPrefixes)];
            $shipment->setContainerNumber($containerPrefix . str_pad($i * 123, 7, '0', STR_PAD_LEFT));
            
            // Assign to consignees
            if (!empty($consignees)) {
                $consigneeIndex = ($i - 1) % count($consignees);
                $shipment->setConsignee($consignees[$consigneeIndex]);
            }
            
            // Realistic dates
            $baseDate = new \DateTime('-' . rand(5, 120) . ' days');
            $shipment->setNoticeOfArrivalDate($baseDate);
            
            // 70% chance of having actual arrival date
            if (rand(1, 10) <= 7) {
                $arrivalDate = clone $baseDate;
                $arrivalDate->add(new \DateInterval('P' . rand(0, 5) . 'D'));
                $shipment->setActualArrivalDate($arrivalDate);
            }
            
            // Status based on arrival
            $statusIndex = ($i - 1) % count($shipmentStatuses);
            $shipment->setCustStatus($shipmentStatuses[$statusIndex]);
            
            // Vessel and voyage info
            $vesselIndex = ($i - 1) % count($vessels);
            $shipment->setVessel($vessels[$vesselIndex]);
            $shipment->setVoyage(date('y') . str_pad($i + 100, 3, '0', STR_PAD_LEFT) . 'N');
            
            // Realistic cargo details
            $cargoIndex = ($i - 1) % count($cargoDescriptions);
            $shipment->setCommodity($cargoDescriptions[$cargoIndex]);
            
            // Weight and measurements
            $baseWeight = $this->getWeightByCargo($cargoDescriptions[$cargoIndex]);
            $shipment->setNetWtKgm((string)($baseWeight + rand(-2000, 3000)));
            $shipment->setMeasCbm((string)(rand(20, 80) . '.' . rand(10, 99)));
            
            // Package details
            $packageData = $this->getPackageDataByCargo($cargoDescriptions[$cargoIndex]);
            $shipment->setCommodityPcs((string)$packageData['count']);
            $shipment->setCommodityQty($packageData['type']);
            
            // Container details - add variety
            $containerTypes = ['20GP', '40GP', '40HC', '20RF', '40RF', '45HC'];
            $containerSizes = ['20ft', '40ft', '40ft', '20ft', '40ft', '45ft'];
            $containerIndex = $i % count($containerTypes);
            
            $shipment->setContainerType($containerTypes[$containerIndex]);
            $shipment->setContainerSize($containerSizes[$containerIndex]);
            
            // Port information - using billingInformation for port details
            $portIndex = ($i - 1) % count($originPorts);
            $billingInfo = "Port of Loading: {$originPorts[$portIndex]['name']}, {$originPorts[$portIndex]['country']}\n";
            $billingInfo .= "Port of Discharge: Port of Manila, Philippines\n";
            $billingInfo .= "Freight: Prepaid\n";
            $billingInfo .= "Service: Door to Port";
            $shipment->setBillingInformation($billingInfo);
            
            // Set created by (use SL Staff as creators - this makes more sense)
            if (!empty($slStaff)) {
                $staffIndex = ($i - 1) % count($slStaff);
                $shipment->setCreatedBy($slStaff[$staffIndex]);
            } else {
                // Fallback to first broker if no SL staff (shouldn't happen)
                $shipment->setCreatedBy($brokers[0]);
            }
            
            if (!empty($consignees)) {
                $consigneeIndex = ($i - 1) % count($consignees);
                $shipment->setConsignee($consignees[$consigneeIndex]);
                
                // Add authorized brokers
                if ($consignees[$consigneeIndex]->getLinkedBroker()) {
                    $shipment->addAuthorizedBroker($consignees[$consigneeIndex]->getLinkedBroker());
                }
                
                // 20% chance of additional authorized broker
                if (rand(0, 10) <= 2 && !empty($brokers)) {
                    $additionalBrokerIndex = rand(0, count($brokers) - 1);
                    if ($brokers[$additionalBrokerIndex] !== $consignees[$consigneeIndex]->getLinkedBroker()) {
                        $shipment->addAuthorizedBroker($brokers[$additionalBrokerIndex]);
                    }
                }
            }
            
            $this->entityManager->persist($shipment);
            $io->text("Created Shipment: {$manifestNumber} - {$cargoDescriptions[$cargoIndex]} from {$originPorts[$portIndex]['name']}");
        }

        $this->entityManager->flush();

        $io->success('Sample data loaded successfully!');
        $io->table(
            ['Entity', 'Count'],
            [
                ['Brokers', '5'],
                ['Consignees', '10'],
                ['SL Staff', '3'],
                ['Accreditation Submissions', '5'],
                ['Shipment Records', '20'],
            ]
        );

        $io->note([
            'Login credentials for sample users:',
            'Brokers: michael.rodriguez@globallogistics.com, sarah.chen@maritimebrokers.com, etc.',
            'Consignees: procurement@pacificelectronics.ph, imports@manilatextile.com, etc.',
            'SL Staff: carlos.mendoza@shippinglines.com, lisa.wang@shippinglines.com, etc.',
            'Password: password123'
        ]);

        return Command::SUCCESS;
    }

    private function getRandomAddress(int $index): string
    {
        $addresses = [
            '1234 Ayala Avenue, Makati City, Metro Manila 1226',
            '5678 Ortigas Center, Pasig City, Metro Manila 1605',
            '9012 BGC, Taguig City, Metro Manila 1634',
            '3456 Quezon Avenue, Quezon City, Metro Manila 1104',
            '7890 Roxas Boulevard, Manila City, Metro Manila 1000'
        ];
        return $addresses[$index % count($addresses)];
    }

    private function getWeightByCargo(string $cargoType): int
    {
        $weights = [
            'Electronic Components and Semiconductors' => 8000,
            'Textile Fabrics and Garment Materials' => 12000,
            'Automotive Parts and Accessories' => 18000,
            'Agricultural Machinery and Equipment' => 25000,
            'Industrial Manufacturing Equipment' => 30000,
            'Processed Food Products and Beverages' => 15000,
            'Steel and Metal Raw Materials' => 35000,
            'Consumer Electronics and Appliances' => 10000,
            'Chemical Products and Plastics' => 20000,
            'Furniture and Home Furnishings' => 14000,
            'Medical Equipment and Supplies' => 6000,
            'Construction Materials and Tools' => 28000,
            'Sporting Goods and Equipment' => 8000,
            'Books and Educational Materials' => 16000,
            'Cosmetics and Personal Care Products' => 5000
        ];
        
        return $weights[$cargoType] ?? 15000;
    }

    private function getPackageDataByCargo(string $cargoType): array
    {
        $packageData = [
            'Electronic Components and Semiconductors' => ['count' => rand(50, 200), 'type' => 'Cartons'],
            'Textile Fabrics and Garment Materials' => ['count' => rand(100, 400), 'type' => 'Bales'],
            'Automotive Parts and Accessories' => ['count' => rand(20, 80), 'type' => 'Crates'],
            'Agricultural Machinery and Equipment' => ['count' => rand(5, 15), 'type' => 'Units'],
            'Industrial Manufacturing Equipment' => ['count' => rand(3, 12), 'type' => 'Packages'],
            'Processed Food Products and Beverages' => ['count' => rand(200, 800), 'type' => 'Cases'],
            'Steel and Metal Raw Materials' => ['count' => rand(10, 50), 'type' => 'Bundles'],
            'Consumer Electronics and Appliances' => ['count' => rand(80, 300), 'type' => 'Cartons'],
            'Chemical Products and Plastics' => ['count' => rand(40, 120), 'type' => 'Drums'],
            'Furniture and Home Furnishings' => ['count' => rand(30, 100), 'type' => 'Pieces'],
            'Medical Equipment and Supplies' => ['count' => rand(25, 75), 'type' => 'Boxes'],
            'Construction Materials and Tools' => ['count' => rand(15, 60), 'type' => 'Pallets'],
            'Sporting Goods and Equipment' => ['count' => rand(60, 250), 'type' => 'Cartons'],
            'Books and Educational Materials' => ['count' => rand(150, 500), 'type' => 'Cartons'],
            'Cosmetics and Personal Care Products' => ['count' => rand(100, 350), 'type' => 'Cases']
        ];
        
        return $packageData[$cargoType] ?? ['count' => rand(50, 200), 'type' => 'Packages'];
    }

    private function getShipperByPort(array $port): array
    {
        $shippers = [
            'Shanghai' => [
                'name' => 'Shanghai Global Export Trading Co Ltd',
                'address' => 'Room 2108, World Trade Center, 2200 Yan\'an West Road, Shanghai 200336, China'
            ],
            'Singapore' => [
                'name' => 'Singapore International Trading Pte Ltd',
                'address' => '80 Robinson Road, #02-00, Singapore 068898'
            ],
            'Hamburg' => [
                'name' => 'Hamburg Export Solutions GmbH',
                'address' => 'Speicherstadt 15, 20457 Hamburg, Germany'
            ],
            'Rotterdam' => [
                'name' => 'Rotterdam Maritime Logistics BV',
                'address' => 'Wilhelminakade 909, 3072 AP Rotterdam, Netherlands'
            ],
            'Hong Kong' => [
                'name' => 'Hong Kong Trade International Ltd',
                'address' => 'Suite 3501, Two International Finance Centre, Hong Kong'
            ],
            'Busan' => [
                'name' => 'Busan Korea Export Corporation',
                'address' => '123 Haeundae-ro, Haeundae-gu, Busan 48099, South Korea'
            ],
            'Yokohama' => [
                'name' => 'Yokohama Trading Company Ltd',
                'address' => '2-2-1 Minato Mirai, Nishi-ku, Yokohama 220-8765, Japan'
            ],
            'Kaohsiung' => [
                'name' => 'Kaohsiung International Export Co',
                'address' => 'No. 85, Zhongzheng 4th Road, Kaohsiung 80661, Taiwan'
            ]
        ];
        
        return $shippers[$port['name']] ?? [
            'name' => $port['name'] . ' Export Trading Co',
            'address' => 'Export District, ' . $port['name'] . ', ' . $port['country']
        ];
    }

    private function getConsigneeAddress(int $index): string
    {
        $addresses = [
            'Unit 15A, Pacific Star Building, Sen. Gil Puyat Ave, Makati City 1226',
            '2nd Floor, Textile Center, Juan Luna St, Binondo, Manila 1006',
            'Lot 5, Mactan Economic Zone, Lapu-Lapu City, Cebu 6015',
            'KM 7, Diversion Road, Buhangin, Davao City 8000',
            '3rd Floor, Fashion Mall, Ayala Center, Makati City 1224',
            'Industrial Park, Commonwealth Ave, Quezon City 1121',
            'Food Processing Zone, Iloilo Business Park, Iloilo City 5000',
            'Mining District, Session Road, Baguio City 2600',
            'Subic Bay Freeport Zone, Building 229, Olongapo City 2200',
            'Steel Works Complex, Batangas Industrial Park, Batangas City 4200'
        ];
        return $addresses[$index % count($addresses)];
    }

    private function getBrokerAddress(int $index): string
    {
        $addresses = [
            'Suite 1201, Global Logistics Tower, Ortigas Center, Pasig City 1605',
            '15th Floor, Maritime Building, Port Area, Manila City 1018',
            'Unit 8B, Ocean Freight Center, BGC, Taguig City 1634',
            '2nd Floor, Cargo Hub, NAIA Complex, Pasay City 1300',
            '10th Floor, Shipping Plaza, Ayala Avenue, Makati City 1226'
        ];
        return $addresses[$index % count($addresses)];
    }

    // Legacy methods for backward compatibility
    private function getRandomShipperName(int $index): string
    {
        return $this->getShipperByPort(['name' => 'Shanghai', 'country' => 'China'])['name'];
    }

    private function getRandomShipperAddress(int $portIndex): string
    {
        return $this->getShipperByPort(['name' => 'Shanghai', 'country' => 'China'])['address'];
    }

    private function getRandomConsigneeAddress(int $index): string
    {
        return $this->getConsigneeAddress($index);
    }

    private function getRandomBrokerAddress(int $index): string
    {
        return $this->getBrokerAddress($index);
    }
}