<?php

namespace App\Controller;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use App\Form\FormFieldTypes;
use App\Service\AccreditationWorkflowService;
use App\Service\BrokerRelationshipService;
use App\Service\DynamicFormRenderer;
use App\Service\FileService;
use App\Service\FormBuilderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

#[Route('/accreditation')]
#[IsGranted('ROLE_USER')]
class AccreditationController extends AbstractController
{
    public function __construct(
        private AccreditationWorkflowService $accreditationService,
        private FormBuilderService $formBuilderService,
        private DynamicFormRenderer $formRenderer,
        private EntityManagerInterface $entityManager,
        private FileService $fileService,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private BrokerRelationshipService $brokerRelationshipService
    ) {
    }

    #[Route('/', name: 'accreditation_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        $hasActiveBroker = true;
        if ($user instanceof Consignee) {
            $this->brokerRelationshipService->syncLegacyLinkedBrokerFromRelationships($user);
            $hasActiveBroker = $this->brokerRelationshipService->consigneeHasActiveBroker($user)
                || $user->getLinkedBroker() !== null;
        }
        
        // Get all active shipping lines
        $shippingLineRepository = $this->entityManager->getRepository(\App\Entity\ShippingLine::class);
        $shippingLines = $shippingLineRepository->findBy(['isActive' => true], ['brandName' => 'ASC']);
        
        // Get all accreditation submissions for this user
        $submissionRepository = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class);
        $submissions = $submissionRepository->findByApplicant($user);
        
        // Build a map of shipping line ID => submission
        $submissionMap = [];
        foreach ($submissions as $submission) {
            $submissionMap[$submission->getShippingLine()->getId()] = $submission;
        }
        
        // Build array of shipping lines with their accreditation status
        $shippingLineData = [];
        foreach ($shippingLines as $shippingLine) {
            $submission = $submissionMap[$shippingLine->getId()] ?? null;
            $status = $submission ? $submission->getStatus()->value : 'NOT_APPLIED';
            
            $shippingLineData[] = [
                'entity' => $shippingLine,
                'submission' => $submission,
                'status' => $status
            ];
        }

        return $this->render('accreditation/index.html.twig', [
            'shippingLines' => $shippingLineData,
            'user' => $user,
            'hasActiveBroker' => $hasActiveBroker,
        ]);
    }

    #[Route('/detail/{id}', name: 'accreditation_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        $user = $this->getUser();
        
        // Get the submission by ID and ensure it belongs to the current user
        $submission = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->findOneBy(['id' => $id, 'applicant' => $user]);

        if (!$submission) {
            // Instead of throwing an exception, show a user-friendly error page
            $this->addFlash('error', 'Application not found or access denied');
            return $this->redirectToRoute('accreditation_index');
        }

        return $this->render('accreditation/detail.html.twig', [
            'submission' => $submission,
            'user' => $user
        ]);
    }

    #[Route('/submit/{shippingLineId}', name: 'accreditation_submit', methods: ['GET', 'POST'])]
    public function submit(Request $request, int $shippingLineId): Response
    {
        $user = $this->getUser();
        
        // Get the shipping line
        $shippingLine = $this->entityManager->getRepository(\App\Entity\ShippingLine::class)->find($shippingLineId);
        if (!$shippingLine || !$shippingLine->isActive()) {
            $this->addFlash('error', 'Shipping line not found or inactive');
            return $this->redirectToRoute('accreditation_index');
        }
        
        // Check if user already has a submission for this shipping line
        $existingSubmission = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->findByApplicantAndShippingLine($user, $shippingLineId);
        
        if ($existingSubmission && in_array($existingSubmission->getStatus()->value, ['PENDING', 'APPROVED'])) {
            $this->addFlash('error', 'You already have a ' . strtolower($existingSubmission->getStatus()->value) . ' application for this shipping line');
            return $this->redirectToRoute('accreditation_index');
        }

        // Check if user can submit (pass shipping line ID for per-shipping-line validation)
        $canSubmit = $this->accreditationService->canSubmitAccreditation($user, $shippingLineId);
        if (!$canSubmit['valid']) {
            $this->addFlash('error', $canSubmit['message']);
            return $this->redirectToRoute('accreditation_index');
        }

        // Get the appropriate form configuration
        $formType = $user->getRole() === UserRole::CONSIGNEE ? FormType::CONSIGNEE : FormType::BROKER;
        $formConfig = $this->formBuilderService->getActiveForm($formType);

        if (!$formConfig) {
            $this->addFlash('error', 'No active accreditation form available. Please contact support.');
            return $this->redirectToRoute('accreditation_index');
        }

        // Check if form config has proper structure
        $fields = $formConfig->getFields();
        if (!isset($fields['fields']) || !is_array($fields['fields'])) {
            $this->addFlash('error', 'Form configuration is invalid. Please contact support.');
            return $this->redirectToRoute('accreditation_index');
        }

        if ($request->isMethod('POST')) {
            // Validate CSRF token
            $csrfToken = new CsrfToken('accreditation_submit', $request->request->get('_csrf_token'));
            if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('accreditation_submit', ['shippingLineId' => $shippingLineId]);
            }

            $formData = $request->request->all();
            $uploadedFiles = $request->files->all();

            // Process file uploads
            $processedFiles = [];
            foreach ($uploadedFiles as $fieldName => $file) {
                if (is_array($file)) {
                    $ids = [];
                    foreach ($file as $uploaded) {
                        if ($uploaded && $uploaded->isValid()) {
                            $storedFile = $this->fileService->uploadFile($uploaded, 'accreditation', $user);
                            $ids[] = $storedFile->getFileId();
                        }
                    }
                    if ($ids !== []) {
                        $processedFiles[$fieldName] = $ids;
                    }
                    continue;
                }

                if ($file && $file->isValid()) {
                    try {
                        $storedFile = $this->fileService->uploadFile($file, 'accreditation', $user);
                        $processedFiles[$fieldName] = $storedFile->getFileId();
                    } catch (\Exception $e) {
                        $this->addFlash('error', 'File upload failed: ' . $e->getMessage());
                        return $this->render('accreditation/submit.html.twig', [
                            'formConfig' => $formConfig,
                            'shippingLine' => $shippingLine,
                            'errors' => ['file_upload' => $e->getMessage()],
                            'submittedData' => $formData
                        ]);
                    }
                }
            }

            $formFields = $formConfig->getFields()['fields'] ?? [];
            $validationResult = $this->formRenderer->validateSubmission($formConfig, $formData);
            $errors = $validationResult['errors'];

            foreach ($formFields as $field) {
                $fieldId = $field['id'];
                $fieldLabel = $field['label'];
                $isRequired = $field['required'] ?? false;
                $fieldType = $field['type'];
                $validation = $field['validation'] ?? [];

                if (!$this->formRenderer->isFieldVisible($field, $formData)) {
                    continue;
                }

                if (!FormFieldTypes::isFileType($fieldType)) {
                    continue;
                }

                if ($fieldType === 'multi_file') {
                    $files = $uploadedFiles[$fieldId] ?? [];
                    if (!is_array($files)) {
                        $files = $files ? [$files] : [];
                    }
                    if ($isRequired && !isset($processedFiles[$fieldId])) {
                        $errors[$fieldId] = $fieldLabel . ' is required';
                        continue;
                    }
                    foreach ($files as $uploaded) {
                        if ($uploaded && $uploaded->isValid()) {
                            $allowedTypes = $validation['allowedTypes'] ?? ['pdf', 'jpg', 'jpeg', 'png'];
                            $maxSize = $validation['maxSize'] ?? (10 * 1024 * 1024);
                            $fileValidation = $this->fileService->validateFile($uploaded, $allowedTypes, $maxSize);
                            if (!$fileValidation['isValid']) {
                                $errors[$fieldId] = $fileValidation['error'] ?? ($fieldLabel . ' is invalid');
                                break;
                            }
                        }
                    }
                    continue;
                }

                $file = $uploadedFiles[$fieldId] ?? null;

                if ($isRequired && !isset($processedFiles[$fieldId])) {
                    $errors[$fieldId] = $fieldLabel . ' is required';
                    continue;
                }

                if ($file && $file->isValid()) {
                    $allowedTypes = $validation['allowedTypes'] ?? ['pdf', 'jpg', 'jpeg', 'png'];
                    $maxSize = $validation['maxSize'] ?? (10 * 1024 * 1024);
                    $fileValidation = $this->fileService->validateFile($file, $allowedTypes, $maxSize);

                    if (!$fileValidation['isValid']) {
                        $errors[$fieldId] = $fileValidation['error'] ?? ($fieldLabel . ' is invalid');
                    }
                }
            }

            if (count($errors) > 0) {
                return $this->render('accreditation/submit.html.twig', [
                    'formConfig' => $formConfig,
                    'shippingLine' => $shippingLine,
                    'errors' => $errors,
                    'submittedData' => $formData,
                ]);
            }

            try {
                // Submit the accreditation with files and shipping line
                $submission = $this->accreditationService->submitAccreditation($user, $formData, $processedFiles, $shippingLine);

                $this->addFlash('success', 'Your accreditation application for ' . $shippingLine->getBrandName() . ' has been submitted successfully!');
                return $this->redirectToRoute('accreditation_index');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to submit accreditation: ' . $e->getMessage());
                return $this->render('accreditation/submit.html.twig', [
                    'formConfig' => $formConfig,
                    'shippingLine' => $shippingLine,
                    'errors' => [],
                    'submittedData' => $formData,
                ]);
            }
        }

        return $this->render('accreditation/submit.html.twig', [
            'formConfig' => $formConfig,
            'shippingLine' => $shippingLine,
            'errors' => [],
            'submittedData' => []
        ]);
    }

    #[Route('/broker/select', name: 'accreditation_broker_select', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CONSIGNEE')]
    public function selectBroker(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof Consignee) {
            throw $this->createAccessDeniedException('Only consignees can select brokers');
        }

        // Handle search functionality
        $searchTerm = $request->query->get('search', '');
        $brokerRepository = $this->entityManager->getRepository(Broker::class);
        
        if ($searchTerm) {
            // Search brokers by business name or email - only show accredited brokers
            $brokers = $brokerRepository->createQueryBuilder('b')
                ->leftJoin('App\Entity\AccreditationSubmission', 'a', 'WITH', 'a.applicant = b.id')
                ->where('b.status = :accountStatus')
                ->andWhere('a.status = :accreditationStatus')
                ->andWhere('(b.fullName LIKE :search OR b.email LIKE :search)')
                ->setParameter('accountStatus', AccountStatus::APPROVED)
                ->setParameter('accreditationStatus', AccreditationStatus::APPROVED)
                ->setParameter('search', '%' . $searchTerm . '%')
                ->orderBy('b.fullName', 'ASC')
                ->getQuery()
                ->getResult();
        } else {
            // Get all approved and accredited brokers
            $brokers = $brokerRepository->createQueryBuilder('b')
                ->leftJoin('App\Entity\AccreditationSubmission', 'a', 'WITH', 'a.applicant = b.id')
                ->where('b.status = :accountStatus')
                ->andWhere('a.status = :accreditationStatus')
                ->setParameter('accountStatus', AccountStatus::APPROVED)
                ->setParameter('accreditationStatus', AccreditationStatus::APPROVED)
                ->orderBy('b.fullName', 'ASC')
                ->getQuery()
                ->getResult();
        }

        if ($request->isMethod('POST')) {
            // Validate CSRF token
            $csrfToken = new CsrfToken('broker_select', $request->request->get('_csrf_token'));
            if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('accreditation_broker_select');
            }

            $brokerId = $request->request->get('broker_id');

            if (!$brokerId) {
                $this->addFlash('error', 'Please select a broker');
                return $this->redirectToRoute('accreditation_broker_select');
            }

            $broker = $brokerRepository->find($brokerId);

            // Check if broker exists, is approved, and has approved accreditation
            if (!$broker || $broker->getStatus() !== AccountStatus::APPROVED) {
                $this->addFlash('error', 'Broker not found or not approved');
                return $this->redirectToRoute('accreditation_broker_select');
            }

            // Check if broker has approved accreditation
            $accreditationSubmission = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
                ->findOneBy(['applicant' => $broker, 'status' => AccreditationStatus::APPROVED]);

            if (!$accreditationSubmission) {
                $this->addFlash('error', 'Selected broker is not accredited');
                return $this->redirectToRoute('accreditation_broker_select');
            }

            try {
                $this->accreditationService->linkBrokerToConsignee($user, $broker);
                $this->addFlash('success', 'Broker linked successfully! You can now submit your accreditation.');
                return $this->redirectToRoute('accreditation_index');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to link broker: ' . $e->getMessage());
            }
        }

        return $this->render('accreditation/broker_select.html.twig', [
            'brokers' => $brokers,
            'currentBroker' => $user->getLinkedBroker(),
            'searchTerm' => $searchTerm
        ]);
    }

    #[Route('/test-upload', name: 'accreditation_test_upload', methods: ['GET', 'POST'])]
    public function testUpload(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $uploadedFiles = $request->files->all();
            
            $debug = [];
            foreach ($uploadedFiles as $fieldName => $file) {
                if ($file) {
                    $debug[$fieldName] = [
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'error' => $file->getError(),
                        'error_message' => $file->getErrorMessage(),
                        'is_valid' => $file->isValid(),
                        'mime_type' => $file->getMimeType(),
                        'extension' => $file->getClientOriginalExtension()
                    ];
                } else {
                    $debug[$fieldName] = 'No file uploaded';
                }
            }
            
            return new Response('<pre>' . json_encode($debug, JSON_PRETTY_PRINT) . '</pre>');
        }
        
        return new Response('
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="test_file" required>
                <button type="submit">Test Upload</button>
            </form>
        ');
    }

    #[Route('/file/{fileId}', name: 'accreditation_file_download', methods: ['GET'])]
    public function downloadFile(string $fileId): Response
    {
        $user = $this->getUser();

        try {
            // Debug logging
            error_log('File download attempt: fileId=' . $fileId . ', userId=' . $user->getId() . ', userRole=' . $user->getRole()->value);
            
            // Get the stored file first to check if it exists
            $storedFile = $this->fileService->retrieveFile($fileId, $user);
            
            if (!$storedFile) {
                error_log('File not found or access denied: fileId=' . $fileId);
                throw $this->createNotFoundException('File not found or access denied');
            }

            error_log('File found: ' . $storedFile->getOriginalName() . ', category=' . $storedFile->getCategory());

            // Get file response with proper headers
            $fileResponse = $this->fileService->getFileResponse($fileId, $user);
            
            if (!$fileResponse) {
                error_log('File response failed: fileId=' . $fileId);
                throw $this->createNotFoundException('File not found or access denied');
            }

            error_log('File response successful: size=' . $fileResponse['size'] . ', mimeType=' . $fileResponse['mimeType']);

            // Create response with proper headers
            $response = new Response($fileResponse['content']);
            $response->headers->set('Content-Type', $fileResponse['mimeType']);
            $response->headers->set('Content-Length', (string) $fileResponse['size']);
            
            // Set filename for download
            $disposition = 'inline; filename="' . $fileResponse['filename'] . '"';
            $response->headers->set('Content-Disposition', $disposition);
            
            // Add cache headers for better performance
            $response->headers->set('Cache-Control', 'public, max-age=3600');
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + 3600));
            
            // Add CSP headers to allow iframe embedding from same origin
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
            
            return $response;
            
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('File download error: ' . $e->getMessage() . ' for fileId: ' . $fileId . ', userId: ' . $user->getId());
            throw $this->createNotFoundException('File not found: ' . $e->getMessage());
        }
    }
}
