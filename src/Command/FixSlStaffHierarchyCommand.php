<?php

namespace App\Command;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fix-sl-staff-hierarchy',
    description: 'Fix SL_STAFF users that are missing shipping_line_admin_id'
)]
class FixSlStaffHierarchyCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Fixing SL_STAFF Hierarchy');

        // Find all SL_STAFF users without a shipping_line_admin_id
        $slStaffUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.shippingLineAdmin IS NULL')
            ->setParameter('role', UserRole::SL_STAFF)
            ->getQuery()
            ->getResult();

        if (empty($slStaffUsers)) {
            $io->success('All SL_STAFF users already have a shipping line admin assigned.');
            return Command::SUCCESS;
        }

        $io->section('Found ' . count($slStaffUsers) . ' SL_STAFF users without admin assignment');

        // Find all SHIPPING_LINES_ADMIN users
        $admins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', UserRole::SHIPPING_LINES_ADMIN)
            ->getQuery()
            ->getResult();

        if (empty($admins)) {
            $io->error('No SHIPPING_LINES_ADMIN users found. Cannot assign SL_STAFF users.');
            return Command::FAILURE;
        }

        $io->section('Available SHIPPING_LINES_ADMIN users:');
        foreach ($admins as $index => $admin) {
            $io->writeln(sprintf('[%d] %s (%s)', $index + 1, $admin->getEmail(), $admin->getId()));
        }

        foreach ($slStaffUsers as $slStaff) {
            $io->section('Processing: ' . $slStaff->getEmail());
            
            if (count($admins) === 1) {
                // Only one admin, auto-assign
                $admin = $admins[0];
                $slStaff->setShippingLineAdmin($admin);
                $io->writeln(sprintf('  → Assigned to %s', $admin->getEmail()));
            } else {
                // Multiple admins, ask user
                $adminIndex = $io->choice(
                    sprintf('Select admin for %s', $slStaff->getEmail()),
                    array_map(fn($a) => $a->getEmail(), $admins),
                    $admins[0]->getEmail()
                );
                
                $selectedAdmin = array_values(array_filter($admins, fn($a) => $a->getEmail() === $adminIndex))[0];
                $slStaff->setShippingLineAdmin($selectedAdmin);
                $io->writeln(sprintf('  → Assigned to %s', $selectedAdmin->getEmail()));
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Successfully assigned %d SL_STAFF users to their admins.', count($slStaffUsers)));
        $io->note('SL_STAFF users can now see their admin\'s container yard allocations.');

        return Command::SUCCESS;
    }
}
