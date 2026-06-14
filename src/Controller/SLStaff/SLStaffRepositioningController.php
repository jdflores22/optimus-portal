<?php

namespace App\Controller\SLStaff;

use App\Controller\ShippingLine\RepositioningRequestController as BaseRepositioningController;
use App\Entity\RepositioningRequest;
use App\Entity\StaffUser;
use App\Repository\RepositioningRequestRepository;
use App\Service\RepositioningRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[IsGranted('ROLE_SL_STAFF')]
class SLStaffRepositioningController extends AbstractController
{
    public function __construct(
        private readonly RepositioningRequestService $repositioningService,
        private readonly RepositioningRequestRepository $requestRepo,
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/sl-staff/repositioning', name: 'app_sl_staff_repositioning_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var StaffUser $user */
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();

        $requests = $shippingLine
            ? $this->requestRepo->findForShippingLine($shippingLine)
            : $this->requestRepo->findBy([], ['requestedAt' => 'DESC']);

        $pendingCount = $shippingLine
            ? $this->requestRepo->countPendingForShippingLine($shippingLine)
            : $this->requestRepo->countAllPending();

        return $this->render('repositioning/index.html.twig', [
            'requests' => $requests,
            'pendingCount' => $pendingCount,
            'dashboardRoute' => 'app_sl_staff_dashboard',
            'canReview' => true,
            'newRoute' => 'app_sl_staff_repositioning_new',
            'showRoute' => 'app_sl_staff_repositioning_show',
        ]);
    }

    #[Route('/sl-staff/repositioning/new', name: 'app_sl_staff_repositioning_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        /** @var StaffUser $user */
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        if (!$shippingLine) {
            throw $this->createAccessDeniedException('No shipping line assigned.');
        }

        $cyTerminals = $this->repositioningService->getCyTerminalsForShippingLine($shippingLine);
        $portTerminals = $this->repositioningService->getPortTerminals();

        $sourceCyId = $request->query->getInt('cy') ?: ($request->request->getInt('source_cy') ?: null);
        $sourceCy = $sourceCyId ? $this->em->getRepository(\App\Entity\Terminal::class)->find($sourceCyId) : null;
        $search = $request->query->get('search') ?? $request->request->get('search');

        $eligibleContainers = $this->repositioningService->getEligibleContainers(
            $shippingLine,
            $sourceCy,
            is_string($search) ? $search : null,
        );

        if ($request->isMethod('POST')) {
            $token = new CsrfToken('repositioning_request', $request->request->get('_csrf_token'));
            if (!$this->csrfTokenManager->isTokenValid($token)) {
                $this->addFlash('error', 'Invalid security token.');
                return $this->redirectToRoute('app_sl_staff_repositioning_index');
            }

            try {
                $typeValue = $request->request->get('request_type', 'export');
                $requestType = \App\Entity\Enum\RepositioningRequestType::tryFrom($typeValue)
                    ?? \App\Entity\Enum\RepositioningRequestType::EXPORT;

                $sourceCyEntity = $this->em->getRepository(\App\Entity\Terminal::class)->find($request->request->getInt('source_cy'));
                $destPort = $this->em->getRepository(\App\Entity\Terminal::class)->find($request->request->getInt('destination_port'));
                $purpose = trim((string) $request->request->get('purpose', ''));
                $containerIds = array_map('intval', (array) $request->request->all('container_ids'));

                if (!$sourceCyEntity || !$destPort || $purpose === '') {
                    throw new \InvalidArgumentException('Source CY, destination port, and purpose are required.');
                }

                $letterPath = $this->handleLetterUpload($request);

                $created = $this->repositioningService->createRequest(
                    $shippingLine,
                    $user,
                    $requestType,
                    $sourceCyEntity,
                    $destPort,
                    $purpose,
                    $containerIds,
                    $letterPath,
                );

                $this->addFlash('success', sprintf(
                    'Repositioning request %s submitted with %d container(s).',
                    $created->getRequestNumber(),
                    $created->getContainerCount()
                ));

                return $this->redirectToRoute('app_sl_staff_repositioning_show', ['id' => $created->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('repositioning/create.html.twig', [
            'cyTerminals' => $cyTerminals,
            'portTerminals' => $portTerminals,
            'eligibleContainers' => $eligibleContainers,
            'selectedCyId' => $sourceCy?->getId(),
            'search' => $search,
            'dashboardRoute' => 'app_sl_staff_dashboard',
            'indexRoute' => 'app_sl_staff_repositioning_index',
            'formAction' => 'app_sl_staff_repositioning_new',
        ]);
    }

    #[Route('/sl-staff/repositioning/{id}', name: 'app_sl_staff_repositioning_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $req = $this->loadRequest($id);

        return $this->render('repositioning/show.html.twig', [
            'request' => $req,
            'dashboardRoute' => 'app_sl_staff_dashboard',
            'indexRoute' => 'app_sl_staff_repositioning_index',
            'canReview' => true,
            'approveRoute' => 'app_sl_staff_repositioning_approve',
            'rejectRoute' => 'app_sl_staff_repositioning_reject',
            'completeRoute' => 'app_sl_staff_repositioning_complete',
            'cancelRoute' => null,
        ]);
    }

    #[Route('/sl-staff/repositioning/{id}/approve', name: 'app_sl_staff_repositioning_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        $this->validateCsrf($request, 'review_repositioning');
        $req = $this->loadRequest($id);

        try {
            $this->repositioningService->approveRequest($req, $this->getUser());
            $this->addFlash('success', 'Request approved. Containers marked in transit to port.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_sl_staff_repositioning_show', ['id' => $id]);
    }

    #[Route('/sl-staff/repositioning/{id}/reject', name: 'app_sl_staff_repositioning_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        $this->validateCsrf($request, 'review_repositioning');
        $req = $this->loadRequest($id);
        $notes = trim((string) $request->request->get('review_notes', ''));

        if ($notes === '') {
            $this->addFlash('error', 'Rejection reason is required.');
            return $this->redirectToRoute('app_sl_staff_repositioning_show', ['id' => $id]);
        }

        try {
            $this->repositioningService->rejectRequest($req, $this->getUser(), $notes);
            $this->addFlash('success', 'Request rejected.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_sl_staff_repositioning_show', ['id' => $id]);
    }

    #[Route('/sl-staff/repositioning/{id}/complete', name: 'app_sl_staff_repositioning_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(int $id, Request $request): Response
    {
        $this->validateCsrf($request, 'review_repositioning');
        $req = $this->loadRequest($id);

        try {
            $this->repositioningService->completeRequest($req, $this->getUser());
            $this->addFlash('success', 'Containers marked as arrived at destination port.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_sl_staff_repositioning_show', ['id' => $id]);
    }

    private function loadRequest(int $id): RepositioningRequest
    {
        $req = $this->requestRepo->find($id);
        if (!$req) {
            throw $this->createNotFoundException('Request not found.');
        }

        return $req;
    }

    private function validateCsrf(Request $request, string $id): void
    {
        $token = new CsrfToken($id, $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }
    }

    private function handleLetterUpload(Request $request): ?string
    {
        $uploadedFile = $request->files->get('request_letter');
        if (!$uploadedFile) {
            return null;
        }

        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($uploadedFile->getMimeType(), $allowed, true)) {
            throw new \InvalidArgumentException('Request letter must be PDF, JPG, or PNG.');
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/repositioning_letters';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = uniqid('rrp_', true) . '.' . $uploadedFile->guessExtension();
        $uploadedFile->move($uploadDir, $filename);

        return '/uploads/repositioning_letters/' . $filename;
    }
}
