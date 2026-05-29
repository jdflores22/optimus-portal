<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\FirebaseService;

#[AsCommand(
    name: 'firebase:migrate',
    description: 'Migrate MySQL data to Firebase Firestore',
)]
class FirebaseMigrateCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FirebaseService $firebaseService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Specific entity to migrate (e.g., User, Manifest)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for migration', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without actually writing to Firebase')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entity = $input->getOption('entity');
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No data will be written to Firebase');
        }

        $io->title('MySQL to Firebase Migration');

        // Define entities to migrate
        $entities = $entity ? [$entity] : [
            'User',
            'ShippingLine',
            'Terminal',
            'Manifest',
            'Container',
            'NOA',
            'ElectronicDeliveryOrder',
            'Payment',
            'Notification',
            'AccreditationSubmission',
            'ActivityLog',
            'AuditLog',
            'Billing',
            'Broker',
            'BrokerTransferRequest',
            'City',
            'ConfigurationHistory',
            'Consignee',
            'ConsigneeBrokerRelationship',
            'ContainerAllocationAudit',
            'ContainerSize',
            'ContainerType',
            'DwellTimeConfiguration',
            'DwellTimeEvent',
            'EDOAccessLog',
            'EDOPayment',
            'EDOReleaseHistory',
            'FormConfiguration',
            'GenerationSession',
            'GeotagPhoto',
            'NOADocument',
            'NotificationDeliveryLog',
            'NotificationMetrics',
            'NotificationPreferences',
            'PaymentFeeConfiguration',
            'PaymentVerification',
            'PendingUser',
            'PreAdviceRequest',
            'PushSubscription',
            'QueuedNotification',
            'ReferralCode',
            'Region',
            'RolePermissionConfiguration',
            'ScheduledReport',
            'ShipmentRecord',
            'ShippingLineConfiguration',
            'ShippingLineTerminalAllocation',
            'StaffUser',
            'StoredFile',
            'SuspensionAppeal',
            'SystemConfiguration',
            'TerminalSlot',
            'TerminalTeamUser',
            'Trucker',
            'UserShippingLinePreference',
            'WorkflowStateHistory',
        ];

        foreach ($entities as $entityName) {
            $io->section("Migrating: $entityName");
            
            try {
                $this->migrateEntity($entityName, $batchSize, $dryRun, $io);
                $io->success("$entityName migrated successfully");
            } catch (\Exception $e) {
                $io->error("Failed to migrate $entityName: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $io->success('Migration completed successfully!');
        return Command::SUCCESS;
    }

    private function migrateEntity(string $entityName, int $batchSize, bool $dryRun, SymfonyStyle $io): void
    {
        $className = "App\\Entity\\$entityName";
        
        if (!class_exists($className)) {
            throw new \RuntimeException("Entity class not found: $className");
        }

        $repository = $this->entityManager->getRepository($className);
        $qb = $repository->createQueryBuilder('e');
        $query = $qb->getQuery();
        
        $totalCount = (int) $qb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $io->text("Total records: $totalCount");

        $progressBar = $io->createProgressBar($totalCount);
        $progressBar->start();

        $offset = 0;
        $operations = [];

        while ($offset < $totalCount) {
            $query->setFirstResult($offset)->setMaxResults($batchSize);
            $entities = $query->getResult();

            foreach ($entities as $entity) {
                $data = $this->entityToArray($entity);
                $collectionName = $this->getCollectionName($entityName);
                $documentId = (string) $entity->getId();

                if (!$dryRun) {
                    $operations[] = [
                        'type' => 'set',
                        'collection' => $collectionName,
                        'documentId' => $documentId,
                        'data' => $data
                    ];

                    // Batch write every 500 operations (Firestore limit)
                    if (count($operations) >= 500) {
                        $this->firebaseService->batchWrite($operations);
                        $operations = [];
                    }
                }

                $progressBar->advance();
            }

            $offset += $batchSize;
            $this->entityManager->clear();
        }

        // Write remaining operations
        if (!$dryRun && count($operations) > 0) {
            $this->firebaseService->batchWrite($operations);
        }

        $progressBar->finish();
        $io->newLine(2);
    }

    private function entityToArray($entity): array
    {
        $data = [];
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($entity);

            // Skip null values
            if ($value === null) {
                continue;
            }

            $propertyName = $property->getName();

            // Handle different data types
            if ($value instanceof \DateTimeInterface) {
                $data[$propertyName] = $value->format('Y-m-d H:i:s');
            } elseif (is_object($value)) {
                // Handle related entities - store only ID
                if (method_exists($value, 'getId')) {
                    $data[$propertyName . '_id'] = $value->getId();
                }
            } elseif (is_array($value) || $value instanceof \Doctrine\Common\Collections\Collection) {
                // Handle collections - store only IDs
                $ids = [];
                foreach ($value as $item) {
                    if (is_object($item) && method_exists($item, 'getId')) {
                        $ids[] = $item->getId();
                    }
                }
                if (!empty($ids)) {
                    $data[$propertyName . '_ids'] = $ids;
                }
            } else {
                $data[$propertyName] = $value;
            }
        }

        return $data;
    }

    private function getCollectionName(string $entityName): string
    {
        // Convert entity name to snake_case collection name
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $entityName));
    }
}
