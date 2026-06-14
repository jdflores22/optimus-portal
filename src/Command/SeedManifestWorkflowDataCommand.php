<?php

namespace App\Command;

use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\Consignee;
use App\Entity\Broker;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed:manifest-workflow',
    description: 'Seed test data for manifest payment and NOA workflow'
)]
class SeedManifestWorkflowDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('users', null, InputOption::VALUE_NONE, 'Seed test users with all roles')
            ->addOption('manifests', null, InputOption::VALUE_NONE, 'Seed sample manifests')
            ->addOption('payments', null, InputOption::VALUE_NONE, 'Seed sample payments')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Seed all test data')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $seedAll = $input->getOption('all');
        $seedUsers = $input->getOption('users') || $seedAll;
        $seedManifests = $input->getOption('manifests') || $seedAll;
        $seedPayments = $input->getOption('payments') || $seedAll;

        if (!$seedUsers && !$seedManifests && !$seedPayments) {
            $io->error('Please specify at least one option: --users, --manifests, --payments, or --all');
            return Command::FAILURE;
        }

        $io->title('Seeding Manifest Workflow Test Data');

        $users = [];
        if ($seedUsers) {
            $users = $this->seedUsers($io);
        } else {
            // Load existing users
            $users = $this->loadExistingUsers($io);
        }

        $manifests = [];
        if ($seedManifests && !empty($users)) {
            $manifests = $this->seedManifests($io, $users);
        }

        if ($seedPayments && !empty($manifests)) {
            $this->seedPayments($io, $manifests, $users);
        }

        $io->success('Test data seeding completed successfully!');

        return Command::SUCCESS;
    }

    private function seedUsers(SymfonyStyle $io): array
    {
        $io->section('Seeding Test Users');

        $users = [];

        // SL_STAFF user
        $slStaff = $this->createUser(
            'slstaff@test.com',
            'Test123!',
            'SL Staff',
            'Test',
            UserRole::SL_STAFF
        );
        $users['sl_staff'] = $slStaff;
        $io->writeln('✓ Created SL_STAFF user: slstaff@test.com');

        // SYSTEM_ADMIN user
        $systemAdmin = $this->createUser(
            'sysadmin@test.com',
            'Test123!',
            'System Admin',
            'Test',
            UserRole::SYSTEM_ADMIN
        );
        $users['system_admin'] = $systemAdmin;
        $io->writeln('✓ Created SYSTEM_ADMIN user: sysadmin@test.com');

        // ACCOUNTING user
        $accounting = $this->createUser(
            'accounting@test.com',
            'Test123!',
            'Accounting',
            'Test',
            UserRole::ACCOUNTING
        );
        $users['accounting'] = $accounting;
        $io->writeln('✓ Created ACCOUNTING user: accounting@test.com');

        // Broker users
        $broker1 = $this->createBroker(
            'broker1@test.com',
            'Test123!',
            'John Broker - Broker Services Inc.'
        );
        $users['broker1'] = $broker1;
        $io->writeln('✓ Created Broker user: broker1@test.com');

        $broker2 = $this->createBroker(
            'broker2@test.com',
            'Test123!',
            'Jane Broker - Express Brokerage Co.'
        );
        $users['broker2'] = $broker2;
        $io->writeln('✓ Created Broker user: broker2@test.com');

        // Consignee users
        $consignee1 = $this->createConsignee(
            'consignee1@test.com',
            'Test123!',
            'ABC Trading Corp',
            null
        );
        $users['consignee1'] = $consignee1;
        $io->writeln('✓ Created Consignee user: consignee1@test.com');

        $consignee2 = $this->createConsignee(
            'consignee2@test.com',
            'Test123!',
            'XYZ Imports Ltd',
            $broker1
        );
        $users['consignee2'] = $consignee2;
        $io->writeln('✓ Created Consignee user (with linked broker): consignee2@test.com');

        $this->entityManager->flush();

        $io->writeln('');
        $io->writeln('Default password for all test users: Test123!');

        return $users;
    }

    private function loadExistingUsers(SymfonyStyle $io): array
    {
        $io->section('Loading Existing Users');

        $users = [];

        $slStaff = $this->entityManager->getRepository(StaffUser::class)
            ->findOneBy(['role' => UserRole::SL_STAFF]);
        if ($slStaff) {
            $users['sl_staff'] = $slStaff;
            $io->writeln('✓ Loaded SL_STAFF user');
        }

        $systemAdmin = $this->entityManager->getRepository(StaffUser::class)
            ->findOneBy(['role' => UserRole::SYSTEM_ADMIN]);
        if ($systemAdmin) {
            $users['system_admin'] = $systemAdmin;
            $io->writeln('✓ Loaded SYSTEM_ADMIN user');
        }

        $accounting = $this->entityManager->getRepository(StaffUser::class)
            ->findOneBy(['role' => UserRole::ACCOUNTING]);
        if ($accounting) {
            $users['accounting'] = $accounting;
            $io->writeln('✓ Loaded ACCOUNTING user');
        }

        $brokers = $this->entityManager->getRepository(Broker::class)->findAll();
        foreach ($brokers as $index => $broker) {
            $users['broker' . ($index + 1)] = $broker;
        }
        $io->writeln(sprintf('✓ Loaded %d Broker users', count($brokers)));

        $consignees = $this->entityManager->getRepository(Consignee::class)->findAll();
        foreach ($consignees as $index => $consignee) {
            $users['consignee' . ($index + 1)] = $consignee;
        }
        $io->writeln(sprintf('✓ Loaded %d Consignee users', count($consignees)));

        return $users;
    }

    private function seedManifests(SymfonyStyle $io, array $users): array
    {
        $io->section('Seeding Sample Manifests');

        if (!isset($users['sl_staff'])) {
            $io->warning('No SL_STAFF user found. Skipping manifest seeding.');
            return [];
        }

        $manifests = [];
        $slStaff = $users['sl_staff'];

        // Manifest 1: Pending payment
        $manifest1 = new Manifest();
        $manifest1->setManifestNumber('MAN-2024-001');
        $manifest1->setVesselName('MV Ocean Star');
        $manifest1->setVoyageNumber('V123');
        $manifest1->setArrivalDate(new \DateTime('+7 days'));
        $manifest1->setCreatedBy($slStaff);
        $manifest1->setWorkflowState(WorkflowState::PAYMENT_SUBMITTED);
        
        if (isset($users['consignee1'])) {
            $manifest1->setConsignee($users['consignee1']);
        }
        if (isset($users['broker1'])) {
            $manifest1->setBroker($users['broker1']);
        }
        
        $this->entityManager->persist($manifest1);
        $manifests['manifest1'] = $manifest1;
        $io->writeln('✓ Created manifest: MAN-2024-001 (payment_submitted)');

        // Manifest 2: Payment verified
        $manifest2 = new Manifest();
        $manifest2->setManifestNumber('MAN-2024-002');
        $manifest2->setVesselName('MV Pacific Trader');
        $manifest2->setVoyageNumber('V124');
        $manifest2->setArrivalDate(new \DateTime('+10 days'));
        $manifest2->setCreatedBy($slStaff);
        $manifest2->setWorkflowState(WorkflowState::PAYMENT_VERIFIED);
        
        if (isset($users['consignee2'])) {
            $manifest2->setConsignee($users['consignee2']);
        }
        if (isset($users['broker1'])) {
            $manifest2->setBroker($users['broker1']);
        }
        
        $this->entityManager->persist($manifest2);
        $manifests['manifest2'] = $manifest2;
        $io->writeln('✓ Created manifest: MAN-2024-002 (payment_verified)');

        // Manifest 3: NOA generated
        $manifest3 = new Manifest();
        $manifest3->setManifestNumber('MAN-2024-003');
        $manifest3->setVesselName('MV Atlantic Express');
        $manifest3->setVoyageNumber('V125');
        $manifest3->setArrivalDate(new \DateTime('+5 days'));
        $manifest3->setCreatedBy($slStaff);
        $manifest3->setWorkflowState(WorkflowState::NOA_GENERATED);
        
        if (isset($users['consignee1'])) {
            $manifest3->setConsignee($users['consignee1']);
        }
        if (isset($users['broker2'])) {
            $manifest3->setBroker($users['broker2']);
        }
        
        $this->entityManager->persist($manifest3);
        $manifests['manifest3'] = $manifest3;
        $io->writeln('✓ Created manifest: MAN-2024-003 (noa_generated)');

        // Manifest 4: BL uploaded
        $manifest4 = new Manifest();
        $manifest4->setManifestNumber('MAN-2024-004');
        $manifest4->setVesselName('MV Global Carrier');
        $manifest4->setVoyageNumber('V126');
        $manifest4->setArrivalDate(new \DateTime('+3 days'));
        $manifest4->setBlNumber('BL-2024-001');
        $manifest4->setCreatedBy($slStaff);
        $manifest4->setWorkflowState(WorkflowState::BL_UPLOADED);
        
        if (isset($users['consignee2'])) {
            $manifest4->setConsignee($users['consignee2']);
        }
        if (isset($users['broker1'])) {
            $manifest4->setBroker($users['broker1']);
        }
        
        $this->entityManager->persist($manifest4);
        $manifests['manifest4'] = $manifest4;
        $io->writeln('✓ Created manifest: MAN-2024-004 (bl_uploaded)');

        // Manifest 5: Billing generated
        $manifest5 = new Manifest();
        $manifest5->setManifestNumber('MAN-2024-005');
        $manifest5->setVesselName('MV Horizon Voyager');
        $manifest5->setVoyageNumber('V127');
        $manifest5->setArrivalDate(new \DateTime('+2 days'));
        $manifest5->setBlNumber('BL-2024-002');
        $manifest5->setCreatedBy($slStaff);
        $manifest5->setWorkflowState(WorkflowState::BILLING_GENERATED);
        
        if (isset($users['consignee1'])) {
            $manifest5->setConsignee($users['consignee1']);
        }
        if (isset($users['broker2'])) {
            $manifest5->setBroker($users['broker2']);
        }
        
        $this->entityManager->persist($manifest5);
        $manifests['manifest5'] = $manifest5;
        $io->writeln('✓ Created manifest: MAN-2024-005 (billing_generated)');

        $this->entityManager->flush();

        return $manifests;
    }

    private function seedPayments(SymfonyStyle $io, array $manifests, array $users): void
    {
        $io->section('Seeding Sample Payments');

        // Payment for manifest 1 - pending validation
        if (isset($manifests['manifest1']) && isset($users['broker1'])) {
            $payment1 = new Payment();
            $payment1->setManifest($manifests['manifest1']);
            $payment1->setPaymentType(PaymentType::MANIFEST_ACCESS);
            $payment1->setAmount(500.00);
            $payment1->setReceiptFilePath('/uploads/receipts/test-receipt-1.pdf');
            $payment1->setSubmittedBy($users['broker1']);
            $payment1->setStatus(PaymentStatus::PENDING_VALIDATION);
            
            $this->entityManager->persist($payment1);
            $io->writeln('✓ Created manifest access payment for MAN-2024-001 (pending_validation)');
        }

        // Payment for manifest 2 - verified
        if (isset($manifests['manifest2']) && isset($users['broker1']) && isset($users['system_admin'])) {
            $payment2 = new Payment();
            $payment2->setManifest($manifests['manifest2']);
            $payment2->setPaymentType(PaymentType::MANIFEST_ACCESS);
            $payment2->setAmount(500.00);
            $payment2->setReceiptFilePath('/uploads/receipts/test-receipt-2.pdf');
            $payment2->setSubmittedBy($users['broker1']);
            
            $this->entityManager->persist($payment2);
            $this->entityManager->flush(); // Flush before calling verify to ensure payment has an ID
            
            $payment2->verify($users['system_admin']);
            
            $io->writeln('✓ Created manifest access payment for MAN-2024-002 (verified)');
        }

        // Payment for manifest 5 - final payment pending
        if (isset($manifests['manifest5']) && isset($users['broker2'])) {
            $payment5 = new Payment();
            $payment5->setManifest($manifests['manifest5']);
            $payment5->setPaymentType(PaymentType::FINAL_PAYMENT);
            $payment5->setAmount(15000.00);
            $payment5->setReceiptFilePath('/uploads/receipts/test-receipt-5.pdf');
            $payment5->setSubmittedBy($users['broker2']);
            $payment5->setStatus(PaymentStatus::PENDING_VALIDATION);
            
            $this->entityManager->persist($payment5);
            $io->writeln('✓ Created final payment for MAN-2024-005 (pending_validation)');
        }

        $this->entityManager->flush();
    }

    private function createUser(
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        UserRole $role
    ): StaffUser {
        $user = new StaffUser();
        $user->setEmail($email);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setDepartment('Operations'); // Default department for test users
        $user->setRole($role);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setEmailVerifiedAt(new \DateTime());

        $this->entityManager->persist($user);

        return $user;
    }

    private function createBroker(
        string $email,
        string $password,
        string $fullName
    ): Broker {
        $broker = new Broker();
        $broker->setEmail($email);
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, $password));
        $broker->setFullName($fullName);
        $broker->setRole(UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $broker->setEmailVerifiedAt(new \DateTime());

        $this->entityManager->persist($broker);

        return $broker;
    }

    private function createConsignee(
        string $email,
        string $password,
        string $businessName,
        ?Broker $linkedBroker
    ): Consignee {
        $consignee = new Consignee();
        $consignee->setEmail($email);
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, $password));
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setEmailVerifiedAt(new \DateTime());
        $consignee->setBusinessName($businessName);
        
        if ($linkedBroker) {
            $consignee->setLinkedBroker($linkedBroker);
        }

        $this->entityManager->persist($consignee);

        return $consignee;
    }
}
