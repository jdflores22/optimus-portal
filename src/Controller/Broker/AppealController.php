<?php

namespace App\Controller\Broker;

use App\Entity\Broker;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Notification;
use App\Entity\SuspensionAppeal;
use App\Entity\User;
use App\Service\ActivityLogService;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/broker/appeal')]
#[IsGranted('ROLE_BROKER')]
class AppealController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogService $activityLogService,
        private AuditService $auditService
    ) {
    }

    #[Route('/submit', name: 'broker_submit_appeal', methods: ['POST'])]
    public function submitAppeal(Request $request): JsonResponse
    {
        /** @var Broker $user */
        $user = $this->getUser();

        // Check if user is actually suspended (status = DENIED)
        if ($user->getStatus() !== AccountStatus::DENIED) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Your account is not suspended'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if there's already a pending appeal
        $existingAppeal = $this->entityManager->getRepository(SuspensionAppeal::class)
            ->findOneBy([
                'user' => $user,
                'status' => 'pending'
            ]);

        if ($existingAppeal) {
            return new JsonResponse([
                'success' => false,
                'message' => 'You already have a pending appeal'
            ], Response::HTTP_BAD_REQUEST);
        }

        $appealLetter = $request->request->get('appeal_letter');

        if (empty($appealLetter)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Appeal letter is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Create appeal
        $appeal = new SuspensionAppeal();
        $appeal->setUser($user);
        $appeal->setAppealLetter($appealLetter);

        // Handle file uploads
        $uploadedFiles = $request->files->get('appeal_attachments', []);
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/appeal_attachments';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        foreach ($uploadedFiles as $file) {
            if ($file) {
                $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = transliterator_transliterate(
                    'Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()',
                    $originalFilename
                );
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

                try {
                    $file->move($uploadDir, $newFilename);
                    $appeal->addAppealAttachment('/uploads/appeal_attachments/' . $newFilename);
                } catch (FileException $e) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Failed to upload file: ' . $e->getMessage()
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }
        }

        $this->entityManager->persist($appeal);
        $this->entityManager->flush();

        // Create notifications for all SYSTEM_ADMIN users
        $systemAdmins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::SYSTEM_ADMIN)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();

        foreach ($systemAdmins as $admin) {
            $notification = new Notification();
            $notification->setUser($admin);
            $notification->setTitle('New Suspension Appeal');
            $notification->setMessage(sprintf(
                'Broker %s has submitted a suspension appeal. Click to review.',
                $user->getEmail()
            ));
            $notification->setType('warning');
            $notification->setActionUrl('/admin/appeals/' . $appeal->getId());
            $notification->setActionText('Review Appeal');
            
            $this->entityManager->persist($notification);
        }
        
        $this->entityManager->flush();

        // Log to Activity Log
        $activityContext = [
            'broker_email' => $user->getEmail(),
            'broker_id' => $user->getId(),
            'appeal_id' => $appeal->getId(),
            'appeal_letter_length' => strlen($appealLetter),
            'attachments_count' => $appeal->getAppealAttachments() ? count($appeal->getAppealAttachments()) : 0,
            'suspension_reason' => $user->getDeactivationReason(),
        ];

        if ($appeal->getAppealAttachments()) {
            $activityContext['attachment_files'] = $appeal->getAppealAttachments();
        }

        $this->activityLogService->logActivity(
            $user,
            'suspension_appeal_submitted',
            'SuspensionAppeal',
            $appeal->getId(),
            [],
            ['status' => 'pending'],
            $activityContext
        );

        // Log to Audit Log
        $this->auditService->logAction(
            $user,
            'suspension_appeal_submitted',
            'SuspensionAppeal',
            $appeal->getId(),
            [
                'broker_email' => $user->getEmail(),
                'broker_role' => $user->getRole()->value,
                'appeal_letter_preview' => substr($appealLetter, 0, 200) . (strlen($appealLetter) > 200 ? '...' : ''),
                'attachments_count' => $appeal->getAppealAttachments() ? count($appeal->getAppealAttachments()) : 0,
                'suspension_reason' => $user->getDeactivationReason(),
            ]
        );

        return new JsonResponse([
            'success' => true,
            'message' => 'Your appeal has been submitted successfully'
        ]);
    }

    #[Route('/status', name: 'broker_appeal_status', methods: ['GET'])]
    public function getAppealStatus(): JsonResponse
    {
        /** @var Broker $user */
        $user = $this->getUser();

        $appeal = $this->entityManager->getRepository(SuspensionAppeal::class)
            ->findOneBy(
                ['user' => $user],
                ['submittedAt' => 'DESC']
            );

        if (!$appeal) {
            return new JsonResponse([
                'hasAppeal' => false
            ]);
        }

        return new JsonResponse([
            'hasAppeal' => true,
            'status' => $appeal->getStatus(),
            'submittedAt' => $appeal->getSubmittedAt()->format('Y-m-d H:i:s'),
            'reviewedAt' => $appeal->getReviewedAt()?->format('Y-m-d H:i:s'),
            'reviewNotes' => $appeal->getReviewNotes()
        ]);
    }
}
