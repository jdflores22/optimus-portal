<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;

#[AsCommand(
    name: 'firebase:export-data',
    description: 'Export MySQL data to JSON files for Firebase migration',
)]
class FirebaseExportDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Output directory for JSON files', 'var/firebase-export')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Specific entity to export')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = $this->projectDir . '/' . $input->getOption('output-dir');
        $entity = $input->getOption('entity');

        $io->title('Export MySQL Data to JSON');

        // Create output directory
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            $io->text("Created output directory: $outputDir");
        }

        // Define entities to export
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
            $io->section("Exporting: $entityName");
            
            try {
                $count = $this->exportEntity($entityName, $outputDir, $io);
                $io->success("Exported $count records from $entityName");
            } catch (\Exception $e) {
                $io->error("Failed to export $entityName: " . $e->getMessage());
            }
        }

        $io->success("Export completed! Files saved to: $outputDir");
        return Command::SUCCESS;
    }

    private function exportEntity(string $entityName, string $outputDir, SymfonyStyle $io): int
    {
        $className = "App\\Entity\\$entityName";
        
        if (!class_exists($className)) {
            throw new \RuntimeException("Entity class not found: $className");
        }

        $repository = $this->entityManager->getRepository($className);
        $entities = $repository->findAll();
        
        $data = [];
        foreach ($entities as $entity) {
            $data[] = $this->entityToArray($entity);
        }

        $filename = $outputDir . '/' . strtolower($entityName) . '.json';
        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));

        return count($data);
    }

    private function entityToArray($entity): array
    {
        $data = ['id' => $entity->getId()];
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($entity);

            if ($value === null) {
                continue;
            }

            $propertyName = $property->getName();

            if ($value instanceof \DateTimeInterface) {
                $data[$propertyName] = $value->format('Y-m-d H:i:s');
            } elseif (is_object($value) && method_exists($value, 'getId')) {
                $data[$propertyName . '_id'] = $value->getId();
            } elseif (is_array($value) || $value instanceof \Doctrine\Common\Collections\Collection) {
                $ids = [];
                foreach ($value as $item) {
                    if (is_object($item) && method_exists($item, 'getId')) {
                        $ids[] = $item->getId();
                    }
                }
                if (!empty($ids)) {
                    $data[$propertyName . '_ids'] = $ids;
                }
            } elseif (is_scalar($value)) {
                $data[$propertyName] = $value;
            }
        }

        return $data;
    }
}
