<?php

namespace App\Command;

use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\SlotStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:setup-sample-terminals',
    description: 'Setup sample terminals and slots for demo purposes',
)]
class SetupSampleTerminalsCommand extends Command
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
            // Check if terminals already exist
            $existingTerminals = $this->entityManager->getRepository(Terminal::class)->findAll();
            
            if (count($existingTerminals) >= 3) {
                $io->info('Terminals already exist. Skipping terminal creation.');
                return Command::SUCCESS;
            }

            // Sample terminal data
            $terminalData = [
                [
                    'name' => 'ASL Container Yard',
                    'type' => TerminalType::CY,
                    'location' => 'Port Area North, Manila',
                    'capacity' => 150
                ],
                [
                    'name' => 'CUL Terminal',
                    'type' => TerminalType::ATI,
                    'location' => 'South Harbor, Manila',
                    'capacity' => 120
                ],
                [
                    'name' => 'HLL Harbour Link',
                    'type' => TerminalType::ICTSI,
                    'location' => 'Manila International Container Terminal',
                    'capacity' => 200
                ],
                [
                    'name' => 'KGP Container Yard',
                    'type' => TerminalType::CY,
                    'location' => 'Bataan Port Area',
                    'capacity' => 100
                ],
                [
                    'name' => 'KMTC Terminal',
                    'type' => TerminalType::ATI,
                    'location' => 'Subic Bay Freeport',
                    'capacity' => 180
                ],
                [
                    'name' => 'NAM Shipping Terminal',
                    'type' => TerminalType::ICTSI,
                    'location' => 'Cebu International Port',
                    'capacity' => 90
                ]
            ];

            $terminalsCreated = 0;
            foreach ($terminalData as $data) {
                $terminal = new Terminal();
                $terminal->setName($data['name']);
                $terminal->setType($data['type']);
                $terminal->setLocation($data['location']);
                $terminal->setDailyCapacity($data['capacity']);
                $terminal->setIsActive(true);

                $this->entityManager->persist($terminal);
                $terminalsCreated++;

                // Create terminal slots for the next 30 days
                $this->createTerminalSlots($terminal, $data['capacity']);
            }

            $this->entityManager->flush();

            $io->success("Created {$terminalsCreated} sample terminals with slots for the next 30 days.");

        } catch (\Exception $e) {
            $io->error('Error setting up terminals: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function createTerminalSlots(Terminal $terminal, int $dailyCapacity): void
    {
        $startDate = new \DateTime('today');
        
        for ($i = 0; $i < 30; $i++) {
            $date = clone $startDate;
            $date->add(new \DateInterval("P{$i}D"));
            
            $slot = new TerminalSlot();
            $slot->setTerminal($terminal);
            $slot->setDate($date);
            $slot->setCapacity($dailyCapacity);
            
            // Simulate some random occupancy (20-80% of capacity)
            $occupancyRate = rand(20, 80) / 100;
            $assignedCount = (int)($dailyCapacity * $occupancyRate);
            $slot->setAssignedCount($assignedCount);
            
            // Set status based on occupancy
            if ($assignedCount >= $dailyCapacity) {
                $slot->setStatus(SlotStatus::FULL);
            } elseif ($assignedCount > $dailyCapacity * 0.9) {
                $slot->setStatus(SlotStatus::AVAILABLE); // Nearly full but still available
            } else {
                $slot->setStatus(SlotStatus::AVAILABLE);
            }
            
            $this->entityManager->persist($slot);
        }
    }
}