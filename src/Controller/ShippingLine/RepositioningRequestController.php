<?php

namespace App\Controller\ShippingLine;

use App\Entity\Enum\RepositioningRequestType;
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

#[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
class RepositioningRequestController extends AbstractController
{
    public function __construct(
        private readonly RepositioningRequestService $repositioningService,
        private readonly RepositioningRequestRepository $requestRepo,
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/shipping-admin/repositioning', name: 'app_shipping_admin_repositioning_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderIndex('app_shipping_admin_dashboard');
    }

    #[Route('/shipping-admin/repositioning/new', name: 'app_shipping_admin_repositioning_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->handleCreate($request, 'app_shipping_admin_repositioning_index', 'app_shipping_admin_dashboard');
    }

    #[Route('/shipping-admin/repositioning/{id}', name: 'app_shipping_admin_repositioning_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        return $this->renderShow($id, 'app_shipping_admin_repositioning_index', 'app_shipping_admin_dashboard', false);
    }

    #[Route('/shipping-admin/repositioning/{id}/cancel', name: 'app_shipping_admin_repositioning_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        return $this->handleCancel($id, $request, 'app_shipping_admin_repositioning_index');
    }

    private function renderIndex(string $dashboardRoute): Response
    {
        /** @var StaffUser $user */
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        if (!$shippingLine) {
            throw $this->createAccessDeniedException('No shipping line assigned.');
        }

        $requests = $this->requestRepo->findForShippingLine($shippingLine);
        $pendingCount = $this->requestRepo->countPendingForShippingLine($shippingLine);

        return $this->render('repositioning/index.html.twig', [
            'requests' => $requests,
            'pendingCount' => $pendingCount,
            'dashboardRoute' => $dashboardRoute,
            'canReview' => false,
            'newRoute' => 'app_shipping_admin_repositioning_new',
            'showRoute' => 'app_shipping_admin_repositioning_show',
            'pageTitle' => 'Outbound / Export Requests',
            'pageSubtitle' => 'CY-to-port outbound container transfers — prioritized by dwell time (CAO 8-2019)',
        ]);
    }

    private function handleCreate(Request $request, string $indexRoute, string $dashboardRoute): Response
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
                return $this->redirectToRoute($indexRoute);
            }

            try {
                $typeValue = $request->request->get('request_type', 'export');
                $requestType = RepositioningRequestType::tryFrom($typeValue) ?? RepositioningRequestType::EXPORT;

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
                    'Repositioning request %s submitted with %d container(s). Highest dwell-time containers prioritized per CAO 8-2019.',
                    $created->getRequestNumber(),
                    $created->getContainerCount()
                ));

                return $this->redirectToRoute('app_shipping_admin_repositioning_show', ['id' => $created->getId()]);
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
            'dashboardRoute' => $dashboardRoute,
            'indexRoute' => $indexRoute,
            'formAction' => 'app_shipping_admin_repositioning_new',
            'pageTitle' => 'New Outbound Request',
            'pageSubtitle' => 'Select CY containers for export/repositioning to port — highest dwell time first (CAO 8-2019)',
        ]);
    }

    private function renderShow(int $id, string $indexRoute, string $dashboardRoute, bool $canReview): Response
    {
        /** @var StaffUser $user */
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();

        $req = $this->requestRepo->find($id);
        if (!$req || ($shippingLine && $req->getShippingLine() !== $shippingLine)) {
            throw $this->createNotFoundException('Request not found.');
        }

        return $this->render('repositioning/show.html.twig', [
            'request' => $req,
            'dashboardRoute' => $dashboardRoute,
            'indexRoute' => $indexRoute,
            'canReview' => $canReview,
            'approveRoute' => null,
            'rejectRoute' => null,
            'completeRoute' => null,
            'cancelRoute' => 'app_shipping_admin_repositioning_cancel',
        ]);
    }

    private function handleCancel(int $id, Request $request, string $indexRoute): Response
    {
        $token = new CsrfToken('cancel_repositioning', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute($indexRoute);
        }

        /** @var StaffUser $user */
        $user = $this->getUser();
        $req = $this->requestRepo->find($id);
        if (!$req || $req->getShippingLine() !== $user->getShippingLineScope()) {
            throw $this->createNotFoundException();
        }

        if (!$req->isPending()) {
            $this->addFlash('error', 'Only pending requests can be cancelled.');
            return $this->redirectToRoute('app_shipping_admin_repositioning_show', ['id' => $id]);
        }

        $req->setStatus(\App\Entity\Enum\RepositioningRequestStatus::CANCELLED);
        $this->em->flush();

        $this->addFlash('success', 'Request cancelled.');
        return $this->redirectToRoute($indexRoute);
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
