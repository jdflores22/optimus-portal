<?php

namespace App\Tests\Functional;

use App\Entity\Container;
use App\Entity\TerminalTeamUser;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class TerminalTeamDwellTimeFunctionalTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    public function testGetDashboardMetricsReturnsSuccessForTerminalTeam(): void
    {
        // Arrange
        $client = static::createClient();
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $this->createContainer('TEST123456', 55);
        $this->createContainer('TEST789012', 65);

        // Simulate authentication
        $client->loginUser($terminalUser);

        // Act
        $client->request('GET', '/api/terminal-team/dwell-time/dashboard-metrics');

        // Assert
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('dwell_time_summary', $response['data']);
        $this->assertArrayHasKey('approaching_warning_count', $response['data']['dwell_time_summary']);
    }

    public function testGetContainerAlertStatusReturnsCorrectInformation(): void
    {
        // Arrange
        $client = static::createClient();
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $container = $this->createContainer('TEST123456', 55);
        $container->setStatus(ContainerStatus::ALERT);
        $container->setDwellTimePausedAt(new \DateTime());
        $this->entityManager->flush();

        $client->loginUser($terminalUser);

        // Act
        $client->request('GET', '/api/terminal-team/dwell-time/container/' . $container->getId() . '/alert-status');

        // Assert
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('TEST123456', $response['data']['container_number']);
        $this->assertTrue($response['data']['is_alerted']);
        $this->assertTrue($response['data']['is_dwell_time_paused']);
    }

    public function testDashboardMetricsRequiresAuthentication(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request('GET', '/api/terminal-team/dwell-time/dashboard-metrics');

        // Assert
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testContainerAlertStatusRequiresAuthentication(): void
    {
        // Arrange
        $client = static::createClient();
        $container = $this->createContainer('TEST123456', 55);

        // Act
        $client->request('GET', '/api/terminal-team/dwell-time/container/' . $container->getId() . '/alert-status');

        // Assert
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDashboardMetricsShowsContainersInDifferentDwellTimeCategories(): void
    {
        // Arrange
        $client = static::createClient();
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        
        // Create containers in different categories
        $this->createContainer('APPROACHING', 55); // Approaching warning (50-59 days)
        $this->createContainer('WARNING', 65);     // Warning issued (60-89 days)
        $this->createContainer('RETURNED', 92);    // Automatic return (90+ days)
        
        $alertedContainer = $this->createContainer('ALERTED', 50);
        $alertedContainer->setStatus(ContainerStatus::ALERT);
        $this->entityManager->flush();

        $client->loginUser($terminalUser);

        // Act
        $client->request('GET', '/api/terminal-team/dwell-time/dashboard-metrics');

        // Assert
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $summary = $response['data']['dwell_time_summary'];
        $this->assertEquals(1, $summary['approaching_warning_count']);
        $this->assertEquals(1, $summary['warning_issued_count']);
        $this->assertEquals(1, $summary['automatic_returns_count']);
        $this->assertEquals(1, $summary['alerted_containers_count']);
    }

    public function testDashboardMetricsIncludesContainerDetails(): void
    {
        // Arrange
        $client = static::createClient();
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $container = $this->createContainer('TEST123456', 55);

        $client->loginUser($terminalUser);

        // Act
        $client->request('GET', '/api/terminal-team/dwell-time/dashboard-metrics');

        // Assert
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('containers_approaching_warning', $response['data']);
        $this->assertCount(1, $response['data']['containers_approaching_warning']);
        
        $containerData = $response['data']['containers_approaching_warning'][0];
        $this->assertEquals('TEST123456', $containerData['container_number']);
        $this->assertEquals(55, $containerData['current_dwell_time']);
        $this->assertArrayHasKey('status', $containerData);
    }

    public function testAlertStatusInfoShowsPauseInformation(): void
    {
        // Arrange
        $client = static::createClient();
        $terminalUser = $this->createTerminalTeamUser('terminal@test.com');
        $container = $this->createContainer('TEST123456', 55);
        
        $pauseDate = new \DateTime('2024-01-15 10:00:00');
        $container->setStatus(ContainerStatus::ALERT);
        $container->setDwellTimePausedAt($pauseDate);
        $container->setTotalPausedDays(5);
        $this->entityManager->flush();

        $client->loginUser($terminalUser);

        // Act
        $client->request('GET', '/api/terminal-team/dwell-time/container/' . $container->getId() . '/alert-status');

        // Assert
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $data = $response['data'];
        $this->assertTrue($data['is_dwell_time_paused']);
        $this->assertEquals(5, $data['total_paused_days']);
        $this->assertNotNull($data['paused_at']);
    }

    private function createTerminalTeamUser(string $email): TerminalTeamUser
    {
        $user = new TerminalTeamUser();
        $user->setEmail($email);
        $user->setPasswordHash('hashed_password');
        $user->setFirstName('Terminal');
        $user->setLastName('User');
        $user->setDepartment('Operations');
        $user->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createContainer(string $containerNumber, int $dwellTime): Container
    {
        $container = new Container();
        $container->setContainerNumber($containerNumber);
        $container->setSize('40');
        $container->setType('Standard');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime("-{$dwellTime} days"));
        $container->setCurrentDwellTime($dwellTime);

        $this->entityManager->persist($container);
        $this->entityManager->flush();

        return $container;
    }

    private function cleanDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE notifications');
        $connection->executeStatement('TRUNCATE TABLE dwell_time_events');
        $connection->executeStatement('TRUNCATE TABLE containers');
        $connection->executeStatement('TRUNCATE TABLE terminal_team_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
