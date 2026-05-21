<?php

namespace App\Command;

use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-shipping-line-hierarchy',
    description: 'Create 4 shipping lines with admins and hierarchical users for testing'
)]
class CreateShippingLineHierarchyCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Creating Shipping Line Hierarchy Test Data');

        // Define shipping lines data
        $shippingLinesData = [
            [
                'name' => 'Pacific Maritime Lines',
                'brand' => 'PACIFIC-ML',
                'code' => 'PML001',
                'admin_email' => 'admin@pacificmaritime.com',
                'admin_name' => ['John', 'Pacific']
            ],
            [
                'name' => 'Atlantic Shipping Corporation',
                'brand' => 'ATLANTIC-SC',
                'code' => 'ASC002',
                'admin_email' => 'admin@atlanticshipping.com',
                'admin_name' => ['Sarah', 'Atlantic']
            ],
            [
                'name' => 'Global Ocean Transport',
                'brand' => 'GLOBAL-OT',
                'code' => 'GOT003',
                'admin_email' => 'admin@globaloceantransport.com',
                'admin_name' => ['Michael', 'Global']
            ],
            [
                'name' => 'Mediterranean Express Lines',
                'brand' => 'MEDITER-EL',
                'code' => 'MEL004',
                'admin_email' => 'admin@medexpress.com',
                'admin_name' => ['Elena', 'Mediterranean']
            ]
        ];

        // Define subordinate roles and their data
        $subordinateRoles = [
            [
                'role' => UserRole::ACCOUNTING,
                'name' => 'Accounting'
            ],
            [
                'role' => UserRole::SL_STAFF,
                'name' => 'Staff'
            ],
            [
                'role' => UserRole::EVALUATOR,
                'name' => 'Evaluator'
            ],
            [
                'role' => UserRole::TERMINAL_TEAM,
                'name' => 'Terminal Team'
            ]
        ];

        $createdData = [];

        foreach ($shippingLinesData as $index => $shippingData) {
            $io->section("Creating {$shippingData['name']}");

            // Create Shipping Line
            $shippingLine = new ShippingLine();
            $shippingLine->setBrandName($shippingData['brand']);
            $shippingLine->setPortalConfig([
                'company_name' => $shippingData['name'],
                'shipping_line_code' => $shippingData['code'],
                'contact_email' => $shippingData['admin_email'],
                'contact_phone' => '+1-555-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT) . '-0000',
                'address' => '123 Maritime Street, Port City, PC ' . (10000 + $index)
            ]);
            $shippingLine->setIsActive(true);

            $this->entityManager->persist($shippingLine);
            $this->entityManager->flush(); // Flush to get ID

            $io->text("✓ Created shipping line: {$shippingData['name']}");

            // Create Shipping Line Admin
            $admin = new StaffUser();
            $admin->setEmail($shippingData['admin_email']);
            $admin->setFirstName($shippingData['admin_name'][0]);
            $admin->setLastName($shippingData['admin_name'][1]);
            $admin->setDepartment('Administration');
            $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
            $admin->setStatus(AccountStatus::APPROVED);
            $admin->setManagedShippingLine($shippingLine);
            
            // Set password (default: "password123")
            $hashedPassword = $this->passwordHasher->hashPassword($admin, 'password123');
            $admin->setPasswordHash($hashedPassword);
            
            // Set email as verified
            $admin->setEmailVerifiedAt(new \DateTime());

            $this->entityManager->persist($admin);
            $this->entityManager->flush(); // Flush to get admin ID

            $io->text("✓ Created admin: {$admin->getFirstName()} {$admin->getLastName()} ({$admin->getEmail()})");

            $subordinates = [];

            // Create subordinate users for each role
            foreach ($subordinateRoles as $roleData) {
                $role = $roleData['role'];
                $roleName = $roleData['name'];
                
                $subordinate = new StaffUser();
                $subordinate->setEmail(strtolower($roleName) . '@' . strtolower($shippingData['brand']) . '.com');
                $subordinate->setFirstName($roleName);
                $subordinate->setLastName($shippingData['admin_name'][1]); // Use shipping line surname
                $subordinate->setDepartment($roleName); // Set department to role name
                $subordinate->setRole($role);
                $subordinate->setStatus(AccountStatus::APPROVED);
                $subordinate->setShippingLineAdmin($admin);
                
                // Set password (default: "password123")
                $hashedPassword = $this->passwordHasher->hashPassword($subordinate, 'password123');
                $subordinate->setPasswordHash($hashedPassword);
                
                // Set email as verified
                $subordinate->setEmailVerifiedAt(new \DateTime());

                // Add some variety - make some users have failed login attempts or be locked
                if ($index === 1 && $role === UserRole::ACCOUNTING) {
                    // Second shipping line's accounting user has failed login attempts
                    $subordinate->setFailedLoginAttempts(3);
                } elseif ($index === 2 && $role === UserRole::SL_STAFF) {
                    // Third shipping line's staff user is temporarily locked
                    $subordinate->setFailedLoginAttempts(5);
                    $subordinate->setLockedUntil(new \DateTime('+1 hour'));
                } elseif ($index === 3 && $role === UserRole::EVALUATOR) {
                    // Fourth shipping line's evaluator is permanently locked by admin
                    $subordinate->setStatus(AccountStatus::LOCKED);
                }

                $this->entityManager->persist($subordinate);
                $subordinates[] = $subordinate;

                $io->text("  ✓ Created {$role->value}: {$subordinate->getFirstName()} {$subordinate->getLastName()} ({$subordinate->getEmail()})");
            }

            $this->entityManager->flush();

            $createdData[] = [
                'shipping_line' => $shippingLine,
                'admin' => $admin,
                'subordinates' => $subordinates
            ];

            $io->text("✓ Completed hierarchy for {$shippingData['name']}\n");
        }

        $io->success('Successfully created shipping line hierarchy test data!');

        // Display summary
        $io->section('Summary');
        $io->table(
            ['Shipping Line', 'Admin Email', 'Subordinates', 'Status'],
            array_map(function($data) {
                return [
                    $data['shipping_line']->getBrandName(),
                    $data['admin']->getEmail(),
                    count($data['subordinates']),
                    $data['shipping_line']->isActive() ? 'Active' : 'Inactive'
                ];
            }, $createdData)
        );

        $io->note('Default password for all users: password123');
        
        $io->section('Test Scenarios Created');
        $io->listing([
            'Pacific Maritime Lines (PML) - All users normal',
            'Atlantic Shipping Corporation (ASC) - Accounting user has 3 failed login attempts',
            'Global Ocean Transport (GOT) - Staff user is temporarily locked (1 hour)',
            'Mediterranean Express Lines (MEL) - Evaluator is permanently locked by admin'
        ]);

        $io->note('You can now test:');
        $io->listing([
            'User hierarchy display on shipping line detail pages',
            'Account lock status display on user detail pages',
            'Shipping line deactivation affecting all users',
            'Different lock types (failed logins vs admin lock)'
        ]);

        return Command::SUCCESS;
    }
}