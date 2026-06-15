<?php

namespace App\Controller;

use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\UserRole;
use App\Service\AccreditationWorkflowService;
use App\Service\ComplianceRequestService;
use App\Form\FormFieldTypes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/evaluator')]
#[IsGranted('ROLE_EVALUATOR')]
class EvaluatorController extends AbstractController
{
    public function __construct(
        private AccreditationWorkflowService $accreditationService,
        private EntityManagerInterface $entityManager,
        private \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }

    #[Route('/dashboard', name: 'app_evaluator_dashboard')]
    public function dashboard(): Response
    {
        // Get pending applications and split by review type
        $pendingApplications = $this->accreditationService->getPendingSubmissions();
        $complianceRequiredApplications = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->findBy(
                ['status' => AccreditationStatus::COMPLIANCE_REQUIRED, 'evaluator' => $this->getUser()],
                ['evaluatedAt' => 'DESC']
            );

        $newPendingApplications = [];
        $compliedApplications = [];
        foreach ($pendingApplications as $application) {
            if ($application->getSubmittedData()['_resubmitted_after_compliance'] ?? false) {
                $compliedApplications[] = $application;
            } else {
                $newPendingApplications[] = $application;
            }
        }

        $reviewQueue = array_merge($compliedApplications, $newPendingApplications);
        
        // Get recently evaluated applications by this evaluator
        $recentlyEvaluated = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->where('a.evaluator = :evaluator')
            ->andWhere('a.status != :pending')
            ->setParameter('evaluator', $this->getUser())
            ->setParameter('pending', \App\Entity\Enum\AccreditationStatus::PENDING)
            ->orderBy('a.evaluatedAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
            
        // Calculate comprehensive statistics
        $stats = [
            'pending_applications' => count($pendingApplications),
            'pending_new' => count($newPendingApplications),
            'complied_resubmissions' => count($compliedApplications),
            'compliance_required' => count($complianceRequiredApplications),
            'evaluated_today' => $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.evaluator = :evaluator')
                ->andWhere('a.evaluatedAt >= :today')
                ->setParameter('evaluator', $this->getUser())
                ->setParameter('today', new \DateTime('today'))
                ->getQuery()
                ->getSingleScalarResult(),
            'total_evaluated' => $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.evaluator = :evaluator')
                ->setParameter('evaluator', $this->getUser())
                ->getQuery()
                ->getSingleScalarResult(),
            'evaluated_this_week' => $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.evaluator = :evaluator')
                ->andWhere('a.evaluatedAt >= :thisWeek')
                ->setParameter('evaluator', $this->getUser())
                ->setParameter('thisWeek', new \DateTime('-7 days'))
                ->getQuery()
                ->getSingleScalarResult(),
            'evaluated_this_month' => $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.evaluator = :evaluator')
                ->andWhere('a.evaluatedAt >= :thisMonth')
                ->setParameter('evaluator', $this->getUser())
                ->setParameter('thisMonth', new \DateTime('-30 days'))
                ->getQuery()
                ->getSingleScalarResult(),
            'approved_count' => $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.evaluator = :evaluator')
                ->andWhere('a.status = :approved')
                ->setParameter('evaluator', $this->getUser())
                ->setParameter('approved', \App\Entity\Enum\AccreditationStatus::APPROVED)
                ->getQuery()
                ->getSingleScalarResult(),
            'denied_count' => $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.evaluator = :evaluator')
                ->andWhere('a.status IN (:denied_statuses)')
                ->setParameter('evaluator', $this->getUser())
                ->setParameter('denied_statuses', [\App\Entity\Enum\AccreditationStatus::DENIED, \App\Entity\Enum\AccreditationStatus::REJECTED])
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        // Get evaluation status distribution
        $statusDistribution = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->select('a.status, COUNT(a.id) as count')
            ->where('a.evaluator = :evaluator')
            ->setParameter('evaluator', $this->getUser())
            ->groupBy('a.status')
            ->getQuery()
            ->getResult();

        // Get evaluation trends for the last 30 days
        $evaluationTrends = [];
        $startDate = new \DateTime('-30 days');
        $endDate = new \DateTime();
        
        $evaluations = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->select('a.evaluatedAt')
            ->where('a.evaluator = :evaluator')
            ->andWhere('a.evaluatedAt >= :startDate')
            ->andWhere('a.evaluatedAt <= :endDate')
            ->setParameter('evaluator', $this->getUser())
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getResult();

        // Group by date in PHP
        $evaluationsByDate = [];
        foreach ($evaluations as $evaluation) {
            if ($evaluation['evaluatedAt']) {
                $date = $evaluation['evaluatedAt']->format('Y-m-d');
                if (!isset($evaluationsByDate[$date])) {
                    $evaluationsByDate[$date] = 0;
                }
                $evaluationsByDate[$date]++;
            }
        }

        foreach ($evaluationsByDate as $date => $count) {
            $evaluationTrends[] = ['date' => $date, 'count' => $count];
        }

        // Get applications by role distribution
        $applicationsByRole = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->select('u.role, COUNT(a.id) as count')
            ->join('a.applicant', 'u')
            ->where('a.evaluator = :evaluator')
            ->setParameter('evaluator', $this->getUser())
            ->groupBy('u.role')
            ->getQuery()
            ->getResult();

        // Format data for charts
        $chartData = [
            'evaluationTrends' => $this->formatTrendData($evaluationTrends),
            'statusDistribution' => $this->formatEvaluationStatusDistribution($statusDistribution),
            'applicationsByRole' => $this->formatApplicationsByRole($applicationsByRole),
        ];

        return $this->render('dashboard/evaluator.html.twig', [
            'pendingApplications' => $pendingApplications,
            'reviewQueue' => $reviewQueue,
            'newPendingApplications' => $newPendingApplications,
            'compliedApplications' => $compliedApplications,
            'complianceRequiredApplications' => $complianceRequiredApplications,
            'recentlyEvaluated' => $recentlyEvaluated,
            'stats' => $stats,
            'chartData' => $chartData,
        ]);
    }

    private function formatTrendData(array $data): array
    {
        if (empty($data)) {
            return [];
        }
        
        $formatted = [];
        foreach ($data as $item) {
            $formatted[] = [
                'x' => $item['date'],
                'y' => (int)$item['count']
            ];
        }
        return $formatted;
    }

    private function formatEvaluationStatusDistribution(array $data): array
    {
        $formatted = [
            'labels' => [],
            'series' => []
        ];
        
        if (empty($data)) {
            $formatted['labels'] = ['No Data'];
            $formatted['series'] = [0];
            return $formatted;
        }
        
        foreach ($data as $item) {
            $formatted['labels'][] = ucfirst(strtolower(str_replace('_', ' ', $item['status']->value)));
            $formatted['series'][] = (int)$item['count'];
        }
        
        return $formatted;
    }

    private function formatApplicationsByRole(array $data): array
    {
        $formatted = [
            'categories' => [],
            'series' => []
        ];
        
        if (empty($data)) {
            $formatted['categories'] = ['No Data'];
            $formatted['series'] = [0];
            return $formatted;
        }
        
        foreach ($data as $item) {
            $formatted['categories'][] = ucfirst(strtolower(str_replace('_', ' ', $item['role']->value)));
            $formatted['series'][] = (int)$item['count'];
        }
        
        return $formatted;
    }

    #[Route('/applications', name: 'app_evaluator_applications')]
    public function applications(): Response
    {
        // Get all applications that the evaluator can see
        $repository = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class);
        
        // Get pending applications and applications evaluated by this evaluator
        $pendingApplications = $repository->findBy(['status' => AccreditationStatus::PENDING]);
        $evaluatedApplications = $repository->findBy(['evaluator' => $this->getUser()]);
        
        // Combine and sort by submission date (newest first)
        $allApplications = array_merge($pendingApplications, $evaluatedApplications);
        
        // Remove duplicates and sort
        $uniqueApplications = [];
        foreach ($allApplications as $app) {
            $uniqueApplications[$app->getId()] = $app;
        }
        
        usort($uniqueApplications, function($a, $b) {
            return $b->getSubmittedAt() <=> $a->getSubmittedAt();
        });

        return $this->render('evaluator/applications.html.twig', [
            'applications' => $uniqueApplications,
        ]);
    }

    #[Route('/application/{id}', name: 'app_evaluator_application_detail')]
    public function applicationDetail(int $id): Response
    {
        $submission = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->find($id);

        if (!$submission) {
            throw $this->createNotFoundException('Application not found');
        }

        // Allow evaluators to view:
        // 1. Pending applications (for evaluation)
        // 2. Applications they have already evaluated
        $canView = $submission->getStatus() === AccreditationStatus::PENDING || 
                   $submission->getEvaluator() === $this->getUser();

        if (!$canView) {
            throw $this->createAccessDeniedException('You do not have access to this application');
        }

        return $this->render('evaluator/application_detail.html.twig', [
            'submission' => $submission,
            'sortedFormFields' => $this->sortFormFields($submission->getFormConfig()?->getFields()['fields'] ?? []),
            'complianceFieldsToCorrect' => ComplianceRequestService::resolveFields(
                $submission,
                $submission->getFormConfig()
            ),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function sortFormFields(array $fields): array
    {
        usort($fields, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $fields;
    }

    #[Route('/application/{id}/evaluate', name: 'app_evaluator_evaluate', methods: ['POST'])]
    public function evaluate(int $id, Request $request): Response
    {
        // Get the submission first to check if it can be evaluated
        $submission = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class)
            ->find($id);

        if (!$submission) {
            $this->addFlash('error', 'Application not found');
            return $this->redirectToRoute('app_evaluator_dashboard');
        }

        // Only allow evaluation of pending applications
        if ($submission->getStatus() !== AccreditationStatus::PENDING) {
            $this->addFlash('error', 'This application has already been evaluated');
            return $this->redirectToRoute('app_evaluator_application_detail', ['id' => $id]);
        }

        // Validate CSRF token
        $csrfToken = new \Symfony\Component\Security\Csrf\CsrfToken('evaluate_application', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_evaluator_application_detail', ['id' => $id]);
        }

        $status = $request->request->get('status');
        $reason = trim((string) $request->request->get('reason', ''));
        $complianceFields = array_values(array_filter((array) $request->request->all('compliance_fields')));
        $complianceFieldNotes = (array) $request->request->all('compliance_field_notes');

        // Validate status
        $validStatuses = [
            'APPROVED' => AccreditationStatus::APPROVED,
            'DENIED' => AccreditationStatus::DENIED,
            'REJECTED' => AccreditationStatus::REJECTED,
            'COMPLIANCE_REQUIRED' => AccreditationStatus::COMPLIANCE_REQUIRED,
        ];

        if (!isset($validStatuses[$status])) {
            $this->addFlash('error', 'Invalid status selected');
            return $this->redirectToRoute('app_evaluator_application_detail', ['id' => $id]);
        }

        if (in_array($status, ['DENIED', 'REJECTED'], true) && $reason === '') {
            $this->addFlash('error', 'Reason is required for denials and rejections');
            return $this->redirectToRoute('app_evaluator_application_detail', ['id' => $id]);
        }

        if ($status === 'COMPLIANCE_REQUIRED') {
            $validFieldIds = array_map(
                static fn (array $field): string => (string) $field['id'],
                array_filter(
                    $this->sortFormFields($submission->getFormConfig()?->getFields()['fields'] ?? []),
                    static fn (array $field): bool => !FormFieldTypes::isLayoutType($field['type'] ?? '')
                )
            );
            $complianceFields = array_values(array_intersect($complianceFields, $validFieldIds));

            if ($complianceFields === []) {
                $this->addFlash('error', 'Select at least one application field that requires correction.');
                return $this->redirectToRoute('app_evaluator_application_detail', ['id' => $id]);
            }
        }

        try {
            $this->accreditationService->evaluateApplication(
                $id,
                $this->getUser(),
                $validStatuses[$status],
                $reason !== '' ? $reason : null,
                $status === 'COMPLIANCE_REQUIRED' ? $complianceFields : [],
                $status === 'COMPLIANCE_REQUIRED' ? $complianceFieldNotes : []
            );

            $this->addFlash('success', 'Application evaluated successfully');
            return $this->redirectToRoute('app_evaluator_dashboard');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_evaluator_application_detail', ['id' => $id]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'An error occurred while processing the evaluation. Please try again.');
            return $this->redirectToRoute('app_evaluator_application_detail', ['id' => $id]);
        }
    }
}
