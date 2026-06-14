<?php

namespace App\Controller;

use App\Service\ManifestService;
use App\Service\ManifestAuthorizationService;
use App\Service\NOAService;
use App\Service\BillingService;
use App\Service\UserService;
use App\Service\ManifestBLDocumentGenerator;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\WorkflowState;
use App\Entity\NOA;
use App\Entity\User;
use App\Security\Voter\NOAVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manifest-workflow')]
class ManifestWorkflowController extends AbstractController
{
    public function __construct(
        private ManifestService $manifestService,
        private ManifestAuthorizationService $authorizationService,
        private NOAService $noaService,
        private BillingService $billingService,
        private UserService $userService,
        private EntityManagerInterface $entityManager,
        private ManifestBLDocumentGenerator $manifestBLGenerator,
        private \App\Service\InAppNotificationService $notificationService,
        private \App\Service\ManifestNotificationService $manifestNotificationService,
        private \App\Service\AuditService $auditService
    ) {
    }

    #[Route('', name: 'manifest_workflow_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        // Only SL_STAFF and ACCOUNTING can access manifest workflow list
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        // Clear entity manager to get fresh data from database
        $this->entityManager->clear();
        
        $user = $this->getUser();
        
        // Get filter parameters
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $consignee = $request->query->get('consignee');
        $blNumber = $request->query->get('bl_number');
        $status = $request->query->get('status');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        // Calculate UNFILTERED statistics for the cards (always show total counts)
        $statsQb = $this->entityManager->getRepository(\App\Entity\NOA::class)
            ->createQueryBuilder('n')
            ->leftJoin(\App\Entity\Manifest::class, 'm', 'WITH', 'm.noa = n.id');
        
        $allNoas = $statsQb->getQuery()->getResult();
        
        $stats = [
            'total' => count($allNoas),
            'noa_generated' => 0,
            'bl_generated' => 0,
            'bl_uploaded' => 0,
            'billing_generated' => 0,
            'payment_verified' => 0,
            'edo_generated' => 0,
            'edo_released' => 0,
            'noa_only' => 0,
        ];
        
        foreach ($allNoas as $noa) {
            $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->findOneBy(['noa' => $noa]);
            
            if ($manifest) {
                $statusValue = $manifest->getWorkflowState()->value;
                if (isset($stats[$statusValue])) {
                    $stats[$statusValue]++;
                } else {
                    $stats[$statusValue] = 1;
                }
            } else {
                $stats['noa_only']++;
            }
        }

        // Build query for FILTERED NOAs for the table
        $qb = $this->entityManager->getRepository(\App\Entity\NOA::class)
            ->createQueryBuilder('n')
            ->leftJoin('n.consignee', 'c')
            ->leftJoin('n.createdBy', 'u')
            ->leftJoin(\App\Entity\Manifest::class, 'm', 'WITH', 'm.noa = n.id')
            ->orderBy('n.createdAt', 'DESC');

        // Apply filters
        if ($dateFrom) {
            $qb->andWhere('n.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo) {
            $qb->andWhere('n.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        if ($consignee) {
            $qb->andWhere('c.businessName LIKE :consignee')
               ->setParameter('consignee', '%' . $consignee . '%');
        }

        if ($blNumber) {
            $qb->andWhere('n.blNumber LIKE :blNumber')
               ->setParameter('blNumber', '%' . $blNumber . '%');
        }

        // Apply status filter
        if ($status) {
            if ($status === 'noa_only') {
                // Filter for NOAs without manifests
                $qb->andWhere('m.id IS NULL');
            } else {
                // Filter by manifest workflow state
                $qb->andWhere('m.workflowState = :status')
                   ->setParameter('status', $status);
            }
        }

        // Pagination
        $totalQuery = clone $qb;
        $total = count($totalQuery->getQuery()->getResult());
        $totalPages = ceil($total / $limit);

        $noas = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Get manifest information for each NOA to display status
        $noasWithManifests = [];
        foreach ($noas as $noa) {
            $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->findOneBy(['noa' => $noa]);
            
            // Count containers with eDOs for this NOA
            $totalContainers = $noa->getContainers()->count();
            $containersWithEdo = 0;
            
            foreach ($noa->getContainers() as $container) {
                $edo = $container->getCurrentEDO();
                if ($edo) {
                    $containersWithEdo++;
                }
            }
            
            $noasWithManifests[] = [
                'noa' => $noa,
                'manifest' => $manifest,
                'total_containers' => $totalContainers,
                'containers_with_edo' => $containersWithEdo,
                'edo_progress' => $totalContainers > 0 ? round(($containersWithEdo / $totalContainers) * 100) : 0,
                'all_edos_generated' => $containersWithEdo == $totalContainers && $totalContainers > 0,
            ];
        }

        return $this->render('manifest_workflow/list.html.twig', [
            'noas' => $noasWithManifests,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'stats' => $stats,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'consignee' => $consignee,
                'bl_number' => $blNumber,
                'status' => $status,
            ],
            'workflowStates' => \App\Entity\Enum\WorkflowState::cases(),
        ]);
    }

    #[Route('/create', name: 'manifest_workflow_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        // Only SL_STAFF can create NOA
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        if ($request->isMethod('POST')) {
            // Form submission handled via JavaScript/API
            return $this->redirectToRoute('manifest_workflow_list');
        }

        // Simply render the NOA creation page
        return $this->render('manifest_workflow/upload.html.twig');
    }

    #[Route('/bulk-import', name: 'manifest_workflow_bulk_import', methods: ['GET', 'POST'])]
    public function bulkImport(Request $request): Response
    {
        // Only SL_STAFF can bulk import NOAs
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        if ($request->isMethod('POST')) {
            $file = $request->files->get('import_file');
            $skipDuplicates = $request->request->getBoolean('skip_duplicates', true);
            $validateOnly = $request->request->getBoolean('validate_only', false);
            
            if (!$file) {
                $this->addFlash('error', 'Please select a file to upload.');
                return $this->redirectToRoute('manifest_workflow_bulk_import');
            }
            
            // Validate file type - check both extension and MIME type
            $allowedExtensions = ['csv', 'xlsx', 'xls'];
            $allowedMimeTypes = [
                'text/csv',
                'text/plain',
                'application/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/octet-stream' // Some systems report CSV as this
            ];
            
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();
            
            // Check extension
            if (!in_array($extension, $allowedExtensions)) {
                $this->addFlash('error', 'Invalid file extension. Only CSV, XLSX, and XLS files are allowed.');
                return $this->redirectToRoute('manifest_workflow_bulk_import');
            }
            
            // Check MIME type
            if (!in_array($mimeType, $allowedMimeTypes)) {
                $this->addFlash('error', sprintf(
                    'Invalid file type detected. The file appears to be "%s" but only CSV and Excel files are allowed. Please ensure you are uploading a valid spreadsheet file.',
                    $mimeType
                ));
                return $this->redirectToRoute('manifest_workflow_bulk_import');
            }
            
            // Additional validation: Try to read the file as CSV to verify it's actually a valid CSV/spreadsheet
            try {
                $testHandle = fopen($file->getPathname(), 'r');
                if ($testHandle === false) {
                    throw new \Exception('Cannot read file');
                }
                
                // Try to read first line as CSV
                $firstLine = fgetcsv($testHandle);
                fclose($testHandle);
                
                if ($firstLine === false || empty($firstLine)) {
                    throw new \Exception('File appears to be empty or not a valid CSV');
                }
                
                // Check if header contains expected columns
                $requiredColumns = ['bl_number', 'vessel_number', 'eta', 'consignee_email', 'port_location', 'containers'];
                $missingColumns = array_diff($requiredColumns, $firstLine);

                if (!empty($missingColumns)) {
                    $legacyCyColumn = in_array('cy_location', $firstLine, true) && !in_array('port_location', $firstLine, true);
                    $this->addFlash('error', $legacyCyColumn
                        ? 'The cy_location column has been replaced with port_location. Please download the updated CSV template.'
                        : sprintf(
                            'Invalid CSV format. Missing required columns: %s. Please use the template provided.',
                            implode(', ', $missingColumns)
                        ));
                    return $this->redirectToRoute('manifest_workflow_bulk_import');
                }
            } catch (\Exception $e) {
                $this->addFlash('error', 'The uploaded file is not a valid CSV or Excel file. Please ensure you are uploading a properly formatted spreadsheet.');
                return $this->redirectToRoute('manifest_workflow_bulk_import');
            }
            
            // Validate file size (5MB max)
            if ($file->getSize() > 5 * 1024 * 1024) {
                $this->addFlash('error', 'File size exceeds 5MB limit.');
                return $this->redirectToRoute('manifest_workflow_bulk_import');
            }
            
            try {
                // For validation only, process synchronously
                if ($validateOnly) {
                    $result = $this->noaService->processBulkImport(
                        $file->getPathname(),
                        $extension,
                        $this->getUser(),
                        $skipDuplicates,
                        true
                    );
                    
                    if ($result['valid']) {
                        $this->addFlash('success', sprintf(
                            'Validation successful! %d NOAs are ready to import. Upload again without "Validate only" to proceed.',
                            $result['valid_count']
                        ));
                    } else {
                        $this->addFlash('error', sprintf(
                            'Validation failed. %d errors found. Please fix the errors and try again.',
                            count($result['errors'])
                        ));
                    }
                    
                    return $this->render('manifest_workflow/bulk_import_preview.html.twig', [
                        'result' => $result,
                        'validateOnly' => true
                    ]);
                }
                
                // For actual import, use async processing with progress tracking
                // Generate unique import ID
                $importId = uniqid('import_', true);
                
                // Store file temporarily
                $tempDir = sys_get_temp_dir() . '/noa_imports';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }
                $tempFilePath = $tempDir . '/' . $importId . '.' . $extension;
                move_uploaded_file($file->getPathname(), $tempFilePath);
                
                // Count total rows for progress tracking
                $handle = fopen($tempFilePath, 'r');
                $totalRows = 0;
                fgetcsv($handle); // Skip header
                while (fgetcsv($handle) !== false) {
                    $totalRows++;
                }
                fclose($handle);
                
                // Initialize progress in session
                $session = $request->getSession();
                $session->set("import_progress_{$importId}", [
                    'total' => $totalRows,
                    'processed' => 0,
                    'successCount' => 0,
                    'errorCount' => 0,
                    'skippedCount' => 0,
                    'status' => 'Starting import...',
                    'complete' => false,
                    'filePath' => $tempFilePath,
                    'extension' => $extension,
                    'skipDuplicates' => $skipDuplicates,
                    'lastUpdate' => null,
                    'errors' => [],
                    'imported_noas' => [],
                    'skipped' => []
                ]);
                
                // Redirect to progress page
                return $this->redirectToRoute('manifest_workflow_bulk_import_progress', [
                    'importId' => $importId
                ]);
                
            } catch (\Exception $e) {
                $this->addFlash('error', 'Import failed: ' . $e->getMessage());
                return $this->redirectToRoute('manifest_workflow_bulk_import');
            }
        }
        
        return $this->render('manifest_workflow/bulk_import.html.twig');
    }
    
    #[Route('/bulk-import/progress/{importId}', name: 'manifest_workflow_bulk_import_progress', methods: ['GET'])]
    public function bulkImportProgress(string $importId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        $session = $request->getSession();
        $progress = $session->get("import_progress_{$importId}");
        
        if (!$progress) {
            $this->addFlash('error', 'Import session not found.');
            return $this->redirectToRoute('manifest_workflow_bulk_import');
        }
        
        return $this->render('manifest_workflow/bulk_import_progress.html.twig', [
            'importId' => $importId,
            'totalRows' => $progress['total']
        ]);
    }
    
    #[Route('/bulk-import/progress-check/{importId}', name: 'manifest_workflow_bulk_import_progress_check', methods: ['GET'])]
    public function bulkImportProgressCheck(string $importId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        $session = $request->getSession();
        $progress = $session->get("import_progress_{$importId}");
        
        if (!$progress) {
            return $this->json(['error' => 'Import session not found'], 404);
        }
        
        // Only process if not complete AND has more rows to process
        if (!$progress['complete'] && $progress['processed'] < $progress['total']) {
            try {
                $this->processNextImportRow($importId, $session);
                // Refresh progress after processing
                $progress = $session->get("import_progress_{$importId}");
            } catch (\Exception $e) {
                error_log('Import processing error: ' . $e->getMessage());
                // Mark as complete on error to prevent infinite loop
                $progress['complete'] = true;
                $progress['status'] = 'Import failed: ' . $e->getMessage();
                $session->set("import_progress_{$importId}", $progress);
            }
        } else {
            // Ensure complete flag is set
            $progress['complete'] = true;
            $session->set("import_progress_{$importId}", $progress);
        }
        
        return $this->json([
            'total' => $progress['total'],
            'processed' => $progress['processed'],
            'successCount' => $progress['successCount'],
            'errorCount' => $progress['errorCount'],
            'skippedCount' => $progress['skippedCount'],
            'status' => $progress['status'],
            'complete' => $progress['complete'],
            'lastUpdate' => $progress['lastUpdate'],
            'redirectUrl' => $progress['complete'] ? $this->generateUrl('manifest_workflow_bulk_import_results', ['importId' => $importId]) : null
        ]);
    }
    
    #[Route('/bulk-import/results/{importId}', name: 'manifest_workflow_bulk_import_results', methods: ['GET'])]
    public function bulkImportResults(string $importId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        $session = $request->getSession();
        $progress = $session->get("import_progress_{$importId}");
        
        if (!$progress) {
            $this->addFlash('error', 'Import session not found.');
            return $this->redirectToRoute('manifest_workflow_bulk_import');
        }
        
        // Clean up temp file
        if (isset($progress['filePath']) && file_exists($progress['filePath'])) {
            @unlink($progress['filePath']);
        }
        
        // Build result array
        $result = [
            'total_count' => $progress['total'],
            'success_count' => $progress['successCount'],
            'error_count' => $progress['errorCount'],
            'skipped_count' => $progress['skippedCount'],
            'errors' => $progress['errors'],
            'imported_noas' => $progress['imported_noas'],
            'skipped' => $progress['skipped'] ?? [],
            'valid' => $progress['errorCount'] === 0
        ];
        
        // Clear session data
        $session->remove("import_progress_{$importId}");
        
        return $this->render('manifest_workflow/bulk_import_preview.html.twig', [
            'result' => $result,
            'validateOnly' => false
        ]);
    }
    
    private function processNextImportRow(string $importId, $session): void
    {
        $progress = $session->get("import_progress_{$importId}");
        
        // Safety check: ensure we don't process beyond total rows
        if (!$progress || $progress['complete'] || $progress['processed'] >= $progress['total']) {
            if ($progress) {
                $progress['complete'] = true;
                $progress['status'] = 'Import complete!';
                $session->set("import_progress_{$importId}", $progress);
            }
            return;
        }
        
        // Read and process one row
        $handle = fopen($progress['filePath'], 'r');
        fgetcsv($handle); // Skip header
        
        // Skip to current row
        for ($i = 0; $i < $progress['processed']; $i++) {
            fgetcsv($handle);
        }
        
        // Read next row
        $row = fgetcsv($handle);
        fclose($handle);
        
        if ($row === false) {
            $progress['complete'] = true;
            $progress['status'] = 'Import complete!';
            $session->set("import_progress_{$importId}", $progress);
            return;
        }
        
        // Process this row
        $rowNumber = $progress['processed'] + 2; // +2 because: +1 for header, +1 for 1-based indexing
        
        try {
            // Parse row data
            $data = $this->parseImportRowData($row, $rowNumber);
            
            // Check for duplicates
            if ($progress['skipDuplicates']) {
                $existing = $this->entityManager->getRepository(\App\Entity\NOA::class)
                    ->findOneBy(['blNumber' => $data['bl_number']]);
                
                if ($existing) {
                    $progress['skippedCount']++;
                    
                    // Add to skipped array for results page
                    if (!isset($progress['skipped'])) {
                        $progress['skipped'] = [];
                    }
                    $progress['skipped'][] = [
                        'row' => $rowNumber,
                        'noa_number' => 'Auto-generated',
                        'reason' => 'BL number already exists: ' . $data['bl_number']
                    ];
                    
                    $progress['lastUpdate'] = [
                        'message' => "Row {$rowNumber}: Skipped - BL number already exists",
                        'type' => 'warning'
                    ];
                    $progress['processed']++;
                    $progress['status'] = "Processing row {$rowNumber} of {$progress['total']}...";
                    $session->set("import_progress_{$importId}", $progress);
                    
                    // DON'T process next row - let the next poll request handle it
                    return;
                }
            }
            
            // Create NOA (this will generate PDF and send notification)
            $noa = $this->noaService->createNOAFromImportData($data, $this->getUser());
            
            $progress['successCount']++;
            $progress['imported_noas'][] = $noa;
            $progress['lastUpdate'] = [
                'message' => "Row {$rowNumber}: Successfully created NOA {$noa->getNoaNumber()}",
                'type' => 'success'
            ];
            
        } catch (\Exception $e) {
            $progress['errorCount']++;
            $progress['errors'][] = [
                'row' => $rowNumber,
                'noa_number' => 'N/A',
                'message' => $e->getMessage()
            ];
            $progress['lastUpdate'] = [
                'message' => "Row {$rowNumber}: Error - " . $e->getMessage(),
                'type' => 'error'
            ];
        }
        
        $progress['processed']++;
        $progress['status'] = "Processing row {$rowNumber} of {$progress['total']}...";
        $session->set("import_progress_{$importId}", $progress);
        
        // DON'T process next row - let the next poll request handle it
        // This allows the progress bar to update between rows
    }
    
    private function parseImportRowData(array $row, int $rowNumber): array
    {
        if (count($row) < 6) {
            throw new \Exception('Invalid row format. Expected 6 columns.');
        }

        $data = [
            'bl_number' => trim($row[0]),
            'vessel_number' => trim($row[1]),
            'eta' => trim($row[2]),
            'consignee_email' => trim($row[3]),
            'port_location' => trim($row[4]),
            'containers_raw' => trim($row[5])
        ];

        // Use NOAService to parse and validate the full row
        return $this->noaService->parseAndValidateImportRow($data, $rowNumber);
    }

    #[Route('/bulk-import/template', name: 'manifest_workflow_download_template', methods: ['GET'])]
    public function downloadTemplate(): Response
    {
        // Only SL_STAFF can download template
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        // Create CSV content (NOA number is auto-generated, so not included)
        // Use terminal codes (name field) instead of full location
        $csv = [
            ['bl_number', 'vessel_number', 'eta', 'consignee_email', 'port_location', 'containers'],
            ['BL-2024-001', 'VESSEL-001', '2024-12-31 14:30:00', 'consignee@example.com', 'ATI', 'CONT001|20|DRY|ATI|CONT002|40|REEFER|ICTSI'],
            ['BL-2024-002', 'VESSEL-002', '2024-12-31 16:00:00', 'consignee@example.com', 'ICTSI', 'CONT003|20|DRY|ICTSI'],
        ];
        
        $filename = 'noa_bulk_import_template_' . date('Y-m-d') . '.csv';
        
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        
        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);
        
        return $response;
    }
    
    #[Route('/noa/{id}', name: 'manifest_workflow_noa_detail', methods: ['GET'])]
    public function noaDetail(int $id): Response
    {
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            throw $this->createNotFoundException('NOA not found');
        }

        $this->assertCanViewNoa($noa);

        // Find associated manifest if it exists
        $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->findOneBy(['noa' => $noa]);

        // Fetch eDO payment status for each container
        $containerPaymentStatus = [];
        if ($noa->getContainers()) {
            foreach ($noa->getContainers() as $container) {
                $containerPaymentStatus[$container->getId()] = $this->getContainerEDOPaymentStatus($container);
            }
        }

        // Fetch audit logs for this NOA and all related entities
        $auditLogs = [];
        
        // 1. Get NOA audit logs
        $noaLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
            ->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', 'NOA')
            ->setParameter('entityId', $id)
            ->getQuery()
            ->getResult();
        $auditLogs = array_merge($auditLogs, $noaLogs);
        
        // 2. Get Manifest audit logs if manifest exists
        if ($manifest) {
            $manifestLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                ->createQueryBuilder('a')
                ->where('a.entityType = :entityType')
                ->andWhere('a.entityId = :entityId')
                ->setParameter('entityType', 'Manifest')
                ->setParameter('entityId', $manifest->getId())
                ->getQuery()
                ->getResult();
            $auditLogs = array_merge($auditLogs, $manifestLogs);
            
            // 3. Get Payment audit logs
            $payments = $manifest->getPayments();
            if ($payments && count($payments) > 0) {
                foreach ($payments as $payment) {
                    $paymentLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                        ->createQueryBuilder('a')
                        ->where('a.entityType = :entityType')
                        ->andWhere('a.entityId = :entityId')
                        ->setParameter('entityType', 'Payment')
                        ->setParameter('entityId', $payment->getId())
                        ->getQuery()
                        ->getResult();
                    $auditLogs = array_merge($auditLogs, $paymentLogs);
                }
            }
            
            // 4. Get EDO audit logs
            $edos = $manifest->getEdos();
            if ($edos && count($edos) > 0) {
                foreach ($edos as $edo) {
                    $edoLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                        ->createQueryBuilder('a')
                        ->where('a.entityType = :entityType')
                        ->andWhere('a.entityId = :entityId')
                        ->setParameter('entityType', 'ElectronicDeliveryOrder')
                        ->setParameter('entityId', $edo->getId())
                        ->getQuery()
                        ->getResult();
                    $auditLogs = array_merge($auditLogs, $edoLogs);
                }
            }
            
            // 5. Get EDO Payment audit logs
            $edoPayments = $manifest->getEdoPayments();
            if ($edoPayments && count($edoPayments) > 0) {
                foreach ($edoPayments as $edoPayment) {
                    $edoPaymentLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                        ->createQueryBuilder('a')
                        ->where('a.entityType = :entityType')
                        ->andWhere('a.entityId = :entityId')
                        ->setParameter('entityType', 'EDOPayment')
                        ->setParameter('entityId', $edoPayment->getId())
                        ->getQuery()
                        ->getResult();
                    $auditLogs = array_merge($auditLogs, $edoPaymentLogs);
                }
            }
        }
        
        // 6. Get Container audit logs
        $containers = $noa->getContainers();
        if ($containers && count($containers) > 0) {
            foreach ($containers as $container) {
                $containerLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                    ->createQueryBuilder('a')
                    ->where('a.entityType = :entityType')
                    ->andWhere('a.entityId = :entityId')
                    ->setParameter('entityType', 'Container')
                    ->setParameter('entityId', $container->getId())
                    ->getQuery()
                    ->getResult();
                $auditLogs = array_merge($auditLogs, $containerLogs);
            }
        }
        
        // Sort all logs by timestamp descending (newest first)
        usort($auditLogs, function($a, $b) {
            return $b->getTimestamp() <=> $a->getTimestamp();
        });

        return $this->render('manifest_workflow/noa_detail.html.twig', [
            'noa' => $noa,
            'manifest' => $manifest,
            'containerPaymentStatus' => $containerPaymentStatus,
            'auditLogs' => $auditLogs,
        ]);
    }

    #[Route('/noa/{id}/edit', name: 'manifest_workflow_noa_edit_page', methods: ['GET'])]
    public function editNoaPage(int $id): Response
    {
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            throw $this->createNotFoundException('NOA not found');
        }

        $this->assertCanEditNoa($noa);

        // Get all consignees for dropdown
        $consignees = $this->entityManager->getRepository(\App\Entity\Consignee::class)
            ->createQueryBuilder('c')
            ->orderBy('c.businessName', 'ASC')
            ->getQuery()
            ->getResult();

        // Get container sizes and types
        $containerSizes = $this->entityManager->getRepository(\App\Entity\ContainerSize::class)
            ->findAll();
        $containerTypes = $this->entityManager->getRepository(\App\Entity\ContainerType::class)
            ->findAll();

        return $this->render('manifest_workflow/noa_edit.html.twig', [
            'noa' => $noa,
            'consignees' => $consignees,
            'containerSizes' => $containerSizes,
            'containerTypes' => $containerTypes,
        ]);
    }

    #[Route('/noa/{id}/update', name: 'manifest_workflow_noa_update', methods: ['POST'])]
    public function updateNoa(int $id, Request $request): Response
    {
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            $this->addFlash('error', 'NOA not found');
            return $this->redirectToRoute('manifest_workflow_list');
        }

        $this->assertCanEditNoa($noa);

        // Store old values for change tracking
        $oldConsignee = $noa->getConsignee();
        $oldValues = [
            'blNumber' => $noa->getBlNumber(),
            'vesselNumber' => $noa->getVesselNumber(),
            'eta' => $noa->getEta(),
            'portLocation' => $noa->getPortLocation(),
            'consignee' => ($oldConsignee instanceof \App\Entity\Consignee) ? $oldConsignee->getBusinessName() : ($oldConsignee ? $oldConsignee->getEmail() : 'N/A'),
        ];

        try {
            // Update NOA basic fields
            $noa->setBlNumber($request->request->get('blNumber'));
            $noa->setVesselNumber($request->request->get('vesselNumber'));
            $noa->setEta(new \DateTime($request->request->get('eta')));
            $noa->setPortLocation($request->request->get('portLocation') ?: null);

            // Update consignee
            $consigneeId = $request->request->get('consigneeId');
            if ($consigneeId) {
                $consignee = $this->entityManager->getRepository(\App\Entity\Consignee::class)->find($consigneeId);
                if ($consignee) {
                    $noa->setConsignee($consignee);
                }
            }

            // Handle containers
            $containersData = $request->request->all('containers');
            $deletedContainers = $request->request->all('deletedContainers');

            // Delete marked containers
            if (!empty($deletedContainers)) {
                foreach ($deletedContainers as $containerId) {
                    $container = $this->entityManager->getRepository(\App\Entity\Container::class)->find($containerId);
                    if ($container && $container->getNoa() === $noa) {
                        $this->entityManager->remove($container);
                    }
                }
            }

            // Update/Create containers
            if (!empty($containersData)) {
                foreach ($containersData as $index => $containerData) {
                    if (isset($containerData['id']) && !empty($containerData['id'])) {
                        // Update existing container
                        $container = $this->entityManager->getRepository(\App\Entity\Container::class)->find($containerData['id']);
                        if ($container && $container->getNoa() === $noa) {
                            $container->setContainerNumber($containerData['containerNumber']);
                            
                            if (!empty($containerData['containerSizeId'])) {
                                $size = $this->entityManager->getRepository(\App\Entity\ContainerSize::class)->find($containerData['containerSizeId']);
                                if ($size) {
                                    $container->setContainerSize($size);
                                }
                            }
                            
                            if (!empty($containerData['containerTypeId'])) {
                                $type = $this->entityManager->getRepository(\App\Entity\ContainerType::class)->find($containerData['containerTypeId']);
                                if ($type) {
                                    $container->setContainerType($type);
                                }
                            }
                        }
                    } else {
                        // Create new container - skip if no container number
                        if (empty($containerData['containerNumber'])) {
                            continue;
                        }
                        
                        $container = new \App\Entity\Container();
                        $container->setContainerNumber($containerData['containerNumber']);
                        $container->setNoa($noa);
                        $container->setAllocationStatus(\App\Entity\Enum\AllocationStatus::PRE_FORECAST);
                        
                        if (!empty($containerData['containerSizeId'])) {
                            $size = $this->entityManager->getRepository(\App\Entity\ContainerSize::class)->find($containerData['containerSizeId']);
                            if ($size) {
                                $container->setContainerSize($size);
                            }
                        }
                        
                        if (!empty($containerData['containerTypeId'])) {
                            $type = $this->entityManager->getRepository(\App\Entity\ContainerType::class)->find($containerData['containerTypeId']);
                            if ($type) {
                                $container->setContainerType($type);
                            }
                        }
                        
                        $this->entityManager->persist($container);
                    }
                }
            }

            // Flush all changes
            $this->entityManager->flush();

            // Track changes for notifications
            $changes = [];
            if ($oldValues['blNumber'] !== $noa->getBlNumber()) {
                $changes[] = "BL Number: {$oldValues['blNumber']} → {$noa->getBlNumber()}";
            }
            if ($oldValues['vesselNumber'] !== $noa->getVesselNumber()) {
                $changes[] = "Vessel Number: {$oldValues['vesselNumber']} → {$noa->getVesselNumber()}";
            }
            if ($oldValues['eta']->format('Y-m-d H:i') !== $noa->getEta()->format('Y-m-d H:i')) {
                $changes[] = "ETA: {$oldValues['eta']->format('M j, Y H:i')} → {$noa->getEta()->format('M j, Y H:i')}";
            }
            if ($oldValues['portLocation'] !== $noa->getPortLocation()) {
                $changes[] = "Port Location: {$oldValues['portLocation']} → {$noa->getPortLocation()}";
            }
            
            $newConsignee = $noa->getConsignee();
            $newConsigneeName = ($newConsignee instanceof \App\Entity\Consignee) ? $newConsignee->getBusinessName() : ($newConsignee ? $newConsignee->getEmail() : 'N/A');
            if ($oldValues['consignee'] !== $newConsigneeName) {
                $changes[] = "Consignee: {$oldValues['consignee']} → {$newConsigneeName}";
            }

            // Create audit log entry
            if (!empty($changes)) {
                $auditLog = new \App\Entity\AuditLog();
                $auditLog->setUser($this->getUser());
                $auditLog->setAction('NOA_UPDATED');
                $auditLog->setEntityType('NOA');
                $auditLog->setEntityId($noa->getId());
                $auditLog->setChanges(['changes' => $changes]);
                $auditLog->setIpAddress($request->getClientIp() ?? '0.0.0.0');
                $this->entityManager->persist($auditLog);
                $this->entityManager->flush();
            }

            // Send notifications if there are changes
            if (!empty($changes)) {
                try {
                    $this->sendNoaUpdateNotifications($noa, $changes);
                } catch (\Exception $notifError) {
                    // Log notification error but don't fail the update
                    error_log('Notification Error: ' . $notifError->getMessage());
                }
            }

            $this->addFlash('success', 'NOA updated successfully');
            return $this->redirectToRoute('manifest_workflow_noa_detail', ['id' => $id]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Error updating NOA: ' . $e->getMessage());
            return $this->redirectToRoute('manifest_workflow_noa_edit_page', ['id' => $id]);
        }
    }

    #[Route('/noa/{id}/edit', name: 'manifest_workflow_noa_edit', methods: ['POST'])]
    public function editNoa(int $id, Request $request): Response
    {
        // Keep this for AJAX compatibility (from modal)
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            return $this->json(['success' => false, 'message' => 'NOA not found'], 404);
        }

        $this->assertCanEditNoa($noa);

        $data = json_decode($request->getContent(), true);
        
        // Validate required fields
        if (empty($data['blNumber']) || empty($data['vesselNumber']) || empty($data['eta']) || empty($data['portLocation'])) {
            return $this->json(['success' => false, 'message' => 'All fields are required'], 400);
        }

        // Store old values for change notification
        $oldValues = [
            'blNumber' => $noa->getBlNumber(),
            'vesselNumber' => $noa->getVesselNumber(),
            'eta' => $noa->getEta(),
            'portLocation' => $noa->getPortLocation(),
        ];

        try {
            // Update NOA fields
            $noa->setBlNumber($data['blNumber']);
            $noa->setVesselNumber($data['vesselNumber']);
            $noa->setEta(new \DateTime($data['eta']));
            $noa->setPortLocation($data['portLocation']);
            
            $this->entityManager->flush();

            // Track changes for notification
            $changes = [];
            if ($oldValues['blNumber'] !== $data['blNumber']) {
                $changes[] = "BL Number: {$oldValues['blNumber']} → {$data['blNumber']}";
            }
            if ($oldValues['vesselNumber'] !== $data['vesselNumber']) {
                $changes[] = "Vessel Number: {$oldValues['vesselNumber']} → {$data['vesselNumber']}";
            }
            if ($oldValues['eta']->format('Y-m-d H:i') !== (new \DateTime($data['eta']))->format('Y-m-d H:i')) {
                $changes[] = "ETA: {$oldValues['eta']->format('M j, Y H:i')} → " . (new \DateTime($data['eta']))->format('M j, Y H:i');
            }
            if ($oldValues['portLocation'] !== $data['portLocation']) {
                $changes[] = "Port Location: {$oldValues['portLocation']} → {$data['portLocation']}";
            }

            // Send notifications if there are changes
            if (!empty($changes)) {
                $this->sendNoaUpdateNotifications($noa, $changes);
            }

            return $this->json([
                'success' => true,
                'message' => 'NOA updated successfully',
                'changes' => $changes
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error updating NOA: ' . $e->getMessage()
            ], 500);
        }
    }

    private function sendNoaUpdateNotifications(\App\Entity\NOA $noa, array $changes): void
    {
        // Find associated manifest
        $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->findOneBy(['noa' => $noa]);

        $changeText = implode(', ', $changes);
        $notificationMessage = "NOA {$noa->getNoaNumber()} has been updated. Changes: {$changeText}";

        // Notify consignee
        if ($noa->getConsignee()) {
            $this->notificationService->createNotification(
                $noa->getConsignee(),
                'NOA Updated',
                $notificationMessage,
                'info',
                $manifest ? ['url' => "/consignee/manifests/" . $manifest->getId()] : null
            );
        }

        // Notify broker if assigned
        if ($manifest && $manifest->getBroker()) {
            $this->notificationService->createNotification(
                $manifest->getBroker(),
                'NOA Updated',
                $notificationMessage,
                'info',
                ['url' => "/broker/manifests/" . $manifest->getId()]
            );
        }

        // Notify all SL_STAFF users
        $slStaffUsers = $this->entityManager->getRepository(\App\Entity\User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', \App\Entity\Enum\UserRole::SL_STAFF)
            ->getQuery()
            ->getResult();

        foreach ($slStaffUsers as $slStaff) {
            $this->notificationService->createNotification(
                $slStaff,
                'NOA Updated',
                $notificationMessage,
                'info',
                ['url' => "/manifest-workflow/noa/{$noa->getId()}"]
            );
        }
    }

    /**
     * Get eDO payment status for a container
     */
    private function getContainerEDOPaymentStatus($container): array
    {
        $status = [
            'has_edo' => false,
            'edo_number' => null,
            'edo_id' => null,
            'has_payment' => false,
            'payment_status' => null,
            'payment_amount' => null,
            'payment_verified_at' => null,
            'official_receipt_path' => null,
        ];

        // Get the current eDO for this container
        $edo = $container->getCurrentEDO();
        
        if ($edo) {
            $status['has_edo'] = true;
            $status['edo_number'] = $edo->getEdoNumber();
            $status['edo_id'] = $edo->getId();

            // Check for payment using DQL to access payments_edo table
            $query = $this->entityManager->createQuery(
                'SELECT p FROM App\Entity\EDOPayment p WHERE p.edo = :edo'
            )->setParameter('edo', $edo);

            try {
                $payment = $query->getOneOrNullResult();
                
                if ($payment) {
                    $status['has_payment'] = true;
                    $status['payment_status'] = $payment->getStatus()->value;
                    $status['payment_amount'] = $payment->getAmount();
                    $status['payment_verified_at'] = $payment->getValidatedAt();
                    $status['official_receipt_path'] = $payment->getOfficialReceiptPath();
                }
            } catch (\Exception $e) {
                // Payment not found or error
            }
        }

        return $status;
    }

    #[Route('/{id}', name: 'manifest_workflow_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        // Clear entity manager to get fresh data from database
        $this->entityManager->clear();
        
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        return $this->render('manifest_workflow/detail.html.twig', [
            'manifest' => $manifest,
        ]);
    }

    #[Route('/{id}/declare-consignee', name: 'manifest_workflow_declare_consignee', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function declareConsignee(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        $user = $this->getUser();
        if (!$user instanceof User || !$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Get approved consignees for autocomplete
        $consignees = $this->userService->getApprovedConsignees();
        
        // Serialize consignees with linked broker info
        $consigneesData = array_map(function($consignee) {
            $data = [
                'id' => $consignee->getId(),
                'businessName' => $consignee->getBusinessName(),
            ];
            
            if ($consignee->getLinkedBroker()) {
                $data['linkedBroker'] = [
                    'id' => $consignee->getLinkedBroker()->getId(),
                    'fullName' => $consignee->getLinkedBroker()->getFullName(),
                ];
            }
            
            return $data;
        }, $consignees);

        return $this->render('manifest_workflow/declare_consignee.html.twig', [
            'manifest' => $manifest,
            'consignees' => $consigneesData,
        ]);
    }

    #[Route('/{id}/generate-noa', name: 'manifest_workflow_generate_noa', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function generateNOA(int $id): Response
    {
        // Clear entity manager to get fresh data from database
        $this->entityManager->clear();
        
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        $user = $this->getUser();
        if (!$user instanceof User || !$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        return $this->render('manifest_workflow/generate_noa.html.twig', [
            'manifest' => $manifest,
        ]);
    }

    #[Route('/{id}/generate-billing', name: 'manifest_workflow_generate_billing', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function generateBilling(int $id): Response
    {
        // Only ACCOUNTING role can access billing generation
        $this->denyAccessUnlessGranted('ROLE_ACCOUNTING');
        
        // Clear entity manager to get fresh data from database
        $this->entityManager->clear();
        
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        if (!$user instanceof User || !$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        return $this->render('manifest_workflow/generate_billing.html.twig', [
            'manifest' => $manifest,
        ]);
    }

    #[Route('/noa/{id}/download-pdf', name: 'manifest_workflow_noa_download_pdf', methods: ['GET'])]
    public function downloadNoaPdf(int $id): Response
    {
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            throw $this->createNotFoundException('NOA not found');
        }

        $this->assertCanViewNoa($noa);

        $pdfPath = $noa->getPdfPath();
        
        if (!$pdfPath) {
            $this->addFlash('error', 'PDF not available for this NOA');
            return $this->redirectToRoute('manifest_workflow_noa_detail', ['id' => $id]);
        }

        // Construct full file path
        $fullPath = $this->getParameter('kernel.project_dir') . '/var/share/' . $pdfPath;
        
        if (!file_exists($fullPath)) {
            $this->addFlash('error', 'PDF file not found on server');
            return $this->redirectToRoute('manifest_workflow_noa_detail', ['id' => $id]);
        }

        // Return file as download
        return $this->file($fullPath, 'NOA_' . $noa->getNoaNumber() . '.pdf');
    }

    #[Route('/noa/{id}/generate-manifest', name: 'manifest_workflow_generate_manifest_form', methods: ['GET'])]
    public function showGenerateManifestForm(int $id): Response
    {
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            throw $this->createNotFoundException('NOA not found');
        }

        $this->assertCanEditNoa($noa);

        // Check if manifest already generated
        if ($noa->getManifestPdfPath()) {
            $this->addFlash('info', 'Manifest/BL has already been generated for this NOA');
            return $this->redirectToRoute('manifest_workflow_noa_detail', ['id' => $id]);
        }

        return $this->render('manifest_workflow/generate_manifest.html.twig', [
            'noa' => $noa,
        ]);
    }

    #[Route('/noa/{id}/generate-manifest', name: 'manifest_workflow_generate_manifest', methods: ['POST'])]
    public function generateManifest(int $id, Request $request): Response
    {
        error_log('=== MANIFEST GENERATION START ===');
        error_log('NOA ID: ' . $id);
        error_log('POST data: ' . json_encode($request->request->all()));
        
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            throw $this->createNotFoundException('NOA not found');
        }

        $this->assertCanEditNoa($noa);

        // Validate CSRF token
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('generate_manifest_' . $id, $submittedToken)) {
            error_log('ERROR: Invalid CSRF token');
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('manifest_workflow_generate_manifest_form', ['id' => $id]);
        }

        try {
            // Get the manifest number from the form
            $manifestNumber = trim($request->request->get('manifest_number', ''));
            error_log('Manifest Number received: ' . ($manifestNumber ?: 'EMPTY'));
            
            if (empty($manifestNumber)) {
                error_log('ERROR: Manifest number is empty');
                $this->addFlash('error', 'Manifest/BL Number is required');
                return $this->redirectToRoute('manifest_workflow_generate_manifest_form', ['id' => $id]);
            }
            
            // Check if manifest number already exists
            $existingManifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->findOneBy(['manifestNumber' => $manifestNumber]);
            
            if ($existingManifest) {
                $this->addFlash('error', 'Manifest/BL Number already exists. Please use a unique number.');
                return $this->redirectToRoute('manifest_workflow_generate_manifest_form', ['id' => $id]);
            }
            
            // Get the confirmed actual arrival date from the form
            $actualArrivalDate = $request->request->get('actual_arrival_date');
            
            if ($actualArrivalDate) {
                try {
                    $arrivalDateTime = new \DateTime($actualArrivalDate);
                    // Update the ETA to actual arrival date
                    $noa->setEta($arrivalDateTime);
                    $this->entityManager->flush();
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Invalid arrival date format');
                    return $this->redirectToRoute('manifest_workflow_generate_manifest_form', ['id' => $id]);
                }
            }

            // Generate Manifest/BL PDF
            try {
                error_log('Starting PDF generation for NOA ID: ' . $noa->getId());
                $pdfPath = $this->manifestBLGenerator->generatePDF($noa, $manifestNumber);
                error_log('PDF generated successfully. Path: ' . $pdfPath);
                
                // Create Manifest record in manifests table
                error_log('Creating Manifest record');
                $manifest = new \App\Entity\Manifest();
                
                // Use the manifest number from the form
                $manifest->setManifestNumber($manifestNumber);
                
                // Set basic information from NOA
                $manifest->setConsignee($noa->getConsignee());
                $manifest->setBlNumber($noa->getBlNumber());
                $manifest->setVesselName($noa->getVesselNumber());
                $manifest->setArrivalDate($noa->getEta());
                $manifest->setManifestFilePath($pdfPath);
                
                // Set shipping line (get from createdBy user if StaffUser)
                $createdBy = $noa->getCreatedBy();
                if ($createdBy instanceof \App\Entity\StaffUser && $createdBy->getShippingLineScope()) {
                    $manifest->setShippingLine($createdBy->getShippingLineScope());
                } else {
                    // Fallback: try to get shipping line from database
                    $shippingLine = $this->entityManager->getRepository(\App\Entity\ShippingLine::class)->findOneBy([]);
                    if ($shippingLine) {
                        $manifest->setShippingLine($shippingLine);
                    } else {
                        throw new \Exception('No shipping line found for manifest');
                    }
                }
                
                $manifest->setCreatedBy($this->getUser());
                $manifest->setNoa($noa);
                
                // Persist manifest
                $this->entityManager->persist($manifest);
                error_log('Manifest record created with number: ' . $manifestNumber);
                
                // Persist the PDF path to database
                $this->entityManager->flush();
                error_log('PDF path and Manifest flushed to database');

                /** @var User $actor */
                $actor = $this->getUser();
                $this->manifestService->recordBlGeneratedWorkflow(
                    $manifest,
                    $actor,
                    'Manifest/BL PDF generated'
                );
                
                // **IMPORTANT**: Link all containers from the NOA to this manifest
                // This ensures brokers don't have to manually "Add" containers
                foreach ($noa->getContainers() as $container) {
                    $container->setManifest($manifest);
                }
                $this->entityManager->flush();
                error_log('Containers linked to manifest');
                
                $this->addFlash('success', 'Manifest/BL PDF generated successfully! Manifest Number: ' . $manifestNumber);
                
                return $this->redirectToRoute('manifest_workflow_noa_detail', ['id' => $id]);
            } catch (\Exception $pdfException) {
                error_log('PDF Generation Exception: ' . $pdfException->getMessage());
                error_log('Exception Class: ' . get_class($pdfException));
                error_log('File: ' . $pdfException->getFile() . ' Line: ' . $pdfException->getLine());
                error_log('Stack trace: ' . $pdfException->getTraceAsString());
                throw $pdfException; // Re-throw to be caught by outer catch
            }
            
        } catch (\Exception $e) {
            error_log('Manifest/BL generation error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            $this->addFlash('error', 'Failed to generate Manifest/BL PDF: ' . $e->getMessage());
            return $this->redirectToRoute('manifest_workflow_generate_manifest_form', ['id' => $id]);
        }
    }

    #[Route('/noa/{id}/download-manifest', name: 'manifest_workflow_download_manifest', methods: ['GET'])]
    public function downloadManifest(int $id): Response
    {
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);
        
        if (!$noa) {
            throw $this->createNotFoundException('NOA not found');
        }

        $this->assertCanViewNoa($noa);

        $pdfPath = $noa->getManifestPdfPath();
        
        if (!$pdfPath) {
            $this->addFlash('error', 'Manifest/BL PDF not available');
            return $this->redirectToRoute('manifest_workflow_noa_detail', ['id' => $id]);
        }

        // Construct full file path
        $fullPath = $this->getParameter('kernel.project_dir') . '/var/share/' . $pdfPath;
        
        if (!file_exists($fullPath)) {
            $this->addFlash('error', 'Manifest/BL PDF file not found on server');
            return $this->redirectToRoute('manifest_workflow_noa_detail', ['id' => $id]);
        }

        // Return file as download
        return $this->file($fullPath, 'MANIFEST_BL_' . $noa->getBlNumber() . '.pdf');
    }

    #[Route('/api/manifests/{manifestId}/download', name: 'api_manifest_download_by_id', methods: ['GET'])]
    public function downloadManifestById(int $manifestId): Response
    {
        $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)->find($manifestId);
        
        if (!$manifest) {
            return $this->json(['error' => 'Manifest not found'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user instanceof User || !$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Get the NOA associated with this manifest
        $noa = $manifest->getNoa();
        
        if (!$noa) {
            return $this->json(['error' => 'NOA not found for this manifest'], Response::HTTP_NOT_FOUND);
        }

        $pdfPath = $noa->getManifestPdfPath();
        
        if (!$pdfPath) {
            return $this->json(['error' => 'Manifest/BL PDF not available'], Response::HTTP_NOT_FOUND);
        }

        // Construct full file path
        $fullPath = $this->getParameter('kernel.project_dir') . '/var/share/' . $pdfPath;
        
        if (!file_exists($fullPath)) {
            return $this->json(['error' => 'Manifest/BL PDF file not found on server'], Response::HTTP_NOT_FOUND);
        }

        // Return file as download
        return $this->file($fullPath, 'MANIFEST_BL_' . $noa->getBlNumber() . '.pdf');
    }

    #[Route('/bulk-import-manifests', name: 'manifest_workflow_bulk_import_manifests', methods: ['GET', 'POST'])]
    public function bulkImportManifests(Request $request): Response
    {
        // Only SL_STAFF can bulk import manifests
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        if ($request->isMethod('POST')) {
            $file = $request->files->get('import_file');
            $skipExisting = $request->request->getBoolean('skip_existing', true);
            $validateOnly = $request->request->getBoolean('validate_only', false);
            
            if (!$file) {
                $this->addFlash('error', 'Please select a file to upload.');
                return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
            }
            
            // Validate file type - check both extension and MIME type
            $allowedExtensions = ['csv', 'xlsx', 'xls'];
            $allowedMimeTypes = [
                'text/csv',
                'text/plain',
                'application/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/octet-stream' // Some systems report CSV as this
            ];
            
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();
            
            // Check extension
            if (!in_array($extension, $allowedExtensions)) {
                $this->addFlash('error', 'Invalid file extension. Only CSV, XLSX, and XLS files are allowed.');
                return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
            }
            
            // Check MIME type
            if (!in_array($mimeType, $allowedMimeTypes)) {
                $this->addFlash('error', sprintf(
                    'Invalid file type detected. The file appears to be "%s" but only CSV and Excel files are allowed. Please ensure you are uploading a valid spreadsheet file.',
                    $mimeType
                ));
                return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
            }
            
            // Additional validation: Try to read the file as CSV to verify it's actually a valid CSV/spreadsheet
            try {
                $testHandle = fopen($file->getPathname(), 'r');
                if ($testHandle === false) {
                    throw new \Exception('Cannot read file');
                }
                
                // Try to read first line as CSV
                $firstLine = fgetcsv($testHandle);
                fclose($testHandle);
                
                if ($firstLine === false || empty($firstLine)) {
                    throw new \Exception('File appears to be empty or not a valid CSV');
                }
                
                // Check if header contains expected columns
                $requiredColumns = ['bl_number', 'manifest_number'];
                $missingColumns = array_diff($requiredColumns, $firstLine);
                
                if (!empty($missingColumns)) {
                    $this->addFlash('error', sprintf(
                        'Invalid CSV format. Missing required columns: %s. Please use the template provided.',
                        implode(', ', $missingColumns)
                    ));
                    return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
                }
            } catch (\Exception $e) {
                $this->addFlash('error', 'The uploaded file is not a valid CSV or Excel file. Please ensure you are uploading a properly formatted spreadsheet.');
                return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
            }
            
            // Validate file size (5MB max)
            if ($file->getSize() > 5 * 1024 * 1024) {
                $this->addFlash('error', 'File size exceeds 5MB limit.');
                return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
            }
            
            try {
                // For validation only, process synchronously
                if ($validateOnly) {
                    $result = $this->processManifestBulkImport(
                        $file->getPathname(),
                        $extension,
                        $skipExisting,
                        true
                    );
                    
                    if ($result['valid']) {
                        $this->addFlash('success', sprintf(
                            'Validation successful! %d manifests are ready to generate. Upload again without "Validate only" to proceed.',
                            $result['valid_count']
                        ));
                    } else {
                        $this->addFlash('error', sprintf(
                            'Validation failed. %d errors found. Please fix the errors and try again.',
                            count($result['errors'])
                        ));
                    }
                    
                    return $this->render('manifest_workflow/bulk_import_manifests_preview.html.twig', [
                        'result' => $result,
                        'validateOnly' => true
                    ]);
                }
                
                // For actual import, use async processing with progress tracking
                // Generate unique import ID
                $importId = uniqid('manifest_import_', true);
                
                // Store file temporarily
                $tempDir = sys_get_temp_dir() . '/manifest_imports';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }
                $tempFilePath = $tempDir . '/' . $importId . '.' . $extension;
                move_uploaded_file($file->getPathname(), $tempFilePath);
                
                // Count total rows for progress tracking
                $handle = fopen($tempFilePath, 'r');
                $totalRows = 0;
                fgetcsv($handle); // Skip header
                while (fgetcsv($handle) !== false) {
                    $totalRows++;
                }
                fclose($handle);
                
                // Initialize progress in session
                $session = $request->getSession();
                $session->set("manifest_import_progress_{$importId}", [
                    'total' => $totalRows,
                    'processed' => 0,
                    'successCount' => 0,
                    'errorCount' => 0,
                    'skippedCount' => 0,
                    'status' => 'Starting manifest import...',
                    'complete' => false,
                    'filePath' => $tempFilePath,
                    'extension' => $extension,
                    'skipExisting' => $skipExisting,
                    'lastUpdate' => null,
                    'errors' => [],
                    'success' => [],
                    'skipped' => []
                ]);
                
                // Redirect to progress page
                return $this->redirectToRoute('manifest_workflow_bulk_import_manifests_progress', [
                    'importId' => $importId
                ]);
                
            } catch (\Exception $e) {
                $this->addFlash('error', 'Import failed: ' . $e->getMessage());
                return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
            }
        }
        
        return $this->render('manifest_workflow/bulk_import_manifests.html.twig');
    }
    
    #[Route('/bulk-import-manifests/progress/{importId}', name: 'manifest_workflow_bulk_import_manifests_progress', methods: ['GET'])]
    public function bulkImportManifestsProgress(string $importId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        $session = $request->getSession();
        $progress = $session->get("manifest_import_progress_{$importId}");
        
        if (!$progress) {
            $this->addFlash('error', 'Import session not found.');
            return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
        }
        
        return $this->render('manifest_workflow/bulk_import_manifests_progress.html.twig', [
            'importId' => $importId,
            'totalRows' => $progress['total']
        ]);
    }
    
    #[Route('/bulk-import-manifests/progress-check/{importId}', name: 'manifest_workflow_bulk_import_manifests_progress_check', methods: ['GET'])]
    public function bulkImportManifestsProgressCheck(string $importId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        $session = $request->getSession();
        $progress = $session->get("manifest_import_progress_{$importId}");
        
        if (!$progress) {
            return $this->json(['error' => 'Import session not found'], 404);
        }
        
        // Only process if not complete AND has more rows to process
        if (!$progress['complete'] && $progress['processed'] < $progress['total']) {
            try {
                $this->processNextManifestImportRow($importId, $session);
                // Refresh progress after processing
                $progress = $session->get("manifest_import_progress_{$importId}");
            } catch (\Exception $e) {
                error_log('Manifest import processing error: ' . $e->getMessage());
                // Mark as complete on error to prevent infinite loop
                $progress['complete'] = true;
                $progress['status'] = 'Import failed: ' . $e->getMessage();
                $session->set("manifest_import_progress_{$importId}", $progress);
            }
        } else {
            // Ensure complete flag is set
            $progress['complete'] = true;
            $session->set("manifest_import_progress_{$importId}", $progress);
        }
        
        return $this->json([
            'total' => $progress['total'],
            'processed' => $progress['processed'],
            'successCount' => $progress['successCount'],
            'errorCount' => $progress['errorCount'],
            'skippedCount' => $progress['skippedCount'],
            'status' => $progress['status'],
            'complete' => $progress['complete'],
            'lastUpdate' => $progress['lastUpdate'],
            'redirectUrl' => $progress['complete'] ? $this->generateUrl('manifest_workflow_bulk_import_manifests_results', ['importId' => $importId]) : null
        ]);
    }
    
    #[Route('/bulk-import-manifests/results/{importId}', name: 'manifest_workflow_bulk_import_manifests_results', methods: ['GET'])]
    public function bulkImportManifestsResults(string $importId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        $session = $request->getSession();
        $progress = $session->get("manifest_import_progress_{$importId}");
        
        if (!$progress) {
            $this->addFlash('error', 'Import session not found.');
            return $this->redirectToRoute('manifest_workflow_bulk_import_manifests');
        }
        
        // Clean up temp file
        if (isset($progress['filePath']) && file_exists($progress['filePath'])) {
            @unlink($progress['filePath']);
        }
        
        // Build result array
        $result = [
            'total_count' => $progress['total'],
            'success_count' => $progress['successCount'],
            'error_count' => $progress['errorCount'],
            'skipped_count' => $progress['skippedCount'],
            'errors' => $progress['errors'],
            'success' => $progress['success'],
            'skipped' => $progress['skipped'] ?? [],
            'valid' => $progress['errorCount'] === 0
        ];
        
        // Clear session data
        $session->remove("manifest_import_progress_{$importId}");
        
        return $this->render('manifest_workflow/bulk_import_manifests_preview.html.twig', [
            'result' => $result,
            'validateOnly' => false
        ]);
    }
    
    private function processNextManifestImportRow(string $importId, $session): void
    {
        $progress = $session->get("manifest_import_progress_{$importId}");
        
        // Safety check: ensure we don't process beyond total rows
        if (!$progress || $progress['complete'] || $progress['processed'] >= $progress['total']) {
            if ($progress) {
                $progress['complete'] = true;
                $progress['status'] = 'Import complete!';
                $session->set("manifest_import_progress_{$importId}", $progress);
            }
            return;
        }
        
        // Read and process one row
        $handle = fopen($progress['filePath'], 'r');
        $header = fgetcsv($handle); // Read header
        
        // Skip to current row
        for ($i = 0; $i < $progress['processed']; $i++) {
            fgetcsv($handle);
        }
        
        // Read next row
        $data = fgetcsv($handle);
        fclose($handle);
        
        if ($data === false) {
            $progress['complete'] = true;
            $progress['status'] = 'Import complete!';
            $session->set("manifest_import_progress_{$importId}", $progress);
            return;
        }
        
        // Combine header with data
        $row = array_combine($header, $data);
        $rowNumber = $progress['processed'] + 2; // +2 because: +1 for header, +1 for 1-based indexing
        
        try {
            // Validate required fields
            if (empty($row['bl_number']) || empty($row['manifest_number'])) {
                throw new \Exception('BL Number and Manifest Number are required');
            }
            
            // Find NOA by BL number
            $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)
                ->findOneBy(['blNumber' => trim($row['bl_number'])]);
            
            if (!$noa) {
                throw new \Exception('NOA with this BL Number not found');
            }
            
            // Check if manifest already exists for this NOA
            if ($progress['skipExisting']) {
                $existingManifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                    ->findOneBy(['noa' => $noa]);
                
                if ($existingManifest) {
                    $progress['skippedCount']++;
                    
                    // Add to skipped array for results page
                    if (!isset($progress['skipped'])) {
                        $progress['skipped'] = [];
                    }
                    $progress['skipped'][] = [
                        'row' => $rowNumber,
                        'bl_number' => $row['bl_number'],
                        'manifest_number' => $row['manifest_number'],
                        'reason' => 'NOA already has a manifest: ' . $existingManifest->getManifestNumber()
                    ];
                    
                    $progress['lastUpdate'] = [
                        'message' => "Row {$rowNumber}: Skipped - NOA already has manifest " . $existingManifest->getManifestNumber(),
                        'type' => 'warning'
                    ];
                    $progress['processed']++;
                    $progress['status'] = "Processing row {$rowNumber} of {$progress['total']}...";
                    $session->set("manifest_import_progress_{$importId}", $progress);
                    
                    // DON'T process next row - let the next poll request handle it
                    return;
                }
            }
            
            // Check if manifest number is unique
            $duplicateManifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->findOneBy(['manifestNumber' => trim($row['manifest_number'])]);
            
            if ($duplicateManifest) {
                throw new \Exception('Manifest Number already exists');
            }
            
            // Update NOA ETA if actual arrival date provided
            if (!empty($row['actual_arrival_date'])) {
                try {
                    $actualArrivalDate = new \DateTime(trim($row['actual_arrival_date']));
                    $noa->setEta($actualArrivalDate);
                } catch (\Exception $e) {
                    throw new \Exception('Invalid actual arrival date format');
                }
            }
            
            // Generate Manifest/BL PDF
            $pdfPath = $this->manifestBLGenerator->generatePDF($noa, trim($row['manifest_number']));
            
            // Create Manifest record
            $manifest = new \App\Entity\Manifest();
            $manifest->setManifestNumber(trim($row['manifest_number']));
            $manifest->setConsignee($noa->getConsignee());
            $manifest->setBlNumber($noa->getBlNumber());
            $manifest->setVesselName($noa->getVesselNumber());
            $manifest->setArrivalDate($noa->getEta());
            $manifest->setManifestFilePath($pdfPath);
            
            // Set shipping line
            $createdBy = $noa->getCreatedBy();
            if ($createdBy instanceof \App\Entity\StaffUser && $createdBy->getShippingLineScope()) {
                $manifest->setShippingLine($createdBy->getShippingLineScope());
            } else {
                $shippingLine = $this->entityManager->getRepository(\App\Entity\ShippingLine::class)->findOneBy([]);
                if ($shippingLine) {
                    $manifest->setShippingLine($shippingLine);
                }
            }
            
            $manifest->setCreatedBy($this->getUser());
            $manifest->setNoa($noa);
            
            $this->entityManager->persist($manifest);
            $this->entityManager->flush();

            /** @var User $actor */
            $actor = $this->getUser();
            $this->manifestService->recordBlGeneratedWorkflow(
                $manifest,
                $actor,
                'Manifest/BL imported from bulk upload'
            );
            
            // **IMPORTANT**: Link all containers from the NOA to this manifest
            // This ensures brokers don't have to manually "Add" containers
            foreach ($noa->getContainers() as $container) {
                $container->setManifest($manifest);
            }
            $this->entityManager->flush();
            
            // Send notification to consignee about manifest creation
            try {
                $this->manifestNotificationService->notifyConsigneeDeclared($manifest);
            } catch (\Exception $e) {
                // Log notification error but don't fail the import
                error_log('Manifest notification failed: ' . $e->getMessage());
            }
            
            // Log audit trail for manifest creation
            try {
                $this->auditService->logAction(
                    $this->getUser(),
                    'create',
                    'Manifest',
                    $manifest->getId(),
                    [
                        'manifest_number' => $manifest->getManifestNumber(),
                        'bl_number' => $manifest->getBlNumber(),
                        'noa_number' => $noa->getNoaNumber(),
                        'vessel_name' => $manifest->getVesselName(),
                        'import_type' => 'bulk_import'
                    ]
                );
            } catch (\Exception $e) {
                // Log audit error but don't fail the import
                error_log('Manifest audit log failed: ' . $e->getMessage());
            }
            
            $progress['successCount']++;
            $progress['success'][] = [
                'row' => $rowNumber,
                'bl_number' => $row['bl_number'],
                'manifest_number' => $row['manifest_number'],
                'noa_number' => $noa->getNoaNumber()
            ];
            $progress['lastUpdate'] = [
                'message' => "Row {$rowNumber}: Successfully created manifest {$row['manifest_number']}",
                'type' => 'success'
            ];
            
        } catch (\Exception $e) {
            $progress['errorCount']++;
            $progress['errors'][] = [
                'row' => $rowNumber,
                'bl_number' => $row['bl_number'] ?? 'N/A',
                'manifest_number' => $row['manifest_number'] ?? 'N/A',
                'message' => $this->translateErrorMessage($e->getMessage())
            ];
            $progress['lastUpdate'] = [
                'message' => "Row {$rowNumber}: Error - " . $e->getMessage(),
                'type' => 'error'
            ];
        }
        
        $progress['processed']++;
        $progress['status'] = "Processing row {$rowNumber} of {$progress['total']}...";
        $session->set("manifest_import_progress_{$importId}", $progress);
        
        // DON'T process next row - let the next poll request handle it
        // This allows the progress bar to update between rows
    }

    #[Route('/bulk-import-manifests/template', name: 'manifest_workflow_download_manifest_template', methods: ['GET'])]
    public function downloadManifestTemplate(): Response
    {
        // Only SL_STAFF can download template
        $this->denyAccessUnlessGranted('ROLE_SL_STAFF');
        
        // Create CSV content
        $csv = [
            ['bl_number', 'manifest_number', 'actual_arrival_date'],
            ['BL-2024-001', 'MAN-2024-001', '2024-12-31 14:30:00'],
            ['BL-2024-002', 'MAN-2024-002', '2024-12-31 16:00:00'],
            ['BL-2024-003', 'MAN-2024-003', ''],
        ];
        
        $filename = 'manifest_bulk_import_template_' . date('Y-m-d') . '.csv';
        
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        
        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);
        
        return $response;
    }

    private function processManifestBulkImport(
        string $filePath,
        string $extension,
        bool $skipExisting,
        bool $validateOnly
    ): array {
        $result = [
            'total_count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'skipped_count' => 0,
            'valid_count' => 0,
            'valid' => true,
            'errors' => [],
            'skipped' => [],
            'success' => [],
        ];

        // Read CSV file
        $rows = [];
        if ($extension === 'csv') {
            $handle = fopen($filePath, 'r');
            $header = fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) === count($header)) {
                    $rows[] = array_combine($header, $data);
                }
            }
            fclose($handle);
        } else {
            // For Excel files, use PhpSpreadsheet if available
            // For now, just support CSV
            throw new \Exception('Excel format not yet supported. Please use CSV.');
        }

        $result['total_count'] = count($rows);
        $rowNumber = 1; // Start from 1 (header is row 0)

        foreach ($rows as $row) {
            $rowNumber++;
            
            // Validate required fields
            if (empty($row['bl_number']) || empty($row['manifest_number'])) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'bl_number' => $row['bl_number'] ?? 'N/A',
                    'manifest_number' => $row['manifest_number'] ?? 'N/A',
                    'message' => 'BL Number and Manifest Number are required'
                ];
                $result['error_count']++;
                $result['valid'] = false;
                continue;
            }

            // Find NOA by BL number
            $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)
                ->findOneBy(['blNumber' => trim($row['bl_number'])]);

            if (!$noa) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'bl_number' => $row['bl_number'],
                    'manifest_number' => $row['manifest_number'],
                    'message' => 'NOA with this BL Number not found'
                ];
                $result['error_count']++;
                $result['valid'] = false;
                continue;
            }

            // Check if manifest already exists for this NOA
            $existingManifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->findOneBy(['noa' => $noa]);

            if ($existingManifest && $skipExisting) {
                $result['skipped'][] = [
                    'row' => $rowNumber,
                    'bl_number' => $row['bl_number'],
                    'manifest_number' => $row['manifest_number'],
                    'reason' => 'NOA already has a manifest: ' . $existingManifest->getManifestNumber()
                ];
                $result['skipped_count']++;
                continue;
            }

            // Check if manifest number is unique
            $duplicateManifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->findOneBy(['manifestNumber' => trim($row['manifest_number'])]);

            if ($duplicateManifest) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'bl_number' => $row['bl_number'],
                    'manifest_number' => $row['manifest_number'],
                    'message' => 'Manifest Number already exists'
                ];
                $result['error_count']++;
                $result['valid'] = false;
                continue;
            }

            // Validate actual arrival date if provided
            $actualArrivalDate = null;
            if (!empty($row['actual_arrival_date'])) {
                try {
                    $actualArrivalDate = new \DateTime(trim($row['actual_arrival_date']));
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'bl_number' => $row['bl_number'],
                        'manifest_number' => $row['manifest_number'],
                        'message' => 'Invalid actual arrival date format'
                    ];
                    $result['error_count']++;
                    $result['valid'] = false;
                    continue;
                }
            }

            // If validate only, just count valid rows
            if ($validateOnly) {
                $result['valid_count']++;
                continue;
            }

            // Generate manifest
            try {
                // Update NOA ETA if actual arrival date provided
                if ($actualArrivalDate) {
                    $noa->setEta($actualArrivalDate);
                }

                // Generate Manifest/BL PDF
                $pdfPath = $this->manifestBLGenerator->generatePDF($noa, trim($row['manifest_number']));
                
                // Create Manifest record
                $manifest = new \App\Entity\Manifest();
                $manifest->setManifestNumber(trim($row['manifest_number']));
                $manifest->setConsignee($noa->getConsignee());
                $manifest->setBlNumber($noa->getBlNumber());
                $manifest->setVesselName($noa->getVesselNumber());
                $manifest->setArrivalDate($noa->getEta());
                $manifest->setManifestFilePath($pdfPath);
                
                // Set shipping line
                $createdBy = $noa->getCreatedBy();
                if ($createdBy instanceof \App\Entity\StaffUser && $createdBy->getShippingLineScope()) {
                    $manifest->setShippingLine($createdBy->getShippingLineScope());
                } else {
                    $shippingLine = $this->entityManager->getRepository(\App\Entity\ShippingLine::class)->findOneBy([]);
                    if ($shippingLine) {
                        $manifest->setShippingLine($shippingLine);
                    }
                }
                
                $manifest->setCreatedBy($this->getUser());
                $manifest->setNoa($noa);
                
                $this->entityManager->persist($manifest);
                $this->entityManager->flush();

                /** @var User $actor */
                $actor = $this->getUser();
                $this->manifestService->recordBlGeneratedWorkflow(
                    $manifest,
                    $actor,
                    'Manifest/BL imported from bulk upload'
                );

                // Send notification to consignee about manifest creation
                try {
                    $this->manifestNotificationService->notifyConsigneeDeclared($manifest);
                } catch (\Exception $e) {
                    // Log notification error but don't fail the import
                    error_log('Manifest notification failed: ' . $e->getMessage());
                }

                // Log audit trail for manifest creation
                try {
                    $this->auditService->logAction(
                        $this->getUser(),
                        'create',
                        'Manifest',
                        $manifest->getId(),
                        [
                            'manifest_number' => $manifest->getManifestNumber(),
                            'bl_number' => $manifest->getBlNumber(),
                            'noa_number' => $noa->getNoaNumber(),
                            'vessel_name' => $manifest->getVesselName(),
                            'import_type' => 'bulk_import'
                        ]
                    );
                } catch (\Exception $e) {
                    // Log audit error but don't fail the import
                    error_log('Manifest audit log failed: ' . $e->getMessage());
                }

                $result['success'][] = [
                    'row' => $rowNumber,
                    'bl_number' => $row['bl_number'],
                    'manifest_number' => $row['manifest_number'],
                    'noa_number' => $noa->getNoaNumber(),
                ];
                $result['success_count']++;

            } catch (\Exception $e) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'bl_number' => $row['bl_number'],
                    'manifest_number' => $row['manifest_number'],
                    'message' => $this->translateErrorMessage($e->getMessage())
                ];
                $result['error_count']++;
                $result['valid'] = false;
            }
        }

        return $result;
    }

    /**
     * Translate technical database errors into user-friendly messages
     */
    private function translateErrorMessage(string $errorMessage): string
    {
        // Check for duplicate entry errors
        if (preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/", $errorMessage, $matches)) {
            $duplicateValue = $matches[1];
            $keyName = $matches[2];
            
            // Translate key names to user-friendly field names
            if (stripos($keyName, 'manifest_number') !== false) {
                return "Manifest number '{$duplicateValue}' already exists. Please use a unique manifest number.";
            } elseif (stripos($keyName, 'bl_number') !== false) {
                return "BL number '{$duplicateValue}' already has a manifest.";
            } else {
                return "This record already exists in the system (duplicate: {$duplicateValue}).";
            }
        }
        
        // Check for foreign key constraint errors
        if (stripos($errorMessage, 'foreign key constraint') !== false || stripos($errorMessage, 'SQLSTATE[23000]') !== false) {
            if (stripos($errorMessage, 'noa') !== false) {
                return "Invalid NOA reference. The NOA may have been deleted.";
            } elseif (stripos($errorMessage, 'shipping_line') !== false) {
                return "Invalid shipping line. Please contact support.";
            } else {
                return "Invalid reference data. Please check that all related records exist.";
            }
        }
        
        // Check for NOT NULL constraint errors
        if (stripos($errorMessage, 'cannot be null') !== false || stripos($errorMessage, 'NOT NULL') !== false) {
            if (stripos($errorMessage, 'manifest_number') !== false) {
                return "Manifest number is required and cannot be empty.";
            } elseif (stripos($errorMessage, 'bl_number') !== false) {
                return "BL number is required and cannot be empty.";
            } else {
                return "A required field is missing. Please check all required fields are filled.";
            }
        }
        
        // Check for data too long errors
        if (stripos($errorMessage, 'Data too long') !== false) {
            return "One or more fields exceed the maximum allowed length. Please shorten your input.";
        }
        
        // Check for invalid datetime format
        if (stripos($errorMessage, 'Incorrect datetime value') !== false) {
            return "Invalid date/time format. Please use format: YYYY-MM-DD HH:MM:SS (e.g., 2026-06-15 08:30:00)";
        }
        
        // If no specific pattern matched, return a cleaned version of the original message
        // Remove SQL technical details but keep the core message
        $cleanMessage = preg_replace('/SQLSTATE\[\w+\]:\s*/', '', $errorMessage);
        $cleanMessage = preg_replace('/An exception occurred while executing a query:\s*/', '', $cleanMessage);
        $cleanMessage = preg_replace('/Integrity constraint violation:\s*\d+\s*/', '', $cleanMessage);
        
        // If the cleaned message is still too technical, provide a generic message
        if (strlen($cleanMessage) > 200 || stripos($cleanMessage, 'SQLSTATE') !== false) {
            return "An error occurred while processing this record. Please check your data and try again.";
        }
        
        return $cleanMessage;
    }

    private function assertCanViewNoa(NOA $noa): void
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Access denied');
        }

        if ($this->isGranted(NOAVoter::VIEW, $noa)) {
            return;
        }

        $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->findOneBy(['noa' => $noa]);
        if ($manifest && $this->authorizationService->canViewManifest($manifest, $user)) {
            return;
        }

        throw $this->createAccessDeniedException('Access denied');
    }

    private function assertCanEditNoa(NOA $noa): void
    {
        $this->denyAccessUnlessGranted(NOAVoter::EDIT, $noa);
    }
}

