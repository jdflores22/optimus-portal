<?php

namespace App\Command;

use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\StaffUser;
use App\Entity\Terminal;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:setup-shipping-allocations',
    description: 'Setup shipping line terminal allocations for demo purposes',
)]
class SetupShippingLineAllocationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Create the table if it doesn't exist
            $connection = $this->entityManager->getConnection();
            $sql = "CREATE TABLE IF NOT EXISTS shipping_line_terminal_allocations (
                id INT AUTO_INCREMENT NOT NULL,
                staff_user_id INT NOT NULL,
                terminal_id INT NOT NULL,
                allocated_capacity INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY unique_staff_terminal (staff_user_id, terminal_id),
                INDEX idx_staff_user (staff_user_id),
                INDEX idx_terminal (terminal_id),
                CONSTRAINT FK_allocation_staff_user FOREIGN KEY (staff_user_id) REFERENCES staff_users (id) ON DELETE CASCADE,
                CONSTRAINT FK_allocation_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB";
            
            $connection->executeStatement($sql);
            $io->success('Created shipping_line_terminal_allocations table');

            // Find SHIPPING_LINES_ADMIN users
            $shippingAdmins = $this->entityManager->getRepository(StaffUser::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->setParameter('role', UserRole::SHIPPING_LINES_ADMIN)
                ->getQuery()
                ->getResult();

            if (empty($shippingAdmins)) {
                $io->warning('No SHIPPING_LINES_ADMIN users found. Creating sample data...');
                
                // Create a sample shipping lines admin user
                $shippingAdmin = new StaffUser();
                $shippingAdmin->setEmail('shipping.admin@optimus.com');
                $shippingAdmin->setPasswordHash(password_hash('password123', PASSWORD_DEFAULT));
                $shippingAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
                $shippingAdmin->setFirstName('Shipping');
                $shippingAdmin->setLastName('Administrator');
                $shippingAdmin->setDepartment('Shipping Lines');
                
                $this->entityManager->persist($shippingAdmin);
                $this->entityManager->flush();
                
                $shippingAdmins = [$shippingAdmin];
                $io->success('Created sample shipping admin user: shipping.admin@optimus.com');
            }

            // Find all terminals
            $terminals = $this->entityManager->getRepository(Terminal::class)->findAll();

            if (empty($terminals)) {
                $io->warning('No terminals found. Please create terminals first.');
                return Command::FAILURE;
            }

            // Create allocations for each shipping admin
            $allocationsCreated = 0;
            foreach ($shippingAdmins as $admin) {
                // Allocate some terminals to this admin (for demo, allocate 2-3 terminals)
                $terminalCount = min(3, count($terminals));
                $selectedTerminals = array_slice($terminals, 0, $terminalCount);
                
                foreach ($selectedTerminals as $terminal) {
                    // Check if allocation already exists
                    $existingAllocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
                        ->findOneBy(['staffUser' => $admin, 'terminal' => $terminal]);
                    
                    if (!$existingAllocation) {
                        $allocation = new ShippingLineTerminalAllocation();
                        $allocation->setStaffUser($admin);
                        $allocation->setTerminal($terminal);
                        // Allocate 60-80% of terminal capacity
                        $allocatedCapacity = (int)($terminal->getDailyCapacity() * (0.6 + (rand(0, 20) / 100)));
                        $allocation->setAllocatedCapacity($allocatedCapacity);
                        
                        $this->entityManager->persist($allocation);
                        $allocationsCreated++;
                    }
                }
            }

            $this->entityManager->flush();

            $io->success("Setup completed! Created {$allocationsCreated} terminal allocations.");
            $io->info("Shipping admin users can now see their allocated container yards in the dashboard.");

        } catch (\Exception $e) {
            $io->error('Error setting up allocations: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}