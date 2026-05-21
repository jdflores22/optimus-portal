<?php

namespace App\DataFixtures;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\PaymentStatus;
use App\Entity\PaymentVerification;
use App\Entity\ShipmentRecord;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ShipmentFixtures extends Fixture implements DependentFixtureInterface
{
    public const SHIPMENT_1_REFERENCE = 'shipment-1';
    public const SHIPMENT_2_REFERENCE = 'shipment-2';
    public const SHIPMENT_3_REFERENCE = 'shipment-3';
    public const SHIPMENT_4_REFERENCE = 'shipment-4';
    public const PAYMENT_1_REFERENCE = 'payment-1';
    public const PAYMENT_2_REFERENCE = 'payment-2';
    public const PAYMENT_3_REFERENCE = 'payment-3';
    public const EDO_1_REFERENCE = 'edo-1';
    public const EDO_2_REFERENCE = 'edo-2';

    public function load(ObjectManager $manager): void
    {
        // Get references to users
        $slStaff = $this->getReference(UserFixtures::SL_STAFF_REFERENCE, \App\Entity\StaffUser::class);
        $broker1 = $this->getReference(UserFixtures::BROKER_1_REFERENCE, \App\Entity\Broker::class);
        $broker2 = $this->getReference(UserFixtures::BROKER_2_REFERENCE, \App\Entity\Broker::class);
        $accounting = $this->getReference(UserFixtures::ACCOUNTING_REFERENCE, \App\Entity\StaffUser::class);

        // Create Shipment 1 - Arrived with verified payment and EDO
        $shipment1 = new ShipmentRecord();
        $shipment1->setManifestNumber('MAN2024001');
        $shipment1->setNoticeOfArrivalDate(new \DateTime('-5 days'));
        $shipment1->setActualArrivalDate(new \DateTime('-3 days'));
        $shipment1->setBillingInformation('Container: TCLU1234567, Size: 40ft, Weight: 25,000kg, Cargo: Electronics, Port Charges: $2,500, Storage: $500, Total: $3,000');
        $shipment1->setCreatedBy($slStaff);
        $shipment1->addAuthorizedBroker($broker1);
        $manager->persist($shipment1);
        $this->addReference(self::SHIPMENT_1_REFERENCE, $shipment1);

        // Create payment verification for shipment 1 (verified)
        $payment1 = new PaymentVerification();
        $payment1->setShipment($shipment1);
        $payment1->setBroker($broker1);
        $payment1->setProofFilePath('/uploads/payment_proof_001.pdf');
        $payment1->setStatus(PaymentStatus::VERIFIED);
        $payment1->setVerifiedBy($accounting);
        $payment1->setVerifiedAt(new \DateTime('-2 days'));
        $manager->persist($payment1);
        $this->addReference(self::PAYMENT_1_REFERENCE, $payment1);

        // Create EDO for payment 1
        $edo1 = new ElectronicDeliveryOrder();
        $edo1->setEdoNumber('EDO' . date('Ymd') . '001');
        $edo1->setEdoPayment($payment1);
        $edo1->setPdfPath('/uploads/edo_' . $edo1->getEdoNumber() . '.pdf');
        $manager->persist($edo1);
        $this->addReference(self::EDO_1_REFERENCE, $edo1);

        // Create Shipment 2 - Arrived with pending payment
        $shipment2 = new ShipmentRecord();
        $shipment2->setManifestNumber('MAN2024002');
        $shipment2->setNoticeOfArrivalDate(new \DateTime('-3 days'));
        $shipment2->setActualArrivalDate(new \DateTime('-1 day'));
        $shipment2->setBillingInformation('Container: MSKU9876543, Size: 20ft, Weight: 15,000kg, Cargo: Textiles, Port Charges: $1,800, Storage: $300, Total: $2,100');
        $shipment2->setCreatedBy($slStaff);
        $shipment2->addAuthorizedBroker($broker1);
        $shipment2->addAuthorizedBroker($broker2);
        $manager->persist($shipment2);
        $this->addReference(self::SHIPMENT_2_REFERENCE, $shipment2);

        // Create payment verification for shipment 2 (pending)
        $payment2 = new PaymentVerification();
        $payment2->setShipment($shipment2);
        $payment2->setBroker($broker2);
        $payment2->setProofFilePath('/uploads/payment_proof_002.pdf');
        $payment2->setStatus(PaymentStatus::PENDING);
        $manager->persist($payment2);
        $this->addReference(self::PAYMENT_2_REFERENCE, $payment2);

        // Create Shipment 3 - Expected arrival (future)
        $shipment3 = new ShipmentRecord();
        $shipment3->setManifestNumber('MAN2024003');
        $shipment3->setNoticeOfArrivalDate(new \DateTime('+2 days'));
        $shipment3->setActualArrivalDate(null);
        $shipment3->setBillingInformation('Container: COSCO123456, Size: 40ft HC, Weight: 28,000kg, Cargo: Machinery, Port Charges: $3,200, Storage: $0, Total: $3,200');
        $shipment3->setCreatedBy($slStaff);
        $shipment3->addAuthorizedBroker($broker1);
        $manager->persist($shipment3);
        $this->addReference(self::SHIPMENT_3_REFERENCE, $shipment3);

        // Create Shipment 4 - Arrived with verified payment and EDO (different broker)
        $shipment4 = new ShipmentRecord();
        $shipment4->setManifestNumber('MAN2024004');
        $shipment4->setNoticeOfArrivalDate(new \DateTime('-7 days'));
        $shipment4->setActualArrivalDate(new \DateTime('-5 days'));
        $shipment4->setBillingInformation('Container: OOLU7654321, Size: 20ft, Weight: 12,000kg, Cargo: Food Products, Port Charges: $1,500, Storage: $200, Total: $1,700');
        $shipment4->setCreatedBy($slStaff);
        $shipment4->addAuthorizedBroker($broker2);
        $manager->persist($shipment4);
        $this->addReference(self::SHIPMENT_4_REFERENCE, $shipment4);

        // Create payment verification for shipment 4 (verified)
        $payment3 = new PaymentVerification();
        $payment3->setShipment($shipment4);
        $payment3->setBroker($broker2);
        $payment3->setProofFilePath('/uploads/payment_proof_003.pdf');
        $payment3->setStatus(PaymentStatus::VERIFIED);
        $payment3->setVerifiedBy($accounting);
        $payment3->setVerifiedAt(new \DateTime('-4 days'));
        $manager->persist($payment3);
        $this->addReference(self::PAYMENT_3_REFERENCE, $payment3);

        // Create EDO for payment 3
        $edo2 = new ElectronicDeliveryOrder();
        $edo2->setEdoNumber('EDO' . date('Ymd') . '002');
        $edo2->setEdoPayment($payment3);
        $edo2->setPdfPath('/uploads/edo_' . $edo2->getEdoNumber() . '.pdf');
        $manager->persist($edo2);
        $this->addReference(self::EDO_2_REFERENCE, $edo2);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}