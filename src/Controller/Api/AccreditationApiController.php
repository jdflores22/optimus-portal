<?php

namespace App\Controller\Api;

use App\Service\AccreditationWorkflowService;
use App\Service\FormBuilderService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\Enum\UserRole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/accreditation', name: 'api_accreditation_')]
class AccreditationApiController extends BaseApiController
{
    public function __construct(
        private AccreditationWorkflowService $accreditationService,
        private FormBuilderService $formBuilderService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/submit', name: 'submit', methods: ['POST'])]
    public function submitAccreditation(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only consignees and brokers can submit accreditation
        $roleCheck = $this->requireRole($user, [UserRole::CONSIGNEE->value, UserRole::BROKER->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        if (!$data) {
            return $this->errorResponse('Invalid JSON data');
        }

        try {
            $submission = $this->accreditationService->submitAccreditation($user, $data);

            return $this->jsonResponse([
                'success' => true,
                'submission_id' => $submission->getId(),
                'status' => $submission->getStatus()->value,
                'submitted_at' => $submission->getSubmittedAt()->format('Y-m-d H:i:s')
            ], 201);

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    #[Route('/status/{id}', name: 'status', methods: ['GET'])]
    public function getAccreditationStatus(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $submission = $this->accreditationService->getSubmissionById($id);
            
            if (!$submission) {
                return $this->errorResponse('Submission not found', 404);
            }

            // Check if user can access this submission
            if ($submission->getApplicant()->getId() !== $user->getId() && 
                !in_array($user->getRole()->value, [UserRole::EVALUATOR->value, UserRole::SHIPPING_LINES_ADMIN->value, UserRole::SYSTEM_ADMIN->value])) {
                return $this->errorResponse('Access denied', 403);
            }

            return $this->jsonResponse([
                'id' => $submission->getId(),
                'status' => $submission->getStatus()->value,
                'submitted_at' => $submission->getSubmittedAt()->format('Y-m-d H:i:s'),
                'evaluated_at' => $submission->getEvaluatedAt()?->format('Y-m-d H:i:s'),
                'approved_at' => $submission->getApprovedAt()?->format('Y-m-d H:i:s'),
                'denial_reason' => $submission->getDenialReason(),
                'applicant' => [
                    'id' => $submission->getApplicant()->getId(),
                    'email' => $submission->getApplicant()->getEmail(),
                    'role' => $submission->getApplicant()->getRole()->value
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve submission status', 500);
        }
    }

    #[Route('/forms/{type}', name: 'get_form', methods: ['GET'])]
    public function getFormConfiguration(string $type, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $formType = strtoupper($type);
            $form = $this->formBuilderService->getActiveForm($formType);

            if (!$form) {
                return $this->errorResponse('Form configuration not found', 404);
            }

            return $this->jsonResponse([
                'id' => $form->getId(),
                'name' => $form->getName(),
                'type' => $form->getType()->value,
                'version' => $form->getVersion(),
                'fields' => $form->getFields(),
                'published_at' => $form->getPublishedAt()?->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve form configuration', 500);
        }
    }

    #[Route('/list', name: 'list', methods: ['GET'])]
    public function listSubmissions(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only evaluators and admins can list all submissions
        $roleCheck = $this->requireRole($user, [UserRole::EVALUATOR->value, UserRole::SHIPPING_LINES_ADMIN->value, UserRole::SYSTEM_ADMIN->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $submissions = $this->accreditationService->getAllSubmissions();
            
            $result = array_map(function($submission) {
                return [
                    'id' => $submission->getId(),
                    'status' => $submission->getStatus()->value,
                    'submitted_at' => $submission->getSubmittedAt()->format('Y-m-d H:i:s'),
                    'applicant' => [
                        'id' => $submission->getApplicant()->getId(),
                        'email' => $submission->getApplicant()->getEmail(),
                        'role' => $submission->getApplicant()->getRole()->value
                    ]
                ];
            }, $submissions);

            return $this->jsonResponse([
                'submissions' => $result,
                'total' => count($result)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve submissions', 500);
        }
    }
}