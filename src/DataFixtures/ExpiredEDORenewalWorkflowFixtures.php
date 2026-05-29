<?php

namespace App\DataFixtures;

use App\Entity\Billing;
use App\Entity\Container;
use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\RenewalRequestStatus;
use App\Entity\Manifest;
use App\Entity\ShippingLine;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Fixtures for testing the Expired eDO Renewal Workflow
 * 
 * Creates test data for various scenarios:
 * - Expired eDOs with different overdue periods
 * - Renewal requests in different statuses
 * - Detention billings with various amounts
 * - Completed renewal workflows with new eDOs
 * 
 * Requirements: All requirements for testing purposes
 */
class ExpiredEDORenewalWorkflowFixtures extends Fixture implements DependentFixtureInterface
{
    public const EXPIRED_EDO_NO_OVERDUE_REFERENCE = 'expired-edo-no-overdue';
    public const EXPIRED_EDO_5_DAYS_REFERENCE = 'expired-edo-5-days';
    public const EXPIRED_EDO_10_DAYS_REFERENCE = 'expired-edo-10-days';
    public const EXPIRED_EDO_30_DAYS_REFERENCE = 'expired-edo-30-days';
    
    public const RENEWAL_REQUEST_PENDING_REFERENCE = 'renewal-request-pending';
    public const RENEWAL_REQUEST_AWAITING_PAYMENT_REFERENCE = 'renewal-request-awaiting-payment';
    public const RENEWAL_REQUEST_PAYMENT_VERIFIED_REFERENCE = 'renewal-request-payment-verified';
    public const RENEWAL_REQUEST_COMPLETED_REFERENCE = 'renewal-request-completed';
    
    public const DETENTION_BILLING_5_DAYS_REFERENCE = 'detention-billing-5-days';
    public const DETENTION_BILLING_10_DAYS_REFERENCE = 'detention-billing-10-days';

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ContainerEDOWorkflowFixtures::class,
        ];
    }

    public static function getGroups(): array
    {
        return ['test-data', 'renewal-workflow'];
    }

    public function load(ObjectManager $manager): void
    {
        // Get user references
        $broker1 = $this->getReference(UserFixtures::BROKER_1_REFERENCE);
        $broker2 = $this->getReference(UserFixtures::BROKER_2_REFERENCE);
        $consignee1 = $this->getReference(UserFixtures::CONSIGNEE_1_REFERENCE);
        $consignee2 = $this->getReference(UserFixtures::CONSIGNEE_2_REFERENCE);
        $slStaff = $this->getReference(UserFixtures::SL_STAFF_REFERENCE);
        $accounting = $this->getReference(UserFixtures::ACCOUNTING_REFERENCE);

        // Get existing references
        $manifest1 = $this->getReference(ContainerEDOWorkflowFixtures::MANIFEST_1_REFERENCE);
        $manifest2 = $this->getReference(ContainerEDOWorkflowFixtures::MANIFEST_2_REFERENCE);
        $container1 = $this->getReference(ContainerEDOWorkflowFixtures::CONTAINER_1_REFERENCE);
        $container2 = $this->getReference(ContainerEDOWorkflowFixtures::CONTAINER_2_REFERENCE);
        $container3 = $this->getReference(ContainerEDOWorkflowFixtures::CONTAINER_3_REFERENCE);

        // Get shipping line (assuming it exists from other fixtures)
        $shippingLine = $manager->getRepository(ShippingLine::class)->findOneBy([]) 
            ?? $this->createDefaultShippingLine($manager);

        // ========================================
        // Scenario 1: Expired eDO with NO overdue days (expired today)
        // ========================================
        $expiredEdoNoOverdue = new ElectronicDeliveryOrder();
        $expiredEdoNoOverdue->setEdoNumber('EDO-TEST-NO-OVERDUE-001');
        $expiredEdoNoOverdue->setManifest($manifest1);
        $expiredEdoNoOverdue->setShippingLine($shippingLine);
        $expiredEdoNoOverdue->setContainer($container1);
        $expiredEdoNoOverdue->setStatus(EDOStatus::EXPIRED);
        $expiredEdoNoOverdue->setExpiresAt(new \DateTime('today'));
        $expiredEdoNoOverdue->setExpiredDays(0);
        $expiredEdoNoOverdue->setVersion(1);
        $expiredEdoNoOverdue->setPdfPath('/uploads/edo/edo_no_overdue.pdf');
        $expiredEdoNoOverdue->setCyLocation('CY-NORTH');
        $manager->persist($expiredEdoNoOverdue);
        $this->addReference(self::EXPIRED_EDO_NO_OVERDUE_REFERENCE, $expiredEdoNoOverdue);

        // ========================================
        // Scenario 2: Expired eDO with 5 days overdue
        // ========================================
        $expiredEdo5Days = new ElectronicDeliveryOrder();
        $expiredEdo5Days->setEdoNumber('EDO-TEST-5-DAYS-OVERDUE-001');
        $expiredEdo5Days->setManifest($manifest1);
        $expiredEdo5Days->setShippingLine($shippingLine);
        $expiredEdo5Days->setContainer($container2);
        $expiredEdo5Days->setStatus(EDOStatus::EXPIRED);
        $expiredEdo5Days->setExpiresAt(new \DateTime('-5 days'));
        $expiredEdo5Days->setExpiredDays(5);
        $expiredEdo5Days->setVersion(1);
        $expiredEdo5Days->setPdfPath('/uploads/edo/edo_5_days_overdue.pdf');
        $expiredEdo5Days->setCyLocation('CY-NORTH');
        $manager->persist($expiredEdo5Days);
        $this->addReference(self::EXPIRED_EDO_5_DAYS_REFERENCE, $expiredEdo5Days);

        // ========================================
        // Scenario 3: Expired eDO with 10 days overdue
        // ========================================
        $expiredEdo10Days = new ElectronicDeliveryOrder();
        $expiredEdo10Days->setEdoNumber('EDO-TEST-10-DAYS-OVERDUE-001');
        $expiredEdo10Days->setManifest($manifest2);
        $expiredEdo10Days->setShippingLine($shippingLine);
        $expiredEdo10Days->setContainer($container3);
        $expiredEdo10Days->setStatus(EDOStatus::EXPIRED);
        $expiredEdo10Days->setExpiresAt(new \DateTime('-10 days'));
        $expiredEdo10Days->setExpiredDays(10);
        $expiredEdo10Days->setVersion(1);
        $expiredEdo10Days->setPdfPath('/uploads/edo/edo_10_days_overdue.pdf');
        $expiredEdo10Days->setCyLocation('CY-SOUTH');
        $manager->persist($expiredEdo10Days);
        $this->addReference(self::EXPIRED_EDO_10_DAYS_REFERENCE, $expiredEdo10Days);

        // ========================================
        // Scenario 4: Expired eDO with 30 days overdue
        // ========================================
        $expiredEdo30Days = new ElectronicDeliveryOrder();
        $expiredEdo30Days->setEdoNumber('EDO-TEST-30-DAYS-OVERDUE-001');
        $expiredEdo30Days->setManifest($manifest2);
        $expiredEdo30Days->setShippingLine($shippingLine);
        $expiredEdo30Days->setStatus(EDOStatus::EXPIRED);
        $expiredEdo30Days->setExpiresAt(new \DateTime('-30 days'));
        $expiredEdo30Days->setExpiredDays(30);
        $expiredEdo30Days->setVersion(1);
        $expiredEdo30Days->setPdfPath('/uploads/edo/edo_30_days_overdue.pdf');
        $expiredEdo30Days->setCyLocation('CY-SOUTH');
        $manager->persist($expiredEdo30Days);
        $this->addReference(self::EXPIRED_EDO_30_DAYS_REFERENCE, $expiredEdo30Days);

        $manager->flush();

        // ========================================
        // Renewal Request 1: PENDING_REVIEW (no detention charges)
        // ========================================
        $renewalRequestPending = new EDORenewalRequest();
        $renewalRequestPending->setExpiredEdo($expiredEdoNoOverdue);
        $renewalRequestPending->setRequestedBy($broker1);
        $renewalRequestPending->setRequestedAt(new \DateTime('-1 hour'));
        $renewalRequestPending->setEmptyContainerReturnDate(new \DateTime('+3 days 14:00'));
        $renewalRequestPending->setOverdueDays(0);
        $renewalRequestPending->setDetentionChargeAmount(0.0);
        $renewalRequestPending->setStatus(RenewalRequestStatus::PENDING_REVIEW);
        $renewalRequestPending->setAdditionalNotes('Urgent: Need to return container ASAP');
        $manager->persist($renewalRequestPending);
        $this->addReference(self::RENEWAL_REQUEST_PENDING_REFERENCE, $renewalRequestPending);

        // ========================================
        // Renewal Request 2: AWAITING_PAYMENT (5 days overdue)
        // ========================================
        $renewalRequestAwaitingPayment = new EDORenewalRequest();
        $renewalRequestAwaitingPayment->setExpiredEdo($expiredEdo5Days);
        $renewalRequestAwaitingPayment->setRequestedBy($broker1);
        $renewalRequestAwaitingPayment->setRequestedAt(new \DateTime('-2 days'));
        $renewalRequestAwaitingPayment->setEmptyContainerReturnDate(new \DateTime('+5 days 10:00'));
        $renewalRequestAwaitingPayment->setOverdueDays(5);
        $renewalRequestAwaitingPayment->setDetentionChargeAmount(250.00); // 5 days * $50/day
        $renewalRequestAwaitingPayment->setStatus(RenewalRequestStatus::AWAITING_PAYMENT);
        $renewalRequestAwaitingPayment->setAdditionalNotes('Container delayed due to customs clearance');
        $manager->persist($renewalRequestAwaitingPayment);
        $this->addReference(self::RENEWAL_REQUEST_AWAITING_PAYMENT_REFERENCE, $renewalRequestAwaitingPayment);

        // Create detention billing for 5 days overdue
        $detentionBilling5Days = new Billing();
        $detentionBilling5Days->setManifest($manifest1);
        $detentionBilling5Days->setBillingType('detention');
        $detentionBilling5Days->setEdoRenewalRequest($renewalRequestAwaitingPayment);
        $detentionBilling5Days->setDetentionDays(5);
        $detentionBilling5Days->setDetentionRate(50.00);
        $detentionBilling5Days->setFreightCharges(0.0);
        $detentionBilling5Days->setThcCharges(0.0);
        $detentionBilling5Days->setTotalAmount(250.00);
        $detentionBilling5Days->setGeneratedBy($accounting);
        $detentionBilling5Days->setPdfPath('/uploads/billing/detention_5_days.pdf');
        $manager->persist($detentionBilling5Days);
        $this->addReference(self::DETENTION_BILLING_5_DAYS_REFERENCE, $detentionBilling5Days);

        // Link billing to renewal request
        $renewalRequestAwaitingPayment->setDetentionBilling($detentionBilling5Days);

        // ========================================
        // Renewal Request 3: PAYMENT_VERIFIED (10 days overdue)
        // ========================================
        $renewalRequestPaymentVerified = new EDORenewalRequest();
        $renewalRequestPaymentVerified->setExpiredEdo($expiredEdo10Days);
        $renewalRequestPaymentVerified->setRequestedBy($broker2);
        $renewalRequestPaymentVerified->setRequestedAt(new \DateTime('-5 days'));
        $renewalRequestPaymentVerified->setEmptyContainerReturnDate(new \DateTime('+7 days 15:00'));
        $renewalRequestPaymentVerified->setOverdueDays(10);
        $renewalRequestPaymentVerified->setDetentionChargeAmount(500.00); // 10 days * $50/day
        $renewalRequestPaymentVerified->setStatus(RenewalRequestStatus::PAYMENT_VERIFIED);
        $renewalRequestPaymentVerified->setPaymentVerified(true);
        $renewalRequestPaymentVerified->setPaymentVerifiedAt(new \DateTime('-1 day'));
        $renewalRequestPaymentVerified->setPaymentVerifiedBy($accounting);
        $renewalRequestPaymentVerified->setAdditionalNotes('Payment confirmed via bank transfer');
        $manager->persist($renewalRequestPaymentVerified);
        $this->addReference(self::RENEWAL_REQUEST_PAYMENT_VERIFIED_REFERENCE, $renewalRequestPaymentVerified);

        // Create detention billing for 10 days overdue
        $detentionBilling10Days = new Billing();
        $detentionBilling10Days->setManifest($manifest2);
        $detentionBilling10Days->setBillingType('detention');
        $detentionBilling10Days->setEdoRenewalRequest($renewalRequestPaymentVerified);
        $detentionBilling10Days->setDetentionDays(10);
        $detentionBilling10Days->setDetentionRate(50.00);
        $detentionBilling10Days->setFreightCharges(0.0);
        $detentionBilling10Days->setThcCharges(0.0);
        $detentionBilling10Days->setTotalAmount(500.00);
        $detentionBilling10Days->setGeneratedBy($accounting);
        $detentionBilling10Days->setPdfPath('/uploads/billing/detention_10_days.pdf');
        $manager->persist($detentionBilling10Days);
        $this->addReference(self::DETENTION_BILLING_10_DAYS_REFERENCE, $detentionBilling10Days);

        // Link billing to renewal request
        $renewalRequestPaymentVerified->setDetentionBilling($detentionBilling10Days);

        // ========================================
        // Renewal Request 4: COMPLETED (30 days overdue with new eDO)
        // ========================================
        $renewalRequestCompleted = new EDORenewalRequest();
        $renewalRequestCompleted->setExpiredEdo($expiredEdo30Days);
        $renewalRequestCompleted->setRequestedBy($broker2);
        $renewalRequestCompleted->setRequestedAt(new \DateTime('-10 days'));
        $renewalRequestCompleted->setEmptyContainerReturnDate(new \DateTime('+2 days 11:00'));
        $renewalRequestCompleted->setOverdueDays(30);
        $renewalRequestCompleted->setDetentionChargeAmount(1500.00); // 30 days * $50/day
        $renewalRequestCompleted->setStatus(RenewalRequestStatus::COMPLETED);
        $renewalRequestCompleted->setPaymentVerified(true);
        $renewalRequestCompleted->setPaymentVerifiedAt(new \DateTime('-3 days'));
        $renewalRequestCompleted->setPaymentVerifiedBy($accounting);
        $renewalRequestCompleted->setCompletedAt(new \DateTime('-1 day'));
        $renewalRequestCompleted->setAdditionalNotes('Completed renewal with new CY location');
        $manager->persist($renewalRequestCompleted);
        $this->addReference(self::RENEWAL_REQUEST_COMPLETED_REFERENCE, $renewalRequestCompleted);

        // Create detention billing for 30 days overdue
        $detentionBilling30Days = new Billing();
        $detentionBilling30Days->setManifest($manifest2);
        $detentionBilling30Days->setBillingType('detention');
        $detentionBilling30Days->setEdoRenewalRequest($renewalRequestCompleted);
        $detentionBilling30Days->setDetentionDays(30);
        $detentionBilling30Days->setDetentionRate(50.00);
        $detentionBilling30Days->setFreightCharges(0.0);
        $detentionBilling30Days->setThcCharges(0.0);
        $detentionBilling30Days->setTotalAmount(1500.00);
        $detentionBilling30Days->setGeneratedBy($accounting);
        $detentionBilling30Days->setPdfPath('/uploads/billing/detention_30_days.pdf');
        $manager->persist($detentionBilling30Days);

        // Link billing to renewal request
        $renewalRequestCompleted->setDetentionBilling($detentionBilling30Days);

        // Create new eDO for completed renewal
        $newEdo = new ElectronicDeliveryOrder();
        $newEdo->setEdoNumber('EDO-TEST-RENEWED-30-DAYS-002');
        $newEdo->setManifest($manifest2);
        $newEdo->setShippingLine($shippingLine);
        $newEdo->setStatus(EDOStatus::ACTIVE);
        $newEdo->setExpiresAt(new \DateTime('+14 days'));
        $newEdo->setVersion(2);
        $newEdo->setPdfPath('/uploads/edo/edo_renewed_30_days.pdf');
        $newEdo->setCyLocation('CY-EAST');
        $newEdo->setGeneratedByName($slStaff->getFirstName() . ' ' . $slStaff->getLastName());
        $newEdo->setAdditionalNotes('Renewed eDO with updated CY location. Previous eDO expired 30 days ago.');
        $newEdo->setPreviousVersion($expiredEdo30Days);
        $manager->persist($newEdo);

        // Link new eDO to renewal request
        $renewalRequestCompleted->setNewEdo($newEdo);

        $manager->flush();

        echo "\nExpired eDO Renewal Workflow test data created successfully:\n";
        echo "- 4 Expired eDOs (0, 5, 10, 30 days overdue)\n";
        echo "- 4 Renewal Requests (PENDING_REVIEW, AWAITING_PAYMENT, PAYMENT_VERIFIED, COMPLETED)\n";
        echo "- 3 Detention Billings (5, 10, 30 days)\n";
        echo "- 1 Renewed eDO (linked to completed renewal request)\n";
    }

    private function createDefaultShippingLine(ObjectManager $manager): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setName('Test Shipping Line');
        $shippingLine->setCode('TSL');
        $manager->persist($shippingLine);
        $manager->flush();
        return $shippingLine;
    }
}
