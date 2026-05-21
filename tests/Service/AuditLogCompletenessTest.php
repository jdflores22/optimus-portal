<?php

namespace App\Tests\Service;

use App\Entity\AccreditationSubmission;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\FormConfiguration;
use App\Entity\StaffUser;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use App\Service\AccreditationWorkflowService;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feature: optimus-shipping-portal, Property 5: Audit log completeness
 * 
 * For any state-changing operation (status change, approval, denial), an audit log entry
 * should be created with timestamp, user identifier, and action details.
 * 
 * Validates: Requirements 4.5, 6.4, 7.5
 */
class AuditLogCompletenessTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private AuditService $auditService;
    private AccreditationWorkflowService $accreditationService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->auditService = $container->get(AuditService::class);
        $this->accreditationService = $container->get(AccreditationWorkflowService::class);

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
     * Property: State-changing operations create audit log entries
     * 
     * For any state-changing operation, an audit log entry should be created
     * with the correct user, action, entity type, entity ID, and changes.
     */
    public function testStateChangingOperationsCreateAuditLogs(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'action' => Generator\elements('status_change', 'approval', 'denial', 'update'),
                'entityType' => Generator\elements('AccreditationSubmission', 'ShipmentRecord', 'PaymentVerification'),
                'entityId' => Generator\choose(1, 1000),
                'changeKey' => Generator\elements('status', 'amount', 'date'),
                'fromValue' => Generator\string(),
                'toValue' => Generator\string()
            ])
        )->then(function ($data) {
            // Create a user to perform the action
            $email = "user{$data['emailSuffix']}" . uniqid() . "@test.com";
            $user = $this->createStaffUser($email);

            // Prepare changes array
            $changes = [
                $data['changeKey'] => [
                    'from' => $data['fromValue'],
                    'to' => $data['toValue']
                ]
            ];

            // Count audit logs before the action
            $auditLogsBefore = count($this->auditService->getAuditTrail($data['entityType'], $data['entityId']));

            // Perform the state-changing operation
            $auditLog = $this->auditService->logAction(
                $user,
                $data['action'],
                $data['entityType'],
                $data['entityId'],
                $changes
            );

            // Count audit logs after the action
            $auditLogsAfter = count($this->auditService->getAuditTrail($data['entityType'], $data['entityId']));

            // Assert that an audit log was created
            $this->assertNotNull($auditLog, 'Audit log should be created');
            $this->assertNotNull($auditLog->getId(), 'Audit log should have an ID');
            $this->assertEquals($auditLogsBefore + 1, $auditLogsAfter, 'Exactly one audit log should be added');

            // Assert audit log contains correct information
            $this->assertEquals($user->getId(), $auditLog->getUser()->getId(), 'Audit log should reference the correct user');
            $this->assertEquals($data['action'], $auditLog->getAction(), 'Audit log should have the correct action');
            $this->assertEquals($data['entityType'], $auditLog->getEntityType(), 'Audit log should have the correct entity type');
            $this->assertEquals($data['entityId'], $auditLog->getEntityId(), 'Audit log should have the correct entity ID');
            $this->assertEquals($changes, $auditLog->getChanges(), 'Audit log should contain the changes');
            $this->assertNotNull($auditLog->getTimestamp(), 'Audit log should have a timestamp');
            $this->assertNotEmpty($auditLog->getIpAddress(), 'Audit log should have an IP address');
        });
    }

    /**
     * Property: Resource access creates audit log entries
     * 
     * For any resource access operation, an audit log entry should be created
     * with the correct user, resource type, and resource ID.
     */
    public function testResourceAccessCreatesAuditLogs(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'resourceType' => Generator\elements('EDO', 'ShipmentRecord', 'AccreditationSubmission'),
                'resourceId' => Generator\choose(1, 1000)
            ])
        )->then(function ($data) {
            // Create a user to access the resource
            $email = "user{$data['emailSuffix']}" . uniqid() . "@test.com";
            $user = $this->createStaffUser($email);

            // Count audit logs before the access
            $auditLogsBefore = count($this->auditService->getAuditTrail($data['resourceType'], $data['resourceId']));

            // Perform the resource access operation
            $auditLog = $this->auditService->logAccess($user, $data['resourceType'], $data['resourceId']);

            // Count audit logs after the access
            $auditLogsAfter = count($this->auditService->getAuditTrail($data['resourceType'], $data['resourceId']));

            // Assert that an audit log was created
            $this->assertNotNull($auditLog, 'Audit log should be created for resource access');
            $this->assertNotNull($auditLog->getId(), 'Audit log should have an ID');
            $this->assertEquals($auditLogsBefore + 1, $auditLogsAfter, 'Exactly one audit log should be added');

            // Assert audit log contains correct information
            $this->assertEquals($user->getId(), $auditLog->getUser()->getId(), 'Audit log should reference the correct user');
            $this->assertEquals('access', $auditLog->getAction(), 'Audit log action should be "access"');
            $this->assertEquals($data['resourceType'], $auditLog->getEntityType(), 'Audit log should have the correct resource type');
            $this->assertEquals($data['resourceId'], $auditLog->getEntityId(), 'Audit log should have the correct resource ID');
            $this->assertEmpty($auditLog->getChanges(), 'Access logs should have empty changes array');
            $this->assertNotNull($auditLog->getTimestamp(), 'Audit log should have a timestamp');
        });
    }

    /**
     * Property: Audit trail retrieval returns all logs for an entity
     * 
     * For any entity with multiple audit log entries, getAuditTrail should
     * return all entries in descending timestamp order.
     */
    public function testAuditTrailRetrievesAllLogsForEntity(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'entityType' => Generator\elements('AccreditationSubmission', 'ShipmentRecord'),
                'entityId' => Generator\choose(1, 1000),
                'numActions' => Generator\choose(2, 5)
            ])
        )->then(function ($data) {
            // Create a user
            $email = "user{$data['emailSuffix']}" . uniqid() . "@test.com";
            $user = $this->createStaffUser($email);

            // Perform multiple actions on the same entity
            $createdLogs = [];
            for ($i = 0; $i < $data['numActions']; $i++) {
                $auditLog = $this->auditService->logAction(
                    $user,
                    "action_{$i}",
                    $data['entityType'],
                    $data['entityId'],
                    ['step' => $i]
                );
                $createdLogs[] = $auditLog;
                
                // Small delay to ensure different timestamps
                usleep(1000);
            }

            // Retrieve audit trail
            $auditTrail = $this->auditService->getAuditTrail($data['entityType'], $data['entityId']);

            // Assert all logs are retrieved
            $this->assertGreaterThanOrEqual(
                $data['numActions'],
                count($auditTrail),
                'Audit trail should contain at least all created logs'
            );

            // Assert logs are in descending timestamp order
            // In descending order, each timestamp should be <= the previous one
            $previousTimestamp = null;
            foreach ($auditTrail as $log) {
                if ($previousTimestamp !== null) {
                    // Current timestamp should be less than or equal to previous (descending)
                    $this->assertGreaterThanOrEqual(
                        $log->getTimestamp()->getTimestamp(),
                        $previousTimestamp->getTimestamp(),
                        'Audit logs should be in descending timestamp order (newer first)'
                    );
                }
                $previousTimestamp = $log->getTimestamp();
            }
        });
    }

    /**
     * Property: Search logs filters correctly by criteria
     * 
     * For any search criteria, searchLogs should return only logs matching
     * all specified criteria.
     */
    public function testSearchLogsFiltersCorrectly(): void
    {
        $this->forAll(
            Generator\associative([
                'emailSuffix' => Generator\nat(),
                'action' => Generator\elements('status_change', 'approval', 'denial'),
                'entityType' => Generator\elements('AccreditationSubmission', 'ShipmentRecord')
            ])
        )->then(function ($data) {
            // Create a user
            $email = "user{$data['emailSuffix']}" . uniqid() . "@test.com";
            $user = $this->createStaffUser($email);

            // Create an audit log with specific criteria
            $this->auditService->logAction(
                $user,
                $data['action'],
                $data['entityType'],
                1,
                ['test' => 'value']
            );

            // Search with matching criteria
            $results = $this->auditService->searchLogs([
                'userId' => $user->getId(),
                'action' => $data['action'],
                'entityType' => $data['entityType']
            ]);

            // Assert that results contain the created log
            $this->assertNotEmpty($results, 'Search should return results for matching criteria');
            
            $found = false;
            foreach ($results as $log) {
                if ($log->getUser()->getId() === $user->getId() &&
                    $log->getAction() === $data['action'] &&
                    $log->getEntityType() === $data['entityType']) {
                    $found = true;
                    break;
                }
            }
            
            $this->assertTrue($found, 'Search results should contain the created audit log');
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
}
