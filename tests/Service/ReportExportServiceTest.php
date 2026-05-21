<?php

namespace App\Tests\Service;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\Trucker;
use App\Entity\TerminalTeamUser;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\ContainerStatus;
use App\Service\ReportingService;
use App\Service\ReportExportService;
use App\Repository\PreAdviceRequestRepository;
use App\Repository\TerminalRepository;
use App\Repository\TerminalSlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unit tests for ReportExportService to verify export functionality
 */
class ReportExportServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ReportingService $reportingService;
    private ReportExportService $reportExportService;
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->projectDir = $container->getParameter('kernel.project_dir');
        
        $preAdviceRequestRepository = $this->entityManager->getRepository(PreAdviceRequest::class);
        $terminalRepository = $this->entityManager->getRepository(Terminal::class);
        $terminalSlotRepository = $this->entityManager->getRepository(\App\Entity\TerminalSlot::class);
        
        $this->reportingService = new ReportingService(
            $preAdviceRequestRepository,
            $terminalRepository,
            $terminalSlotRepository
        );
        
        $this->reportExportService = new ReportExportService(
            $this->reportingService,
            $this->projectDir
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test files
        $reportsDir = $this->projectDir . '/var/reports';
        if (is_dir($reportsDir)) {
            $files = glob($reportsDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE pre_advice_requests');
        $connection->executeStatement('TRUNCATE TABLE terminals');
        $connection->executeStatement('TRUNCATE TABLE containers');
        $connection->executeStatement('TRUNCATE TABLE truckers');
        $connection->executeStatement('TRUNCATE TABLE terminal_team_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    public function testExportPreAdviceStatisticsToPDF(): void
    {
        // Create test data
        $this->createTestData();

        // Export to PDF
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $filePath = $this->reportExportService->exportPreAdviceStatisticsToPDF($startDate, $endDate);

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify file is a valid PDF (starts with PDF header)
        $fileContent = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $fileContent);
    }

    public function testExportTerminalUtilizationToPDF(): void
    {
        // Create test data
        $this->createTestData();

        // Export to PDF
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $filePath = $this->reportExportService->exportTerminalUtilizationToPDF($startDate, $endDate);

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify file is a valid PDF
        $fileContent = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $fileContent);
    }

    public function testExportPreAdviceStatisticsToCSV(): void
    {
        // Create test data
        $this->createTestData();

        // Export to CSV
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $filePath = $this->reportExportService->exportPreAdviceStatisticsToCSV($startDate, $endDate);

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.csv', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify CSV structure
        $csvContent = file_get_contents($filePath);
        $this->assertStringContainsString('Pre-Advice Statistics Report', $csvContent);
        $this->assertStringContainsString('Summary Statistics', $csvContent);
        $this->assertStringContainsString('Approval Metrics', $csvContent);
        $this->assertStringContainsString('Daily Trends', $csvContent);
    }

    public function testExportTerminalUtilizationToCSV(): void
    {
        // Create test data
        $this->createTestData();

        // Export to CSV
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $filePath = $this->reportExportService->exportTerminalUtilizationToCSV($startDate, $endDate);

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.csv', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify CSV structure
        $csvContent = file_get_contents($filePath);
        $this->assertStringContainsString('Terminal Utilization Report', $csvContent);
        $this->assertStringContainsString('Terminal Utilization', $csvContent);
        $this->assertStringContainsString('Terminal Type Statistics', $csvContent);
    }

    public function testGenerateComprehensiveReportPDF(): void
    {
        // Create test data
        $this->createTestData();

        // Generate comprehensive PDF report
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $filePath = $this->reportExportService->generateComprehensiveReport($startDate, $endDate, 'pdf');

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);
        $this->assertStringContainsString('comprehensive_report', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify file is a valid PDF
        $fileContent = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $fileContent);
    }

    public function testGenerateComprehensiveReportCSV(): void
    {
        // Create test data
        $this->createTestData();

        // Generate comprehensive CSV report
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $filePath = $this->reportExportService->generateComprehensiveReport($startDate, $endDate, 'csv');

        // Verify file was created
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.csv', $filePath);
        $this->assertStringContainsString('comprehensive_report', $filePath);
        
        // Verify file has content
        $this->assertGreaterThan(0, filesize($filePath));
        
        // Verify CSV structure
        $csvContent = file_get_contents($filePath);
        $this->assertStringContainsString('Comprehensive Pre-Advice Report', $csvContent);
    }

    public function testCleanupOldReports(): void
    {
        // Create test files with different ages
        $reportsDir = $this->projectDir . '/var/reports';
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }

        // Create a recent file (should not be deleted)
        $recentFile = $reportsDir . '/recent_report.pdf';
        file_put_contents($recentFile, 'test content');
        touch($recentFile, time() - (7 * 24 * 3600)); // 7 days old

        // Create an old file (should be deleted)
        $oldFile = $reportsDir . '/old_report.pdf';
        file_put_contents($oldFile, 'test content');
        touch($oldFile, time() - (35 * 24 * 3600)); // 35 days old

        // Run cleanup
        $deletedCount = $this->reportExportService->cleanupOldReports();

        // Verify results
        $this->assertEquals(1, $deletedCount);
        $this->assertFileExists($recentFile);
        $this->assertFileDoesNotExist($oldFile);
    }

    public function testFileNamingConventions(): void
    {
        // Create test data
        $this->createTestData();

        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-31');

        // Test PDF naming
        $pdfPath = $this->reportExportService->exportPreAdviceStatisticsToPDF($startDate, $endDate);
        $this->assertStringContainsString('pre_advice_statistics_2024-01-01_to_2024-01-31.pdf', $pdfPath);

        // Test CSV naming
        $csvPath = $this->reportExportService->exportPreAdviceStatisticsToCSV($startDate, $endDate);
        $this->assertStringContainsString('pre_advice_statistics_2024-01-01_to_2024-01-31.csv', $csvPath);

        // Test comprehensive report naming
        $comprehensivePath = $this->reportExportService->generateComprehensiveReport($startDate, $endDate, 'pdf');
        $this->assertStringContainsString('comprehensive_report_2024-01-01_to_2024-01-31.pdf', $comprehensivePath);
    }

    public function testDirectoryCreation(): void
    {
        // Remove reports directory if it exists
        $reportsDir = $this->projectDir . '/var/reports';
        if (is_dir($reportsDir)) {
            rmdir($reportsDir);
        }

        // Create test data
        $this->createTestData();

        // Export report (should create directory)
        $startDate = new \DateTime('-1 day');
        $endDate = new \DateTime('+1 day');
        $filePath = $this->reportExportService->exportPreAdviceStatisticsToPDF($startDate, $endDate);

        // Verify directory was created
        $this->assertDirectoryExists($reportsDir);
        $this->assertFileExists($filePath);
    }

    // Helper method to create test data
    private function createTestData(): void
    {
        // Create terminal
        $terminal = new Terminal();
        $terminal->setName('Test Terminal');
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation('Test Location');
        $terminal->setDailyCapacity(100);
        $terminal->setIsActive(true);
        $this->entityManager->persist($terminal);

        // Create trucker
        $trucker = new Trucker();
        $trucker->setEmail('test@trucker.com');
        $trucker->setPasswordHash('hashed_password');
        $trucker->setCompanyName('Test Trucking Co');
        $trucker->setContactPerson('Test Driver');
        $trucker->setPhoneNumber('+1234567890');
        $this->entityManager->persist($trucker);

        // Create container
        $container = new Container();
        $container->setContainerNumber('CONT123456');
        $container->setSize('40ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container->setCurrentLocation('Test Port');
        $container->setExpectedReturnDate(new \DateTime('+7 days'));
        $this->entityManager->persist($container);

        // Create terminal team user
        $terminalTeamUser = new TerminalTeamUser();
        $terminalTeamUser->setEmail('team@terminal.com');
        $terminalTeamUser->setPasswordHash('hashed_password');
        $terminalTeamUser->setFirstName('Terminal');
        $terminalTeamUser->setLastName('Team');
        $this->entityManager->persist($terminalTeamUser);

        // Create pre-advice requests
        $request1 = new PreAdviceRequest();
        $request1->setTrucker($trucker);
        $request1->setContainer($container);
        $request1->setSelectedTerminal($terminal);
        $request1->setStatus(PreAdviceStatus::VERIFIED);
        $request1->setPaymentReference('PAY001');
        $request1->setVerifiedBy($terminalTeamUser);
        $request1->setVerifiedAt(new \DateTime());
        $this->entityManager->persist($request1);

        $request2 = new PreAdviceRequest();
        $request2->setTrucker($trucker);
        $request2->setContainer($container);
        $request2->setSelectedTerminal($terminal);
        $request2->setStatus(PreAdviceStatus::REJECTED);
        $request2->setPaymentReference('PAY002');
        $request2->setVerifiedBy($terminalTeamUser);
        $request2->setVerifiedAt(new \DateTime());
        $request2->setRejectionReason('Photo quality issues');
        $this->entityManager->persist($request2);

        $this->entityManager->flush();
    }
}