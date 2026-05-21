<?php

namespace App\Command;

use App\Entity\AccreditationSubmission;
use App\Entity\Broker;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\UserRole;
use App\Entity\FormConfiguration;
use App\Entity\StaffUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-test-data',
    description: 'Create test accreditation submission data for admin dashboard'
)]
class CreateTestDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Find a broker user
        $broker = $this->entityManager->getRepository(Broker::class)->findOneBy([]);
        if (!$broker) {
            $io->error('No broker found in database. Please load fixtures first.');
            return Command::FAILURE;
        }

        // Find an evaluator
        $evaluator = $this->entityManager->getRepository(StaffUser::class)
            ->findOneBy(['role' => UserRole::EVALUATOR]);
        if (!$evaluator) {
            $io->error('No evaluator found in database. Please load fixtures first.');
            return Command::FAILURE;
        }

        // Find a form configuration
        $formConfig = $this->entityManager->getRepository(FormConfiguration::class)->findOneBy([]);
        if (!$formConfig) {
            $io->error('No form configuration found in database. Please load fixtures first.');
            return Command::FAILURE;
        }

        // Create test accreditation submission
        $submission = new AccreditationSubmission();
        $submission->setApplicant($broker);
        $submission->setFormConfig($formConfig);
        $submission->setSubmittedData([
            'broker_license_number' => 'TEST-BRK-001',
            'business_address' => '123 Test Street, Test City',
            'authorized_representative' => 'John Test',
            'service_areas' => ['customs_clearance', 'freight_forwarding', 'cargo_handling'],
            'experience_years' => '5',
            'terms_acceptance' => '1',
            'regulatory_compliance' => '1',
            '_files' => [
                'business_license' => 'test-file-1',
                'tax_certificate' => 'test-file-2'
            ]
        ]);
        $submission->setStatus(AccreditationStatus::APPROVED);
        $submission->setEvaluator($evaluator);
        $submission->setEvaluatedAt(new \DateTime('-1 day'));

        $this->entityManager->persist($submission);
        $this->entityManager->flush();

        $io->success('Test accreditation submission created successfully!');
        $io->info('Submission ID: ' . $submission->getId());
        $io->info('Status: ' . $submission->getStatus()->value);
        $io->info('Evaluator: ' . $submission->getEvaluator()->getEmail());
        $io->info('Ready for final approval by admin');

        return Command::SUCCESS;
    }
}