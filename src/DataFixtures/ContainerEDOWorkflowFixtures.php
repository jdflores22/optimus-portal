<?php

namespace App\DataFixtures;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ContainerType;
use App\Entity\EDOAuditLog;
use App\Entity\EDOBilling;
use App\Entity\EDOPaymentReceipt;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\AuditEventType;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\RequestStatus;
use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\RegenerationRequest;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ContainerEDOWorkflowFixtures extends Fixture implements DependentFixtureInterface
{
    public const CONTAINER_TYPE_20FT = 'container-type-20ft';
    public const CONTAINER_TYPE_40FT = 'container-type-40ft';
    public const CONTAINER_SIZE_STANDARD = 'container-size-standard';
    public const CONTAINER_SIZE_HIGH_CUBE = 'container-size-high-cube';
    
    public const NOA_1_REFERENCE = 'noa-1';
    public const NOA_2_REFERENCE = 'noa-2';
    public const MANIFEST_1_REFERENCE = 'manifest-1';
    public const MANIFEST_2_REFERENCE = 'manifest-2';
    public const CONTAINER_1_REFERENCE = 'container-1';
    public const CONTAINER_2_REFERENCE = 'container-2';
    public const CONTAINER_3_REFERENCE = 'container-3';
    public const EDO_1_REFERENCE = 'edo-1';
    public const EDO_2_REFERENCE = 'edo-2';
    public const EDO_3_EXPIRED_REFERENCE = 'edo-3-expired';

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        // Create ContainerType reference data
        $type20ft = new ContainerType();
        $type20ft->setName('20ft Standard');
        $type20ft->setCode('20ST');
        $manager->persist($type20ft);
        $this->addReference(self::CONTAINER_TYPE_20FT, $type20ft);

        $type40ft = new ContainerType();
        $type40ft->setName('40ft Standard');
        $type40ft->setCode('40ST');
        $manager->persist($type40ft);
        $this->addReference(self::CONTAINER_TYPE_40FT, $type40ft);

        // Create ContainerSize reference data
        $sizeStandard = new ContainerSize();
        $sizeStandard->setName('Standard');
        $sizeStandard->setTeuValue(1.0);
        $manager->persist($sizeStandard);
        $this->addReference(self::CONTAINER_SIZE_STANDARD, $sizeStandard);

        $sizeHighCube = new ContainerSize();
        $sizeHighCube->setName('High Cube');
        $sizeHighCube->setTeuValue(2.0);
        $manager->persist($sizeHighCube);
        $this->addReference(self::CONTAINER_SIZE_HIGH_CUBE, $sizeHighCube);

        $manager->flush();

        // Get user references
        $terminalUser = $this->getReference(UserFixtures::SL_STAFF_REFERENCE);
        $consignee1 = $this->getReference(UserFixtures::CONSIGNEE_1_REFERENCE);
        $consignee2 = $this->getReference(UserFixtures::CONSIGNEE_2_REFERENCE);
        $broker1 = $this->getReference(UserFixtures::BROKER_1_REFERENCE);
        $accountingUser = $this->getReference(UserFixtures::ACCOUNTING_REFERENCE);

        // Create NOA 1
        $noa1 = new NOA();
        $noa1->setNoaNumber('NOA-20260420-0001');
        $noa1->setBlNumber('BL123456789');
        $noa1->setVesselNumber('VESSEL-001');
        $noa1->setEta(new \DateTime('+7 days'));
        $noa1->setCyLocation('CY-NORTH');
        $noa1->setConsignee($consignee1);
        $noa1->setCreatedBy($terminalUser);
        $noa1->setCreatedAt(new \DateTime());
        $noa1->setUpdatedAt(new \DateTime());
        $manager->persist($noa1);
        $this->addReference(self::NOA_1_REFERENCE, $noa1);

        // Create NOA 2
        $noa2 = new NOA();
        $noa2->setNoaNumber('NOA-20260420-0002');
        $noa2->setBlNumber('BL987654321');
        $noa2->setVesselNumber('VESSEL-002');
        $noa2->setEta(new \DateTime('+10 days'));
        $noa2->setCyLocation('CY-SOUTH');
        $noa2->setConsignee($consignee2);
        $noa2->setCreatedBy($terminalUser);
        $noa2->setCreatedAt(new \DateTime());
        $noa2->setUpdatedAt(new \DateTime());
        $manager->persist($noa2);
        $this->addReference(self::NOA_2_REFERENCE, $noa2);

        $manager->flush();

        // Create Containers for NOA 1
        $container1 = new Container();
        $container1->setContainerNumber('CONT-001-TEST');
        $container1->setStatus(ContainerStatus::IN_TRANSIT);
        $container1->setContainerType($type20ft);
        $container1->setContainerSize($sizeStandard);
        $container1->setNoa($noa1);
        $container1->setCreatedAt(new \DateTime());
        $container1->setUpdatedAt(new \DateTime());
        $container1->setExpectedReturnDate(new \DateTime('+30 days'));
        $manager->persist($container1);
        $this->addReference(self::CONTAINER_1_REFERENCE, $container1);

        $container2 = new Container();
        $container2->setContainerNumber('CONT-002-TEST');
        $container2->setStatus(ContainerStatus::IN_TRANSIT);
        $container2->setContainerType($type40ft);
        $container2->setContainerSize($sizeHighCube);
        $container2->setNoa($noa1);
        $container2->setCreatedAt(new \DateTime());
        $container2->setUpdatedAt(new \DateTime());
        $container2->setExpectedReturnDate(new \DateTime('+30 days'));
        $manager->persist($container2);
        $this->addReference(self::CONTAINER_2_REFERENCE, $container2);

        // Create Container for NOA 2 (will have expired eDO)
        $container3 = new Container();
        $container3->setContainerNumber('CONT-003-TEST');
        $container3->setStatus(ContainerStatus::IN_TRANSIT);
        $container3->setContainerType($type20ft);
        $container3->setContainerSize($sizeStandard);
        $container3->setNoa($noa2);
        $container3->setCreatedAt(new \DateTime());
        $container3->setUpdatedAt(new \DateTime());
        $container3->setExpectedReturnDate(new \DateTime('+30 days'));
        $manager->persist($container3);
        $this->addReference(self::CONTAINER_3_REFERENCE, $container3);

        $manager->flush();

        // Create Manifest 1
        $manifest1 = new Manifest();
        $manifest1->setManifestNumber('MAN-20260420-0001');
        $manifest1->setNoa($noa1);
        $manifest1->setBlNumber('BL123456789');
        $manifest1->setBlFilePath('/uploads/bl/bl_123456789.pdf');
        $manifest1->setConsignee($consignee1);
        $manifest1->setBroker($broker1);
        $manifest1->setCreatedBy($broker1);
        $manifest1->setCreatedAt(new \DateTime());
        $manager->persist($manifest1);
        $this->addReference(self::MANIFEST_1_REFERENCE, $manifest1);

        // Link containers to manifest
        $container1->setManifest($manifest1);
        $container2->setManifest($manifest1);

        // Create Manifest 2
        $manifest2 = new Manifest();
        $manifest2->setManifestNumber('MAN-20260420-0002');
        $manifest2->setNoa($noa2);
        $manifest2->setBlNumber('BL987654321');
        $manifest2->setBlFilePath('/uploads/bl/bl_987654321.pdf');
        $manifest2->setConsignee($consignee2);
        $manifest2->setBroker($broker1);
        $manifest2->setCreatedBy($broker1);
        $manifest2->setCreatedAt(new \DateTime());
        $manager->persist($manifest2);
        $this->addReference(self::MANIFEST_2_REFERENCE, $manifest2);

        $container3->setManifest($manifest2);

        $manager->flush();

        // Create Active eDO 1
        $edo1 = new ElectronicDeliveryOrder();
        $edo1->setEdoNumber('EDO-20260420-CONT001-0001');
        $edo1->setContainer($container1);
        $edo1->setManifest($manifest1);
        $edo1->setStatus(EDOStatus::ACTIVE);
        $edo1->setGeneratedAt(new \DateTime());
        $edo1->setExpiresAt(new \DateTime('+14 days'));
        $edo1->setVersion(1);
        $manager->persist($edo1);
        $this->addReference(self::EDO_1_REFERENCE, $edo1);

        // Create Active eDO 2
        $edo2 = new ElectronicDeliveryOrder();
        $edo2->setEdoNumber('EDO-20260420-CONT002-0001');
        $edo2->setContainer($container2);
        $edo2->setManifest($manifest1);
        $edo2->setStatus(EDOStatus::ACTIVE);
        $edo2->setGeneratedAt(new \DateTime());
        $edo2->setExpiresAt(new \DateTime('+14 days'));
        $edo2->setVersion(1);
        $manager->persist($edo2);
        $this->addReference(self::EDO_2_REFERENCE, $edo2);

        // Create Expired eDO 3 (expired 5 days ago)
        $edo3 = new ElectronicDeliveryOrder();
        $edo3->setEdoNumber('EDO-20260420-CONT003-0001');
        $edo3->setContainer($container3);
        $edo3->setManifest($manifest2);
        $edo3->setStatus(EDOStatus::EXPIRED);
        $edo3->setGeneratedAt(new \DateTime('-19 days'));
        $edo3->setExpiresAt(new \DateTime('-5 days'));
        $edo3->setExpiredDays(5);
        $edo3->setVersion(1);
        $manager->persist($edo3);
        $this->addReference(self::EDO_3_EXPIRED_REFERENCE, $edo3);

        $manager->flush();

        // Create audit logs for eDO creation
        $auditLog1 = new EDOAuditLog();
        $auditLog1->setEdo($edo1);
        $auditLog1->setContainer($container1);
        $auditLog1->setEventType(AuditEventType::EDO_CREATED);
        $auditLog1->setUser($broker1);
        $auditLog1->setDetails(['edo_number' => $edo1->getEdoNumber(), 'manifest_number' => $manifest1->getManifestNumber()]);
        $auditLog1->setTimestamp(new \DateTime());
        $manager->persist($auditLog1);

        $auditLog2 = new EDOAuditLog();
        $auditLog2->setEdo($edo2);
        $auditLog2->setContainer($container2);
        $auditLog2->setEventType(AuditEventType::EDO_CREATED);
        $auditLog2->setUser($broker1);
        $auditLog2->setDetails(['edo_number' => $edo2->getEdoNumber(), 'manifest_number' => $manifest1->getManifestNumber()]);
        $auditLog2->setTimestamp(new \DateTime());
        $manager->persist($auditLog2);

        $auditLog3 = new EDOAuditLog();
        $auditLog3->setEdo($edo3);
        $auditLog3->setContainer($container3);
        $auditLog3->setEventType(AuditEventType::EDO_CREATED);
        $auditLog3->setUser($broker1);
        $auditLog3->setDetails(['edo_number' => $edo3->getEdoNumber(), 'manifest_number' => $manifest2->getManifestNumber()]);
        $auditLog3->setTimestamp(new \DateTime('-19 days'));
        $manager->persist($auditLog3);

        // Create audit log for expiration
        $auditLog4 = new EDOAuditLog();
        $auditLog4->setEdo($edo3);
        $auditLog4->setContainer($container3);
        $auditLog4->setEventType(AuditEventType::EDO_EXPIRED);
        $auditLog4->setUser($terminalUser);
        $auditLog4->setDetails(['edo_number' => $edo3->getEdoNumber(), 'expired_days' => 5]);
        $auditLog4->setTimestamp(new \DateTime('-5 days'));
        $manager->persist($auditLog4);

        // Create regeneration request for expired eDO
        $regenRequest = new RegenerationRequest();
        $regenRequest->setEdo($edo3);
        $regenRequest->setRequester($consignee2);
        $regenRequest->setStatus(RequestStatus::BILLING_GENERATED);
        $regenRequest->setRequestedAt(new \DateTime('-4 days'));
        $regenRequest->setRoutedToAccountingAt(new \DateTime('-3 days'));
        $regenRequest->setNotes('Need to regenerate eDO for container pickup');
        $manager->persist($regenRequest);

        // Create audit log for regeneration request
        $auditLog5 = new EDOAuditLog();
        $auditLog5->setEdo($edo3);
        $auditLog5->setContainer($container3);
        $auditLog5->setEventType(AuditEventType::REGENERATION_REQUESTED);
        $auditLog5->setUser($consignee2);
        $auditLog5->setDetails(['edo_number' => $edo3->getEdoNumber(), 'notes' => $regenRequest->getNotes()]);
        $auditLog5->setTimestamp(new \DateTime('-4 days'));
        $manager->persist($auditLog5);

        $manager->flush();

        // Create billing for expired eDO
        $billing = new EDOBilling();
        $billing->setRegenerationRequest($regenRequest);
        $billing->setExpiredDays(5);
        $billing->setPerDayRate(50.00);
        $billing->setTotalAmount(250.00);
        $billing->setBillingDocumentPath('/uploads/billing/billing_edo3.pdf');
        $billing->setGeneratedBy($accountingUser);
        $billing->setCreatedAt(new \DateTime('-3 days'));
        $manager->persist($billing);

        // Create audit log for billing generation
        $auditLog6 = new EDOAuditLog();
        $auditLog6->setEdo($edo3);
        $auditLog6->setContainer($container3);
        $auditLog6->setEventType(AuditEventType::BILLING_GENERATED);
        $auditLog6->setUser($accountingUser);
        $auditLog6->setDetails([
            'edo_number' => $edo3->getEdoNumber(),
            'expired_days' => 5,
            'per_day_rate' => 50.00,
            'total_amount' => 250.00
        ]);
        $auditLog6->setTimestamp(new \DateTime('-3 days'));
        $manager->persist($auditLog6);

        // Create payment receipt (submitted but not yet confirmed)
        $payment = new EDOPaymentReceipt();
        $payment->setBilling($billing);
        $payment->setReceiptFilePath('/uploads/receipts/receipt_edo3.pdf');
        $payment->setSubmittedBy($consignee2);
        $payment->setSubmittedAt(new \DateTime('-2 days'));
        $payment->setStatus(PaymentStatus::SUBMITTED);
        $manager->persist($payment);

        // Create audit log for payment submission
        $auditLog7 = new EDOAuditLog();
        $auditLog7->setEdo($edo3);
        $auditLog7->setContainer($container3);
        $auditLog7->setEventType(AuditEventType::PAYMENT_SUBMITTED);
        $auditLog7->setUser($consignee2);
        $auditLog7->setDetails([
            'edo_number' => $edo3->getEdoNumber(),
            'amount' => 250.00,
            'receipt_path' => $payment->getReceiptFilePath()
        ]);
        $auditLog7->setTimestamp(new \DateTime('-2 days'));
        $manager->persist($auditLog7);

        $manager->flush();
    }
}
