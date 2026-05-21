<?php

namespace App\DataFixtures;

use App\Entity\Container;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\SlotStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\UserRole;
use App\Entity\StaffUser;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TerminalTeamPreAdviceFixtures extends Fixture implements DependentFixtureInterface
{
    public const TERMINAL_TEAM_USER_REFERENCE = 'user-terminal-team';
    public const TRUCKER_1_REFERENCE = 'user-trucker-1';
    public const TRUCKER_2_REFERENCE = 'user-trucker-2';
    public const CY_TERMINAL_REFERENCE = 'terminal-cy';
    public const ATI_TERMINAL_REFERENCE = 'terminal-ati';
    public const ICTSI_TERMINAL_REFERENCE = 'terminal-ictsi';
    public const CONTAINER_1_REFERENCE = 'container-1';
    public const CONTAINER_2_REFERENCE = 'container-2';
    public const CONTAINER_3_REFERENCE = 'container-3';

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Create Terminal Team user
        $terminalTeamUser = new StaffUser();
        $terminalTeamUser->setEmail('terminal.team@optimus.com');
        $terminalTeamUser->setRole(UserRole::TERMINAL_TEAM);
        $terminalTeamUser->setStatus(AccountStatus::APPROVED);
        $terminalTeamUser->setFirstName('Terminal');
        $terminalTeamUser->setLastName('Team');
        $terminalTeamUser->setDepartment('Operations');
        $terminalTeamUser->setPasswordHash($this->passwordHasher->hashPassword($terminalTeamUser, 'terminal123'));
        $manager->persist($terminalTeamUser);
        $this->addReference(self::TERMINAL_TEAM_USER_REFERENCE, $terminalTeamUser);

        // Create Trucker users (using StaffUser as base for simplicity)
        $trucker1 = new StaffUser();
        $trucker1->setEmail('trucker1@example.com');
        $trucker1->setRole(UserRole::TRUCKER);
        $trucker1->setStatus(AccountStatus::APPROVED);
        $trucker1->setFirstName('John');
        $trucker1->setLastName('Trucker');
        $trucker1->setDepartment('Transport');
        $trucker1->setPasswordHash($this->passwordHasher->hashPassword($trucker1, 'trucker123'));
        $manager->persist($trucker1);
        $this->addReference(self::TRUCKER_1_REFERENCE, $trucker1);

        $trucker2 = new StaffUser();
        $trucker2->setEmail('trucker2@example.com');
        $trucker2->setRole(UserRole::TRUCKER);
        $trucker2->setStatus(AccountStatus::APPROVED);
        $trucker2->setFirstName('Maria');
        $trucker2->setLastName('Driver');
        $trucker2->setDepartment('Transport');
        $trucker2->setPasswordHash($this->passwordHasher->hashPassword($trucker2, 'trucker123'));
        $manager->persist($trucker2);
        $this->addReference(self::TRUCKER_2_REFERENCE, $trucker2);

        // Create Terminals
        $cyTerminal = new Terminal();
        $cyTerminal->setName('Container Yard Terminal');
        $cyTerminal->setType(TerminalType::CY);
        $cyTerminal->setLocation('Port Area, Section A');
        $cyTerminal->setDailyCapacity(50);
        $cyTerminal->setIsActive(true);
        $manager->persist($cyTerminal);
        $this->addReference(self::CY_TERMINAL_REFERENCE, $cyTerminal);

        $atiTerminal = new Terminal();
        $atiTerminal->setName('ATI Terminal Facility');
        $atiTerminal->setType(TerminalType::ATI);
        $atiTerminal->setLocation('Port Area, Section B');
        $atiTerminal->setDailyCapacity(30);
        $atiTerminal->setIsActive(true);
        $manager->persist($atiTerminal);
        $this->addReference(self::ATI_TERMINAL_REFERENCE, $atiTerminal);

        $ictsiTerminal = new Terminal();
        $ictsiTerminal->setName('ICTSI Container Terminal');
        $ictsiTerminal->setType(TerminalType::ICTSI);
        $ictsiTerminal->setLocation('Port Area, Section C');
        $ictsiTerminal->setDailyCapacity(40);
        $ictsiTerminal->setIsActive(true);
        $manager->persist($ictsiTerminal);
        $this->addReference(self::ICTSI_TERMINAL_REFERENCE, $ictsiTerminal);

        // Create Terminal Slots for the next 7 days
        $terminals = [$cyTerminal, $atiTerminal, $ictsiTerminal];
        foreach ($terminals as $terminal) {
            for ($i = 0; $i < 7; $i++) {
                $date = new \DateTime();
                $date->add(new \DateInterval('P' . $i . 'D'));
                
                $slot = new TerminalSlot();
                $slot->setTerminal($terminal);
                $slot->setDate($date);
                $slot->setCapacity($terminal->getDailyCapacity());
                $slot->setAssignedCount(0);
                $slot->setStatus(SlotStatus::AVAILABLE);
                $manager->persist($slot);
            }
        }

        // Create sample Containers
        $container1 = new Container();
        $container1->setContainerNumber('MSKU1234567');
        $container1->setSize('20ft');
        $container1->setType('Dry');
        $container1->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container1->setCurrentLocation('Warehouse District A');
        $container1->setExpectedReturnDate(new \DateTime('+3 days'));
        $manager->persist($container1);
        $this->addReference(self::CONTAINER_1_REFERENCE, $container1);

        $container2 = new Container();
        $container2->setContainerNumber('TCLU9876543');
        $container2->setSize('40ft');
        $container2->setType('Dry');
        $container2->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container2->setCurrentLocation('Warehouse District B');
        $container2->setExpectedReturnDate(new \DateTime('+5 days'));
        $manager->persist($container2);
        $this->addReference(self::CONTAINER_2_REFERENCE, $container2);

        $container3 = new Container();
        $container3->setContainerNumber('HLBU5555555');
        $container3->setSize('40ft');
        $container3->setType('Reefer');
        $container3->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container3->setCurrentLocation('Cold Storage Area');
        $container3->setExpectedReturnDate(new \DateTime('+2 days'));
        $manager->persist($container3);
        $this->addReference(self::CONTAINER_3_REFERENCE, $container3);

        // Create additional test containers with different statuses
        $testContainers = [
            ['ABCD1111111', '20ft', 'Dry', ContainerStatus::IN_TRANSIT, 'En Route to Port'],
            ['EFGH2222222', '40ft', 'Dry', ContainerStatus::AT_TERMINAL, 'CY Terminal'],
            ['IJKL3333333', '20ft', 'Reefer', ContainerStatus::MAINTENANCE, 'Repair Shop'],
            ['MNOP4444444', '40ft', 'Dry', ContainerStatus::RETURNED, 'Completed'],
            ['QRST5555555', '20ft', 'Dry', ContainerStatus::AVAILABLE_FOR_RETURN, 'Customer Site'],
        ];

        foreach ($testContainers as $containerData) {
            $container = new Container();
            $container->setContainerNumber($containerData[0]);
            $container->setSize($containerData[1]);
            $container->setType($containerData[2]);
            $container->setStatus($containerData[3]);
            $container->setCurrentLocation($containerData[4]);
            $container->setExpectedReturnDate(new \DateTime('+' . rand(1, 10) . ' days'));
            $manager->persist($container);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}