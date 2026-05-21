<?php

namespace App\Command;

use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Tests\Fixtures\DwellTimeTestDataFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-test-dwell-time-containers',
    description: 'Generate test containers with various dwell time scenarios'
)]
class GenerateTestDwellTimeContainersCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Generating Test Dwell Time Containers');

        // Create a test user for audit trails
        $user = $this->getOrCreateTestUser();

        $io->section('Creating containers with various dwell time scenarios');

        $containers = [];

        // 1. Container at 60-day threshold (should trigger notification)
        $io->text('Creating container at 60-day notification threshold...');
        $container60 = DwellTimeTestDataFactory::createContainerApproaching60Days(0);
        $this->entityManager->persist($container60);
        $containers[] = [
            'container' => $container60,
            'description' => '60-day threshold (notification due)',
            'dwell_time' => 60
        ];

        // 2. Container 5 days before 60-day threshold
        $io->text('Creating container 5 days before 60-day threshold...');
        $container55 = DwellTimeTestDataFactory::createContainerApproaching60Days(-5);
        $this->entityManager->persist($container55);
        $containers[] = [
            'container' => $container55,
            'description' => '5 days before 60-day notification',
            'dwell_time' => 55
        ];

        // 3. Container 5 days after 60-day threshold
        $io->text('Creating container 5 days after 60-day threshold...');
        $container65 = DwellTimeTestDataFactory::createContainerApproaching60Days(5);
        $this->entityManager->persist($container65);
        $containers[] = [
            'container' => $container65,
            'description' => '5 days after 60-day notification',
            'dwell_time' => 65
        ];

        // 4. Container at 90-day threshold (should trigger automatic return)
        $io->text('Creating container at 90-day automatic return threshold...');
        $container90 = DwellTimeTestDataFactory::createContainerApproaching90Days(0);
        $this->entityManager->persist($container90);
        $containers[] = [
            'container' => $container90,
            'description' => '90-day threshold (automatic return due)',
            'dwell_time' => 90
        ];

        // 5. Container 3 days before 90-day threshold
        $io->text('Creating container 3 days before 90-day threshold...');
        $container87 = DwellTimeTestDataFactory::createContainerApproaching90Days(-3);
        $this->entityManager->persist($container87);
        $containers[] = [
            'container' => $container87,
            'description' => '3 days before 90-day automatic return',
            'dwell_time' => 87
        ];

        // 6. Container in ALERT status (paused at 45 days, 10 days ago)
        $io->text('Creating container in ALERT status (dwell time paused)...');
        $containerAlert = DwellTimeTestDataFactory::createContainerInAlertStatus(45, 10);
        $this->entityManager->persist($containerAlert);
        $containers[] = [
            'container' => $containerAlert,
            'description' => 'ALERT status (paused at 45 days, 10 days ago)',
            'dwell_time' => 45,
            'status' => 'ALERT'
        ];

        // 7. Container in ALERT status (paused at 70 days, 5 days ago)
        $io->text('Creating container in ALERT status near 90-day threshold...');
        $containerAlert70 = DwellTimeTestDataFactory::createContainerInAlertStatus(70, 5);
        $this->entityManager->persist($containerAlert70);
        $containers[] = [
            'container' => $containerAlert70,
            'description' => 'ALERT status (paused at 70 days, 5 days ago)',
            'dwell_time' => 70,
            'status' => 'ALERT'
        ];

        // 8. Container with single pause (10-day pause, 65 days total)
        $io->text('Creating container with single pause cycle...');
        $containerPause = DwellTimeTestDataFactory::createContainerWithSinglePause(10, 65);
        $this->entityManager->persist($containerPause);
        $containers[] = [
            'container' => $containerPause,
            'description' => 'Single pause (10 days paused, 65 days total)',
            'dwell_time' => 55,
            'paused_days' => 10
        ];

        // 9. Container with multiple pauses
        $io->text('Creating container with multiple pause cycles...');
        $containerMultiPause = DwellTimeTestDataFactory::createContainerWithMultiplePauses([5, 10, 7], 85);
        $this->entityManager->persist($containerMultiPause);
        $containers[] = [
            'container' => $containerMultiPause,
            'description' => 'Multiple pauses (22 days total paused, 85 days total)',
            'dwell_time' => 63,
            'paused_days' => 22
        ];

        // 10. Recently resumed container
        $io->text('Creating recently resumed container...');
        $containerResumed = DwellTimeTestDataFactory::createResumedContainer(15, 5);
        $this->entityManager->persist($containerResumed);
        $containers[] = [
            'container' => $containerResumed,
            'description' => 'Recently resumed (15 days paused, resumed 5 days ago)',
            'dwell_time' => 55,
            'paused_days' => 15
        ];

        // Flush all containers
        $this->entityManager->flush();

        // Create audit trails for containers with pause history
        $io->section('Creating audit trails for containers with pause history');
        
        $containersWithAudit = [
            $containerPause,
            $containerMultiPause,
            $containerResumed
        ];

        foreach ($containersWithAudit as $container) {
            $events = DwellTimeTestDataFactory::createCompleteAuditTrail($container, $user);
            foreach ($events as $event) {
                $this->entityManager->persist($event);
            }
        }

        $this->entityManager->flush();

        // Display summary
        $io->section('Summary of Created Containers');

        $tableData = [];
        foreach ($containers as $data) {
            $container = $data['container'];
            $tableData[] = [
                $container->getContainerNumber(),
                $data['description'],
                $data['dwell_time'] . ' days',
                $data['paused_days'] ?? '-',
                $data['status'] ?? $container->getStatus()->value,
                $container->getTerminalArrivalDate()->format('Y-m-d'),
            ];
        }

        $io->table(
            ['Container Number', 'Description', 'Dwell Time', 'Paused Days', 'Status', 'Arrival Date'],
            $tableData
        );

        $io->success(sprintf('Successfully created %d test containers with various dwell time scenarios!', count($containers)));

        $io->note([
            'You can now:',
            '1. Run the dwell time monitoring command: php bin/console app:dwell-time:monitor',
            '2. View containers in the database',
            '3. Test notification triggers at 60 days',
            '4. Test automatic return triggers at 90 days',
            '5. Test pause/resume functionality with ALERT status containers'
        ]);

        return Command::SUCCESS;
    }

    private function getOrCreateTestUser(): StaffUser
    {
        $user = $this->entityManager->getRepository(StaffUser::class)
            ->findOneBy(['email' => 'test.admin@optimus.com']);

        if (!$user) {
            $user = new StaffUser();
            $user->setEmail('test.admin@optimus.com');
            $user->setFirstName('Test');
            $user->setLastName('Admin');
            $user->setDepartment('IT');
            $user->setRole(UserRole::SYSTEM_ADMIN);
            $user->setStatus(AccountStatus::APPROVED);
            $user->setPasswordHash('$2y$12$test'); // Dummy password hash
            
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        return $user;
    }
}
