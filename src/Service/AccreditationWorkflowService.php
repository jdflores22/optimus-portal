<?php

namespace App\Service;

use App\Entity\AccreditationSubmission;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\FormConfiguration;
use App\Entity\User;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;

class AccreditationWorkflowService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FormBuilderService $formBuilderService,
        private DynamicFormRenderer $formRenderer,
        private AuditService $auditService,
        private NotificationService $notificationService,
        private DatabaseTransactionService $dbTransactionService,
        private BrokerRelationshipService $brokerRelationshipService
    ) {
    }

    /**
     * Submit an accreditation application
     * 
     * @param User $user The applicant
     * @param array $formData The submitted form data
     * @param array $files Uploaded files
     * @param \App\Entity\ShippingLine $shippingLine The shipping line for this accreditation
     * @return AccreditationSubmission The created submission
     * @throws \InvalidArgumentException If validation fails
     */
    public function submitAccreditation(User $user, array $formData, array $files = [], \App\Entity\ShippingLine $shippingLine = null): AccreditationSubmission
    {
        error_log('DEBUG: AccreditationWorkflowService.submitAccreditation() called for user ' . $user->getId());
        
        // Check if user can submit accreditation
        $canSubmit = $this->canSubmitAccreditation($user);
        if (!$canSubmit['valid']) {
            error_log('DEBUG: User cannot submit accreditation: ' . $canSubmit['message']);
            throw new \InvalidArgumentException($canSubmit['message']);
        }
        error_log('DEBUG: User can submit accreditation');

        // Get the appropriate form configuration
        $formType = $this->getFormTypeForUser($user);
        error_log('DEBUG: Form type determined: ' . $formType->value);
        
        $formConfig = $this->formBuilderService->getActiveForm($formType);

        if (!$formConfig) {
            error_log('DEBUG: No active form configuration found for ' . $formType->value);
            throw new \InvalidArgumentException('No active form configuration found for ' . $formType->value);
        }
        error_log('DEBUG: Form config found: ' . $formConfig->getId());

        // Skip dynamic form validation for now since we're using static validation
        error_log('DEBUG: Skipping dynamic form validation');

        // Merge file information with form data
        $submissionData = $formData;
        if (!empty($files)) {
            $submissionData['_files'] = $files;
        }
        error_log('DEBUG: Submission data prepared with ' . count($files) . ' files');

        // Check if user has an existing submission for this shipping line that can be resubmitted
        $existingSubmission = null;
        if ($shippingLine) {
            $existingSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
                ->findByApplicantAndShippingLine($user, $shippingLine->getId());
        } else {
            // Backward compatibility: if no shipping line specified, find any existing submission
            $existingSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
                ->findOneBy(['applicant' => $user]);
        }

        if ($existingSubmission) {
            $status = $existingSubmission->getStatus();
            if ($status === AccreditationStatus::COMPLIANCE_REQUIRED || 
                $status === AccreditationStatus::DENIED || 
                $status === AccreditationStatus::REJECTED) {
                
                error_log('DEBUG: Updating existing submission ID: ' . $existingSubmission->getId());
                
                // Update the existing submission for resubmission
                $existingSubmission->setFormConfig($formConfig);
                if ($status === AccreditationStatus::COMPLIANCE_REQUIRED) {
                    $submissionData['_resubmitted_after_compliance'] = true;
                }
                unset($submissionData[ComplianceRequestService::STORAGE_KEY]);
                $existingSubmission->setSubmittedData($submissionData);
                $existingSubmission->setStatus(AccreditationStatus::PENDING);
                $existingSubmission->setEvaluator(null);
                $existingSubmission->setEvaluatedAt(null);
                $existingSubmission->setDenialReason(null);
                
                error_log('DEBUG: Persisting updated submission to database');
                $this->entityManager->flush();
                
                error_log('DEBUG: Submission updated with ID: ' . $existingSubmission->getId());
                return $existingSubmission;
            }
        }

        // Create the accreditation submission (for new submissions)
        error_log('DEBUG: Creating new AccreditationSubmission entity');
        $submission = new AccreditationSubmission();
        $submission->setApplicant($user);
        $submission->setFormConfig($formConfig);
        $submission->setSubmittedData($submissionData);
        $submission->setStatus(AccreditationStatus::PENDING);
        
        // Set shipping line if provided
        if ($shippingLine) {
            $submission->setShippingLine($shippingLine);
            error_log('DEBUG: Set shipping line: ' . $shippingLine->getBrandName());
        }

        error_log('DEBUG: Persisting new submission to database');
        $this->entityManager->persist($submission);
        $this->entityManager->flush();
        
        error_log('DEBUG: Submission saved with ID: ' . $submission->getId());

        return $submission;
    }

    /**
     * Check if a user can submit accreditation
     * 
     * @param User $user The user to check
     * @param int|null $shippingLineId Optional shipping line ID to check for specific shipping line
     * @return array Array with 'valid' boolean and 'message' string
     */
    public function canSubmitAccreditation(User $user, ?int $shippingLineId = null): array
    {
        // Consignees need at least one active broker (referral relationship or legacy link)
        if ($user instanceof Consignee) {
            $this->brokerRelationshipService->syncLegacyLinkedBrokerFromRelationships($user);

            if (!$this->brokerRelationshipService->consigneeHasActiveBroker($user) && $user->getLinkedBroker() === null) {
                return [
                    'valid' => false,
                    'message' => 'Consignees must link a broker before submitting accreditation'
                ];
            }
        }

        // If shipping line ID is provided, check for that specific shipping line
        if ($shippingLineId !== null) {
            $existingSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
                ->findByApplicantAndShippingLine($user, $shippingLineId);

            if ($existingSubmission) {
                $status = $existingSubmission->getStatus();
                if ($status === AccreditationStatus::PENDING || $status === AccreditationStatus::APPROVED) {
                    return [
                        'valid' => false,
                        'message' => 'You already have a ' . strtolower($status->value) . ' accreditation submission for this shipping line'
                    ];
                }
            }
        } else {
            // Backward compatibility: check for any existing submission globally
            $existingSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
                ->findOneBy(['applicant' => $user]);

            if ($existingSubmission) {
                $status = $existingSubmission->getStatus();
                if ($status === AccreditationStatus::PENDING || $status === AccreditationStatus::APPROVED) {
                    return [
                        'valid' => false,
                        'message' => 'You already have a ' . strtolower($status->value) . ' accreditation submission'
                    ];
                }
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Evaluate an accreditation application (Evaluator action)
     * 
     * @param int $submissionId The submission ID
     * @param User $evaluator The evaluator
     * @param AccreditationStatus $status The new status
     * @param string|null $reason Optional reason for denial/rejection/compliance
     * @param list<string> $complianceFieldIds Fields that must be corrected (compliance only)
     * @param array<string, string> $complianceFieldNotes Per-field notes for applicant
     * @throws \InvalidArgumentException If submission not found or invalid status
     */
    public function evaluateApplication(
        int $submissionId,
        User $evaluator,
        AccreditationStatus $status,
        ?string $reason = null,
        array $complianceFieldIds = [],
        array $complianceFieldNotes = []
    ): void {
        $submission = null;
        
        $this->dbTransactionService->executeInTransactionWithRetry(function() use ($submissionId, $evaluator, $status, $reason, $complianceFieldIds, $complianceFieldNotes, &$submission) {
            $submission = $this->entityManager->getRepository(AccreditationSubmission::class)
                ->find($submissionId);

            if (!$submission) {
                throw new \InvalidArgumentException('Accreditation submission not found');
            }

            // Validate status transition
            if ($submission->getStatus() !== AccreditationStatus::PENDING) {
                throw new \InvalidArgumentException('Can only evaluate pending submissions');
            }

            // Validate evaluator role
            if ($evaluator->getRole() !== UserRole::EVALUATOR) {
                throw new \InvalidArgumentException('Only evaluators can evaluate applications');
            }

            // Validate status is appropriate for evaluator
            $allowedStatuses = [
                AccreditationStatus::APPROVED,
                AccreditationStatus::DENIED,
                AccreditationStatus::REJECTED,
                AccreditationStatus::COMPLIANCE_REQUIRED
            ];

            if (!in_array($status, $allowedStatuses, true)) {
                throw new \InvalidArgumentException('Invalid status for evaluation');
            }

            // Update submission
            $submission->setStatus($status);
            $submission->setEvaluator($evaluator);
            $submission->setEvaluatedAt(new \DateTime());

            if ($reason) {
                $submission->setDenialReason($reason);
            }

            if ($status === AccreditationStatus::COMPLIANCE_REQUIRED) {
                $complianceRequest = ComplianceRequestService::build(
                    $complianceFieldIds,
                    $complianceFieldNotes,
                    $reason
                );
                $submittedData = ComplianceRequestService::applyToSubmissionData(
                    $submission->getSubmittedData(),
                    $complianceRequest
                );
                $submission->setSubmittedData($submittedData);

                if (!$reason && $complianceFieldIds !== []) {
                    $submission->setDenialReason('Please correct the selected application fields.');
                }
            }

            // Flush will be handled by the transaction service
            $this->entityManager->flush();

            // Log the evaluation action
            $this->auditService->logAction(
                $evaluator,
                'evaluate_application',
                'AccreditationSubmission',
                $submission->getId(),
                [
                    'status' => [
                        'from' => AccreditationStatus::PENDING->value,
                        'to' => $status->value
                    ],
                    'reason' => $reason,
                    'compliance_field_ids' => $complianceFieldIds,
                ]
            );
        }, "evaluate_application_{$submissionId}");

        // Send notification to applicant (asynchronously to avoid blocking the request)
        if ($submission) {
            try {
                $complianceFields = $status === AccreditationStatus::COMPLIANCE_REQUIRED
                    ? ComplianceRequestService::resolveFields($submission, $submission->getFormConfig())
                    : [];

                // Set a short timeout for email sending to avoid blocking the web request
                $this->notificationService->sendAccreditationStatusChange(
                    $submission->getApplicant(),
                    $status,
                    $reason,
                    $complianceFields
                );
            } catch (\Exception $e) {
                // Log notification failure but don't fail the evaluation
                // The email will be retried by a background job
                error_log('Failed to send accreditation status notification: ' . $e->getMessage());
                $this->logger->warning('Email notification failed during evaluation', [
                    'submission_id' => $submissionId,
                    'user_id' => $submission->getApplicant()->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Final approval by Shipping Lines Admin
     * 
     * @param int $submissionId The submission ID
     * @param User $admin The admin performing final approval
     * @param bool $approved Whether to approve or deny
     * @param string|null $reason Optional reason for denial
     * @throws \InvalidArgumentException If submission not found or invalid state
     */
    public function finalApproval(
        int $submissionId,
        User $admin,
        bool $approved,
        ?string $reason = null
    ): void {
        $submission = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->find($submissionId);

        if (!$submission) {
            throw new \InvalidArgumentException('Accreditation submission not found');
        }

        // Validate admin role
        if ($admin->getRole() !== UserRole::SHIPPING_LINES_ADMIN && $admin->getRole() !== UserRole::SYSTEM_ADMIN) {
            throw new \InvalidArgumentException('Only Shipping Lines Admin or System Admin can perform final approval');
        }

        // Validate submission was approved by evaluator
        if ($submission->getStatus() !== AccreditationStatus::APPROVED) {
            throw new \InvalidArgumentException('Can only perform final approval on evaluator-approved submissions');
        }

        // Update submission
        if ($approved) {
            $submission->setStatus(AccreditationStatus::APPROVED);
            $submission->setFinalApprover($admin);
            $submission->setApprovedAt(new \DateTime());

            // Update user account status
            $user = $submission->getApplicant();
            $user->setStatus(AccountStatus::APPROVED);
        } else {
            $submission->setStatus(AccreditationStatus::DENIED);
            $submission->setFinalApprover($admin);
            if ($reason) {
                $submission->setDenialReason($reason);
            }

            // Update user account status
            $user = $submission->getApplicant();
            $user->setStatus(AccountStatus::DENIED);
        }

        $this->entityManager->flush();

        // Log the final approval action
        $this->auditService->logAction(
            $admin,
            $approved ? 'final_approval' : 'final_denial',
            'AccreditationSubmission',
            $submission->getId(),
            [
                'status' => [
                    'from' => AccreditationStatus::APPROVED->value,
                    'to' => $approved ? AccreditationStatus::APPROVED->value : AccreditationStatus::DENIED->value
                ],
                'approved' => $approved,
                'reason' => $reason,
                'user_status' => [
                    'from' => AccountStatus::PENDING->value,
                    'to' => $approved ? AccountStatus::APPROVED->value : AccountStatus::DENIED->value
                ]
            ]
        );

        // Send notification to applicant
        try {
            $finalStatus = $approved ? AccreditationStatus::APPROVED : AccreditationStatus::DENIED;
            $this->notificationService->sendAccreditationStatusChange(
                $submission->getApplicant(),
                $finalStatus,
                $reason
            );
        } catch (\Exception $e) {
            // Log notification failure but don't fail the approval
            error_log('Failed to send final approval notification: ' . $e->getMessage());
        }
    }

    /**
     * Link a broker to a consignee
     * 
     * @param Consignee $consignee The consignee
     * @param Broker $broker The broker to link
     * @throws \InvalidArgumentException If broker is not approved
     */
    public function linkBrokerToConsignee(Consignee $consignee, Broker $broker): void
    {
        // Validate broker is approved
        if ($broker->getStatus() !== AccountStatus::APPROVED) {
            throw new \InvalidArgumentException('Can only link to approved brokers');
        }

        // Link the broker
        $consignee->setLinkedBroker($broker);
        $broker->addLinkedConsignee($consignee);
        $this->entityManager->flush();

        // Send notification to broker
        try {
            $this->notificationService->sendBrokerLinkageNotification($broker, $consignee);
        } catch (\Exception $e) {
            // Log notification failure but don't fail the linkage
            error_log('Failed to send broker linkage notification: ' . $e->getMessage());
        }
    }

    /**
     * Get the form type for a user based on their role
     * 
     * @param User $user The user
     * @return FormType The form type
     * @throws \InvalidArgumentException If user type is not supported
     */
    private function getFormTypeForUser(User $user): FormType
    {
        return match ($user->getRole()) {
            UserRole::CONSIGNEE => FormType::CONSIGNEE,
            UserRole::BROKER => FormType::BROKER,
            default => throw new \InvalidArgumentException('User role does not support accreditation')
        };
    }

    /**
     * Get all pending submissions for evaluator review
     * 
     * @return array Array of AccreditationSubmission entities
     */
    public function getPendingSubmissions(): array
    {
        return $this->entityManager->getRepository(AccreditationSubmission::class)
            ->findBy(['status' => AccreditationStatus::PENDING], ['submittedAt' => 'ASC']);
    }

    /**
     * Get all evaluator-approved submissions for final approval
     * 
     * @return array Array of AccreditationSubmission entities
     */
    public function getSubmissionsForFinalApproval(): array
    {
        return $this->entityManager->getRepository(AccreditationSubmission::class)
            ->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.evaluator IS NOT NULL')
            ->andWhere('s.finalApprover IS NULL')
            ->setParameter('status', AccreditationStatus::APPROVED)
            ->orderBy('s.evaluatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get accreditation submission for a user
     * 
     * @param User $user The user
     * @return AccreditationSubmission|null The submission or null
     */
    public function getSubmissionForUser(User $user): ?AccreditationSubmission
    {
        return $this->entityManager->getRepository(AccreditationSubmission::class)
            ->findOneBy(['applicant' => $user]);
    }

    /**
     * Get submission by ID
     * 
     * @param int $id The submission ID
     * @return AccreditationSubmission|null The submission or null
     */
    public function getSubmissionById(int $id): ?AccreditationSubmission
    {
        return $this->entityManager->getRepository(AccreditationSubmission::class)
            ->find($id);
    }

    /**
     * Get all approved accreditations (final approved submissions)
     * 
     * @return array Array of AccreditationSubmission entities
     */
    public function getApprovedAccreditations(): array
    {
        return $this->entityManager->getRepository(AccreditationSubmission::class)
            ->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.finalApprover IS NOT NULL')
            ->setParameter('status', AccreditationStatus::APPROVED)
            ->orderBy('s.approvedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all submissions
     * 
     * @return array Array of AccreditationSubmission entities
     */
    public function getAllSubmissions(): array
    {
        return $this->entityManager->getRepository(AccreditationSubmission::class)
            ->findAll();
    }
}
