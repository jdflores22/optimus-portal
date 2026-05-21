<?php

namespace App\DataFixtures;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\AccreditationSubmission;
use App\Entity\ShipmentRecord;
use App\Entity\FormConfiguration;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\ShipmentStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TestDataFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public static function getGroups(): array
    {
        return ['test-data'];
    }

    public function load(ObjectManager $manager): void
    {
        // Create 5 Brokers
        $brokers = [];
        for ($i = 1; $i <= 5; $i++) {
            // Check if broker already exists
            $existingBroker = $manager->getRepository(Broker::class)->findOneBy(['email' => "testbroker{$i}@example.com"]);
            if ($existingBroker) {
                $brokers[] = $existingBroker;
                continue;
            }

            $broker = new Broker();
            $broker->setEmail("testbroker{$i}@example.com");
            $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'password123'));
            $broker->setFullName("Test Broker {$i} Full Name");
            $broker->setStatus(AccountStatus::APPROVED);
            
            $manager->persist($broker);
            $brokers[] = $broker;
        }

        // Create 10 Consignees
        $consignees = [];
        for ($i = 1; $i <= 10; $i++) {
            // Check if consignee already exists
            $existingConsignee = $manager->getRepository(Consignee::class)->findOneBy(['email' => "testconsignee{$i}@example.com"]);
            if ($existingConsignee) {
                $consignees[] = $existingConsignee;
                continue;
            }

            $consignee = new Consignee();
            $consignee->setEmail("testconsignee{$i}@example.com");
            $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'password123'));
            $consignee->setBusinessName("Test Consignee Business {$i} Ltd");
            $consignee->setStatus(AccountStatus::APPROVED);
            
            // Link some consignees to brokers
            if ($i <= 8 && !empty($brokers)) {
                $brokerIndex = ($i - 1) % count($brokers); // Distribute consignees among brokers
                $consignee->setLinkedBroker($brokers[$brokerIndex]);
            }
            
            $manager->persist($consignee);
            $consignees[] = $consignee;
        }

        $manager->flush(); // Flush to get IDs for relationships

        // Create Form Configurations if they don't exist
        $brokerForm = $manager->getRepository(FormConfiguration::class)->findOneBy(['type' => FormType::BROKER]);
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
            $manager->persist($brokerForm);
        }

        $consigneeForm = $manager->getRepository(FormConfiguration::class)->findOneBy(['type' => FormType::CONSIGNEE]);
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
            $manager->persist($consigneeForm);
        }

        $manager->flush();

        // Create 5 Accreditation Submissions (only if they don't exist)
        $statuses = [
            AccreditationStatus::PENDING,
            AccreditationStatus::APPROVED,
            AccreditationStatus::COMPLIANCE_REQUIRED,
            AccreditationStatus::DENIED,
            AccreditationStatus::REJECTED
        ];

        // 3 broker accreditations
        for ($i = 0; $i < 3 && $i < count($brokers); $i++) {
            $existingSubmission = $manager->getRepository(AccreditationSubmission::class)->findOneBy(['applicant' => $brokers[$i]]);
            if ($existingSubmission) {
                continue;
            }

            $submission = new AccreditationSubmission();
            $submission->setApplicant($brokers[$i]);
            $submission->setFormConfig($brokerForm);
            $submission->setSubmittedData([
                'business_name' => "Test Broker Business {$i}",
                'license_number' => "TBR" . str_pad($i + 1, 6, '0', STR_PAD_LEFT)
            ]);
            $submission->setStatus($statuses[$i]);
            
            if ($statuses[$i] !== AccreditationStatus::PENDING) {
                $submission->setEvaluatedAt(new \DateTime('-' . rand(1, 30) . ' days'));
            }
            
            if ($statuses[$i] === AccreditationStatus::DENIED || $statuses[$i] === AccreditationStatus::REJECTED) {
                $submission->setDenialReason("Sample denial reason for test broker {$i}");
            }
            
            $manager->persist($submission);
        }

        // 2 consignee accreditations
        for ($i = 0; $i < 2 && $i < count($consignees); $i++) {
            $existingSubmission = $manager->getRepository(AccreditationSubmission::class)->findOneBy(['applicant' => $consignees[$i]]);
            if ($existingSubmission) {
                continue;
            }

            $submission = new AccreditationSubmission();
            $submission->setApplicant($consignees[$i]);
            $submission->setFormConfig($consigneeForm);
            $submission->setSubmittedData([
                'business_name' => "Test Consignee Business {$i}",
                'business_address' => "123 Test Business Street, Test City {$i}, Test Country"
            ]);
            $submission->setStatus($statuses[$i + 3]);
            
            if ($statuses[$i + 3] !== AccreditationStatus::PENDING) {
                $submission->setEvaluatedAt(new \DateTime('-' . rand(1, 30) . ' days'));
            }
            
            if ($statuses[$i + 3] === AccreditationStatus::DENIED || $statuses[$i + 3] === AccreditationStatus::REJECTED) {
                $submission->setDenialReason("Sample denial reason for test consignee {$i}");
            }
            
            $manager->persist($submission);
        }

        // Create 20 Shipment Records
        $shipmentStatuses = [
            ShipmentStatus::PENDING_ARRIVAL,
            ShipmentStatus::ARRIVED,
            ShipmentStatus::CUSTOMS_CLEARED,
            ShipmentStatus::READY_FOR_PICKUP,
            ShipmentStatus::DELIVERED
        ];

        for ($i = 1; $i <= 20; $i++) {
            // Check if shipment already exists
            $manifestNumber = "TMAN" . str_pad($i, 6, '0', STR_PAD_LEFT);
            $existingShipment = $manager->getRepository(ShipmentRecord::class)->findOneBy(['manifestNumber' => $manifestNumber]);
            if ($existingShipment) {
                continue;
            }

            $shipment = new ShipmentRecord();
            
            // Basic shipment info
            $shipment->setManifestNumber($manifestNumber);
            $shipment->setBlNo("TBL" . str_pad($i, 8, '0', STR_PAD_LEFT));
            $shipment->setContainerNumber("TCONT" . str_pad($i, 7, '0', STR_PAD_LEFT));
            
            // Assign to consignees
            if (!empty($consignees)) {
                $consigneeIndex = ($i - 1) % count($consignees);
                $shipment->setConsignee($consignees[$consigneeIndex]);
            }
            
            // Dates
            $baseDate = new \DateTime('-' . rand(1, 90) . ' days');
            $shipment->setNoticeOfArrivalDate($baseDate);
            
            if (rand(0, 1)) {
                $arrivalDate = clone $baseDate;
                $arrivalDate->add(new \DateInterval('P' . rand(1, 7) . 'D'));
                $shipment->setActualArrivalDate($arrivalDate);
            }
            
            // Status
            $statusIndex = ($i - 1) % count($shipmentStatuses);
            $shipment->setStatus($shipmentStatuses[$statusIndex]);
            
            // Optional fields
            if (rand(0, 1)) {
                $shipment->setVessel("Test Vessel " . chr(65 + ($i % 26)));
            }
            
            if (rand(0, 1)) {
                $shipment->setVoyageNumber("TV" . str_pad($i, 4, '0', STR_PAD_LEFT));
            }
            
            // Cargo details
            $shipment->setCargoDescription("Test cargo description for shipment {$i}");
            $shipment->setGrossWeight(rand(1000, 50000));
            $shipment->setNetWeight(rand(800, 45000));
            $shipment->setPackageCount(rand(1, 100));
            $shipment->setPackageType(['Boxes', 'Pallets', 'Containers', 'Bags'][rand(0, 3)]);
            
            // Port information
            $shipment->setPortOfLoading(['Shanghai', 'Singapore', 'Hamburg', 'Rotterdam'][rand(0, 3)]);
            $shipment->setPortOfDischarge('Manila');
            
            // Shipper and consignee info
            $shipment->setShipperName("Test Shipper Company {$i}");
            $shipment->setShipperAddress("Test Shipper Address {$i}");
            
            if (!empty($consignees)) {
                $consigneeIndex = ($i - 1) % count($consignees);
                $shipment->setConsigneeName($consignees[$consigneeIndex]->getBusinessName());
                $shipment->setConsigneeAddress("Test Consignee Address {$i}");
                
                // Notify party (sometimes same as consignee, sometimes broker)
                if ($consignees[$consigneeIndex]->getLinkedBroker() && rand(0, 1)) {
                    $shipment->setNotifyPartyName($consignees[$consigneeIndex]->getLinkedBroker()->getFullName());
                    $shipment->setNotifyPartyAddress("Test Broker Address for {$consignees[$consigneeIndex]->getLinkedBroker()->getFullName()}");
                } else {
                    $shipment->setNotifyPartyName($consignees[$consigneeIndex]->getBusinessName());
                    $shipment->setNotifyPartyAddress("Test Consignee Address {$i}");
                }
                
                // Add some authorized brokers
                if ($consignees[$consigneeIndex]->getLinkedBroker()) {
                    $shipment->addAuthorizedBroker($consignees[$consigneeIndex]->getLinkedBroker());
                }
                
                // Sometimes add additional authorized brokers
                if (rand(0, 2) === 0 && !empty($brokers)) {
                    $additionalBrokerIndex = rand(0, count($brokers) - 1);
                    if ($brokers[$additionalBrokerIndex] !== $consignees[$consigneeIndex]->getLinkedBroker()) {
                        $shipment->addAuthorizedBroker($brokers[$additionalBrokerIndex]);
                    }
                }
            }
            
            $manager->persist($shipment);
        }

        $manager->flush();
        
        echo "Test data created successfully:\n";
        echo "- 5 Test Brokers\n";
        echo "- 10 Test Consignees\n";
        echo "- 5 Test Accreditation Submissions\n";
        echo "- 20 Test Shipment Records\n";
    }
}