<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\PaymentVerification;
use App\Entity\ShipmentRecord;
use App\Entity\StaffUser;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\UserRole;
use App\Service\AuditService;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 15: EDO access logging
 * 
 * For any EDO access by a user, the access should be recorded in the audit log
 * with user identifier, timestamp, and EDO identifier.
 * 
 * Validates: Requirements 7.5
 */
class EDOAccessLoggingTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private AuditService $auditService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->auditService = $container->get(AuditService::class);
        $this->paymentService = $container->get(PaymentService::class);

        // Configure Eris
        $this->minimumEvaluationRatio = 0.5;
        $this->iterations = 10; // Reduced for faster execution

        // Begin transaction for test isolation
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * Property: EDO access creates audit log entry
     * 
     * For any user accessing an EDO, an audit log entry should be created
     * with the correct user, EDO identifier, and action type "access".
     */
    public function testEDOAccessCreatesAuditLog(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'edoNumber' => Generator\map(
                    fn($n) => 'EDO-' . str_pad($n, 8, '0', STR_PAD_LEFT),
                    Generator\nat()
                )
            ])
        )->then(function ($data) {
            // Create a user who will access the EDO
            $email = "user{$data['emailSuffix']}" . uniqid() . "@test.com";
            $user = $this->createStaffUser($email);

            // Create an EDO
            $edo = $this->createEDO($data['edoNumber']);

            // Count audit logs before access
            $auditLogsBefore = count($this->auditService->getAuditTrail('EDO', $edo->getId()));

            // Access the EDO
            $accessedEdo = $this->paymentService->accessEDO($edo->getId(), $user);

            // Count audit logs after access
            $auditLogsAfter = count($this->auditService->getAuditTrail('EDO', $edo->getId()));

            // Assert that an audit log was created
            $this->assertNotNull($accessedEdo, 'EDO should be returned');
            $this->assertEquals($auditLogsBefore + 1, $auditLogsAfter, 'Exactly one audit log should be added for EDO access');

            // Retrieve the audit trail and verify the latest entry
            $auditTrail = $this->auditService->getAuditTrail('EDO', $edo->getId());
            $this->assertNotEmpty($auditTrail, 'Audit trail should not be empty');

            $latestLog = $auditTrail[0]; // First entry is the most recent (DESC order)
            $this->assertEquals($user->getId(), $latestLog->getUser()->getId(), 'Audit log should reference the correct user');
            $this->assertEquals('access', $latestLog->getAction(), 'Audit log action should be "access"');
            $this->assertEquals('EDO', $latestLog->getEntityType(), 'Audit log should reference EDO entity type');
            $this->assertEquals($edo->getId(), $latestLog->getEntityId(), 'Audit log should reference the correct EDO ID');
            $this->assertNotNull($latestLog->getTimestamp(), 'Audit log should have a timestamp');
            $this->assertNotNull($latestLog->getRelatedEdo(), 'Audit log should be linked to the EDO');
            $this->assertEquals($edo->getId(), $latestLog->getRelatedEdo()->getId(), 'Audit log should be linked to the correct EDO');
        });
    }

    /**
     * Property: Multiple EDO accesses create multiple audit logs
     * 
     * For any EDO accessed multiple times, each access should create a separate
     * audit log entry.
     */
    public function testMultipleEDOAccessesCreateMultipleLogs(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'edoNumber' => Generator\map(
                    fn($n) => 'EDO-' . str_pad($n, 8, '0', STR_PAD_LEFT),
                    Generator\nat()
                ),
                'numAccesses' => Generator\choose(2, 5)
            ])
        )->then(function ($data) {
            // Create a user
            $email = "user{$data['emailSuffix']}" . uniqid() . "@test.com";
            $user = $this->createStaffUser($email);

            // Create an EDO
            $edo = $this->createEDO($data['edoNumber']);

            // Count audit logs before accesses
            $auditLogsBefore = count($this->auditService->getAuditTrail('EDO', $edo->getId()));

            // Access the EDO multiple times
            for ($i = 0; $i < $data['numAccesses']; $i++) {
                $this->paymentService->accessEDO($edo->getId(), $user);
                usleep(1000); // Small delay to ensure different timestamps
            }

            // Count audit logs after accesses
            $auditLogsAfter = count($this->auditService->getAuditTrail('EDO', $edo->getId()));

            // Assert that the correct number of audit logs were created
            $this->assertEquals(
                $auditLogsBefore + $data['numAccesses'],
                $auditLogsAfter,
                "Exactly {$data['numAccesses']} audit logs should be added for {$data['numAccesses']} accesses"
            );

            // Verify all logs are access logs
            $auditTrail = $this->auditService->getAuditTrail('EDO', $edo->getId());
            $accessLogs = array_filter($auditTrail, fn($log) => $log->getAction() === 'access');
            $this->assertGreaterThanOrEqual(
                $data['numAccesses'],
                count($accessLogs),
                'All access operations should be logged as "access" actions'
            );
        });
    }

    /**
     * Property: Different users accessing same EDO create separate logs
     * 
     * For any EDO accessed by different users, each user's access should create
     * a separate audit log entry with the correct user identifier.
     */
    public function testDifferentUsersAccessingSameEDOCreateSeparateLogs(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix1' => Generator\nat(),
                'emailSuffix2' => Generator\nat(),
                'edoNumber' => Generator\map(
                    fn($n) => 'EDO-' . str_pad($n, 8, '0', STR_PAD_LEFT),
                    Generator\nat()
                )
            ])
        )->then(function ($data) {
            // Create two different users
            $email1 = "user{$data['emailSuffix1']}" . uniqid() . "@test.com";
            $email2 = "user{$data['emailSuffix2']}" . uniqid() . "@test.com";
            $user1 = $this->createStaffUser($email1);
            $user2 = $this->createStaffUser($email2);

            // Create an EDO
            $edo = $this->createEDO($data['edoNumber']);

            // Both users access the EDO
            $this->paymentService->accessEDO($edo->getId(), $user1);
            usleep(1000);
            $this->paymentService->accessEDO($edo->getId(), $user2);

            // Retrieve audit trail
            $auditTrail = $this->auditService->getAuditTrail('EDO', $edo->getId());

            // Find logs for each user
            $user1Logs = array_filter($auditTrail, fn($log) => $log->getUser()->getId() === $user1->getId());
            $user2Logs = array_filter($auditTrail, fn($log) => $log->getUser()->getId() === $user2->getId());

            // Assert both users have audit logs
            $this->assertNotEmpty($user1Logs, 'User 1 should have audit logs for EDO access');
            $this->assertNotEmpty($user2Logs, 'User 2 should have audit logs for EDO access');

            // Assert logs are correctly attributed
            foreach ($user1Logs as $log) {
                $this->assertEquals($user1->getId(), $log->getUser()->getId(), 'User 1 logs should reference User 1');
            }
            foreach ($user2Logs as $log) {
                $this->assertEquals($user2->getId(), $log->getUser()->getId(), 'User 2 logs should reference User 2');
            }
        });
    }

    /**
     * Helper: Create a staff user
     */
    private function createStaffUser(string $email): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail($email);
        $user->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Testing');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Helper: Create an EDO with associated entities
     */
    private function createEDO(string $edoNumber): ElectronicDeliveryOrder
    {
        // Create a broker
        $broker = new Broker();
        $broker->setEmail('broker' . uniqid() . '@test.com');
        $broker->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $broker->setRole(UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $broker->setBusinessName('Test Broker');
        $this->entityManager->persist($broker);

        // Create a consignee
        $consignee = new Consignee();
        $consignee->setEmail('consignee' . uniqid() . '@test.com');
        $consignee->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setBusinessName('Test Consignee');
        $consignee->setLinkedBroker($broker);
        $this->entityManager->persist($consignee);

        // Create a staff user
        $staff = new StaffUser();
        $staff->setEmail('staff' . uniqid() . '@test.com');
        $staff->setPasswordHash(password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]));
        $staff->setRole(UserRole::SL_STAFF);
        $staff->setStatus(AccountStatus::APPROVED);
        $staff->setFirstName('Staff');
        $staff->setLastName('User');
        $staff->setDepartment('Operations');
        $this->entityManager->persist($staff);

        // Create a shipment
        $shipment = new ShipmentRecord();
        $shipment->setManifestNumber('MAN-' . uniqid());
        $shipment->setNoticeOfArrivalDate(new \DateTime());
        $shipment->setBillingInformation('Test billing info');
        $shipment->setCreatedBy($staff);
        $this->entityManager->persist($shipment);

        // Create a payment verification
        $payment = new PaymentVerification();
        $payment->setShipment($shipment);
        $payment->setBroker($broker);
        $payment->setProofFilePath('/path/to/proof.pdf');
        $payment->setStatus(PaymentStatus::VERIFIED);
        $payment->setVerifiedBy($staff);
        $payment->setVerifiedAt(new \DateTime());
        $this->entityManager->persist($payment);

        // Create an EDO
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber($edoNumber . '-' . uniqid());
        $edo->setPayment($payment);
        $edo->setPdfPath('/path/to/edo.pdf');
        $this->entityManager->persist($edo);

        $this->entityManager->flush();

        return $edo;
    }
}
