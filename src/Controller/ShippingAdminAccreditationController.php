<?php

namespace App\Controller;

use App\Entity\AccreditationSubmission;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\StaffUser;
use App\Entity\StoredFile;
use App\Service\AccreditationWorkflowService;
use App\Service\ComplianceRequestService;
use App\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/shipping-admin/accreditations')]
#[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
class ShippingAdminAccreditationController extends AbstractController
{
    public function __construct(
        private AccreditationWorkflowService $accreditationService,
        private EntityManagerInterface $entityManager,
        private FileService $fileService
    ) {
    }

    #[Route('', name: 'app_shipping_admin_accreditations')]
    public function index(): Response
    {
        $shippingLine = $this->requireShippingLine();

        $awaitingApproval = $this->accreditationService->getSubmissionsForFinalApprovalByShippingLine($shippingLine);
        $approved = $this->accreditationService->getApprovedAccreditationsByShippingLine($shippingLine);

        return $this->render('shipping_admin/accreditations.html.twig', [
            'shippingLine' => $shippingLine,
            'awaitingApproval' => $awaitingApproval,
            'approved' => $approved,
            'pendingCount' => count($awaitingApproval),
            'approvedCount' => count($approved),
        ]);
    }

    #[Route('/file/{fileId}', name: 'app_shipping_admin_accreditation_file_download', methods: ['GET'])]
    public function downloadFile(string $fileId): Response
    {
        $shippingLine = $this->requireShippingLine();
        /** @var StaffUser $user */
        $user = $this->getUser();

        $storedFile = $this->entityManager->getRepository(StoredFile::class)
            ->findOneBy(['fileId' => $fileId]);

        if (!$storedFile || $storedFile->getCategory() !== 'accreditation') {
            throw $this->createNotFoundException('File not found');
        }

        if (!$this->canAccessAccreditationFile($storedFile, $shippingLine)) {
            throw $this->createAccessDeniedException('Access denied to this file');
        }

        $fileResponse = $this->fileService->getFileResponse($fileId, $user);
        if (!$fileResponse) {
            throw $this->createNotFoundException('File not found or access denied');
        }

        $response = new Response($fileResponse['content']);
        $response->headers->set('Content-Type', $fileResponse['mimeType']);
        $response->headers->set('Content-Length', (string) $fileResponse['size']);
        $response->headers->set('Content-Disposition', 'inline; filename="' . $fileResponse['filename'] . '"');
        $response->headers->set('Cache-Control', 'private, max-age=3600');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }

    #[Route('/{id}', name: 'app_shipping_admin_accreditation_detail', requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $shippingLine = $this->requireShippingLine();
        $submission = $this->requireSubmissionForShippingLine($id, $shippingLine);

        $allowedStatuses = [
            AccreditationStatus::AWAITING_FINAL_APPROVAL,
            AccreditationStatus::APPROVED,
            AccreditationStatus::DENIED,
            AccreditationStatus::REJECTED,
        ];

        if (!in_array($submission->getStatus(), $allowedStatuses, true)) {
            throw $this->createAccessDeniedException('This application is not available for review');
        }

        return $this->render('shipping_admin/application_detail.html.twig', [
            'submission' => $submission,
            'complianceFieldsToCorrect' => ComplianceRequestService::resolveFields(
                $submission,
                $submission->getFormConfig()
            ),
        ]);
    }

    #[Route('/{id}/approve', name: 'app_shipping_admin_accreditation_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        $shippingLine = $this->requireShippingLine();
        $this->requireSubmissionForShippingLine($id, $shippingLine);

        if (!$this->isCsrfTokenValid('admin_approve', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_shipping_admin_accreditation_detail', ['id' => $id]);
        }

        $decision = $request->request->get('decision');
        $reason = $request->request->get('reason');

        if (!in_array($decision, ['approve', 'deny'], true)) {
            $this->addFlash('error', 'Invalid decision');
            return $this->redirectToRoute('app_shipping_admin_accreditation_detail', ['id' => $id]);
        }

        if ($decision === 'deny' && empty(trim((string) $reason))) {
            $this->addFlash('error', 'Reason is required for denials');
            return $this->redirectToRoute('app_shipping_admin_accreditation_detail', ['id' => $id]);
        }

        try {
            $this->accreditationService->finalApproval(
                $id,
                $this->getUser(),
                $decision === 'approve',
                $reason
            );

            $message = $decision === 'approve'
                ? 'Application approved successfully'
                : 'Application denied';
            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_shipping_admin_accreditations');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_shipping_admin_accreditation_detail', ['id' => $id]);
        }
    }

    private function requireShippingLine(): \App\Entity\ShippingLine
    {
        /** @var StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();

        if (!$shippingLine) {
            throw $this->createAccessDeniedException('No shipping line assigned to your account.');
        }

        return $shippingLine;
    }

    private function requireSubmissionForShippingLine(int $id, \App\Entity\ShippingLine $shippingLine): AccreditationSubmission
    {
        $submission = $this->entityManager->getRepository(AccreditationSubmission::class)->find($id);

        if (!$submission || $submission->getShippingLine()->getId() !== $shippingLine->getId()) {
            throw $this->createNotFoundException('Application not found');
        }

        return $submission;
    }

    private function canAccessAccreditationFile(StoredFile $storedFile, \App\Entity\ShippingLine $shippingLine): bool
    {
        $fileId = $storedFile->getFileId();

        $submissions = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->createQueryBuilder('s')
            ->where('s.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getResult();

        foreach ($submissions as $submission) {
            if ($this->submissionContainsFileId($submission->getSubmittedData(), $fileId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submissionContainsFileId(array $data, string $fileId): bool
    {
        if ($this->fileMapContainsId($data['_files'] ?? null, $fileId)) {
            return true;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }

            if (is_string($value) && $value === $fileId) {
                return true;
            }
        }

        return false;
    }

    private function fileMapContainsId(mixed $files, string $fileId): bool
    {
        if (!is_array($files)) {
            return is_string($files) && $files === $fileId;
        }

        foreach ($files as $value) {
            if (is_string($value) && $value === $fileId) {
                return true;
            }

            if (is_array($value)) {
                foreach ($value as $nestedId) {
                    if (is_string($nestedId) && $nestedId === $fileId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
