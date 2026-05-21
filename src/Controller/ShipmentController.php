<?php

namespace App\Controller;

use App\Entity\Broker;
use App\Entity\ShipmentRecord;
use App\Entity\Enum\UserRole;
use App\Service\ShipmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/shipments')]
class ShipmentController extends AbstractController
{
    public function __construct(
        private ShipmentService $shipmentService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * List all shipments with search and filters
     * DEPRECATED: Redirects to new NOA workflow
     */
    #[Route('/', name: 'shipment_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')] // Allow both SL_STAFF and CONSIGNEE
    public function list(Request $request): Response
    {
        // Redirect to new NOA workflow
        return $this->redirectToRoute('manifest_workflow_list');
    }

    /**
     * Show shipment creation form
     * DEPRECATED: Redirects to new NOA workflow
     */
    #[Route('/create', name: 'shipment_create', methods: ['GET'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function create(): Response
    {
        // Redirect to new NOA workflow
        return $this->redirectToRoute('manifest_workflow_create');
    }

    /**
     * Search consignees for shipment creation (AJAX endpoint)
     */
    #[Route('/search-consignees', name: 'shipment_search_consignees', methods: ['GET'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function searchConsignees(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 2) {
            return new JsonResponse(['consignees' => []]);
        }

        $qb = $this->entityManager->createQueryBuilder();
        $consignees = $qb
            ->select('c')
            ->from(\App\Entity\Consignee::class, 'c')
            ->where($qb->expr()->andX(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(c.businessName)', ':query'),
                    $qb->expr()->like('LOWER(c.email)', ':query')
                ),
                $qb->expr()->eq('c.status', ':approvedStatus')
            ))
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setParameter('approvedStatus', \App\Entity\Enum\AccountStatus::APPROVED)
            ->orderBy('c.businessName', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($consignees as $consignee) {
            $linkedBroker = null;
            if ($consignee->getLinkedBroker()) {
                $linkedBroker = [
                    'id' => $consignee->getLinkedBroker()->getId(),
                    'fullName' => $consignee->getLinkedBroker()->getFullName(),
                    'status' => $consignee->getLinkedBroker()->getStatus()->value
                ];
            }

            $result[] = [
                'id' => $consignee->getId(),
                'businessName' => $consignee->getBusinessName(),
                'email' => $consignee->getEmail(),
                'status' => $consignee->getStatus()->value,
                'linkedBroker' => $linkedBroker
            ];
        }

        return new JsonResponse(['consignees' => $result]);
    }

    /**
     * Get shipping line's allocated container yards (AJAX endpoint)
     */
    #[Route('/container-yards', name: 'shipment_container_yards', methods: ['GET'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function getContainerYards(): JsonResponse
    {
        try {
            $user = $this->getUser();
            
            // For SL_STAFF, get their admin's allocations
            // For SHIPPING_LINES_ADMIN, get their own allocations
            $adminUser = $user->getRole()->value === 'SHIPPING_LINES_ADMIN' 
                ? $user 
                : $user->getShippingLineAdmin();
            
            if (!$adminUser) {
                return new JsonResponse([
                    'containerYards' => [],
                    'message' => 'No shipping line admin found for this user. Please contact your administrator.'
                ]);
            }

            // Get allocations for this admin
            $allocations = $this->entityManager->getRepository(\App\Entity\ShippingLineTerminalAllocation::class)
                ->createQueryBuilder('a')
                ->leftJoin('a.terminal', 't')
                ->leftJoin('t.region', 'r')
                ->leftJoin('t.city', 'c')
                ->addSelect('t', 'r', 'c')
                ->where('a.staffUser = :admin')
                ->andWhere('t.isActive = :active')
                ->setParameter('admin', $adminUser)
                ->setParameter('active', true)
                ->orderBy('t.name', 'ASC')
                ->getQuery()
                ->getResult();

            $result = [];
            foreach ($allocations as $allocation) {
                $terminal = $allocation->getTerminal();
                $allocatedCapacity = $allocation->getAllocatedCapacity();
                
                // TODO: Implement current utilization tracking
                // For now, we'll use 0 as current utilization
                $currentUtilization = 0;
                $utilizationPercentage = 0;

                $result[] = [
                    'id' => $terminal->getId(),
                    'name' => $terminal->getName(),
                    'address' => $terminal->getLocation(),
                    'region' => $terminal->getRegion() ? $terminal->getRegion()->getName() : 'N/A',
                    'city' => $terminal->getCity() ? $terminal->getCity()->getName() : 'N/A',
                    'allocatedCapacity' => $allocatedCapacity,
                    'capacity20ft' => $allocation->getCapacity20ft(),
                    'capacity40ft' => $allocation->getCapacity40ft(),
                    'currentUtilization' => $currentUtilization,
                    'utilizationPercentage' => $utilizationPercentage,
                    'availableSpace' => $allocatedCapacity
                ];
            }

            return new JsonResponse(['containerYards' => $result]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => true,
                'message' => $e->getMessage(),
                'containerYards' => []
            ], 500);
        }
    }

    /**
     * Lookup container information by container number (AJAX endpoint)
     */
    #[Route('/lookup-container', name: 'shipment_lookup_container', methods: ['GET'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function lookupContainer(Request $request): JsonResponse
    {
        $containerNumber = $request->query->get('containerNumber');
        
        if (empty($containerNumber)) {
            return new JsonResponse([
                'found' => false,
                'message' => 'Container number is required'
            ]);
        }

        // Lookup container in database
        $container = $this->entityManager->getRepository(\App\Entity\Container::class)
            ->findOneBy(['containerNumber' => $containerNumber]);

        if (!$container) {
            return new JsonResponse([
                'found' => false,
                'message' => 'Container not found in database'
            ]);
        }

        return new JsonResponse([
            'found' => true,
            'container' => [
                'number' => $container->getContainerNumber(),
                'size' => $container->getContainerSize()->getCode(),
                'type' => $container->getContainerType()->getCode(),
                'status' => $container->getStatus()->value
            ]
        ]);
    }

    /**
     * Handle shipment creation
     */
    #[Route('/create', name: 'shipment_store', methods: ['POST'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function store(Request $request): Response
    {
        $user = $this->getUser();
        
        // Explicitly check that only SL_STAFF can create shipments
        if ($user->getRole()->value !== 'SL_STAFF') {
            return $this->redirectToRoute('app_error_access_denied');
        }
        $manifestNumber = $request->request->get('manifestNumber');
        $consigneeId = $request->request->get('consigneeId');
        $deliveryOrderNo = $request->request->get('deliveryOrderNo');
        $blNo = $request->request->get('blNo');
        $vessel = $request->request->get('vessel');
        $voyage = $request->request->get('voyage');
        $lloydsNo = $request->request->get('lloydsNo');
        $custStatus = $request->request->get('custStatus');
        $custRef = $request->request->get('custRef');
        $generalDeclarationDt = $request->request->get('generalDeclarationDt');
        $vesselCustomNo = $request->request->get('vesselCustomNo');
        $agentCustomRegNo = $request->request->get('agentCustomRegNo');
        $custId = $request->request->get('custId');
        $noticeOfArrivalDate = $request->request->get('noticeOfArrivalDate');
        $actualArrivalDate = $request->request->get('actualArrivalDate');
        $billingInformation = $request->request->get('billingInformation');
        
        // Container Information
        $containerNumber = $request->request->get('containerNumber');
        $containerType = $request->request->get('containerType');
        $containerSize = $request->request->get('containerSize');
        
        // Commodity Information
        $commodity = $request->request->get('commodity');
        $commodityPcs = $request->request->get('commodityPcs');
        $commodityQty = $request->request->get('commodityQty');
        $netWtKgm = $request->request->get('netWtKgm');
        $measCbm = $request->request->get('measCbm');
        $emptyReturnAddress = $request->request->get('emptyReturnAddress');

        $errors = [];

        if (empty($manifestNumber)) {
            $errors[] = 'Manifest number is required';
        }

        if (empty($consigneeId)) {
            $errors[] = 'Consignee is required';
        }

        if (empty($noticeOfArrivalDate)) {
            $errors[] = 'Notice of arrival date is required';
        }

        if (empty($billingInformation)) {
            $errors[] = 'Billing information is required';
        }

        // Validate consignee exists
        $consignee = null;
        if ($consigneeId) {
            $consignee = $this->entityManager->getRepository(\App\Entity\Consignee::class)->find($consigneeId);
            if (!$consignee) {
                $errors[] = 'Selected consignee not found';
            }
        }

        if (!empty($errors)) {
            $isShippingLinesAdmin = $user->getRole()->value === 'SHIPPING_LINES_ADMIN';
            return $this->render('shipment/create.html.twig', [
                'errors' => $errors,
                'formData' => $request->request->all(),
                'selectedConsignee' => $consignee,
                'isShippingLinesAdmin' => $isShippingLinesAdmin
            ]);
        }

        try {
            $noticeDate = new \DateTime($noticeOfArrivalDate);
            $actualDate = $actualArrivalDate ? new \DateTime($actualArrivalDate) : null;
            $generalDeclarationDate = $generalDeclarationDt ? new \DateTime($generalDeclarationDt) : null;

            $shipmentData = [
                'manifestNumber' => $manifestNumber,
                'consignee' => $consignee,
                'deliveryOrderNo' => $deliveryOrderNo ?: null,
                'blNo' => $blNo ?: null,
                'vessel' => $vessel ?: null,
                'voyage' => $voyage ?: null,
                'lloydsNo' => $lloydsNo ?: null,
                'custStatus' => $custStatus ?: null,
                'custRef' => $custRef ?: null,
                'generalDeclarationDt' => $generalDeclarationDate,
                'vesselCustomNo' => $vesselCustomNo ?: null,
                'agentCustomRegNo' => $agentCustomRegNo ?: null,
                'custId' => $custId ?: null,
                'noticeOfArrivalDate' => $noticeDate,
                'billingInformation' => $billingInformation,
                // Container Information
                'containerNumber' => $containerNumber ?: null,
                'containerType' => $containerType ?: null,
                'containerSize' => $containerSize ?: null,
                // Commodity Information
                'commodity' => $commodity ?: null,
                'commodityPcs' => $commodityPcs ?: null,
                'commodityQty' => $commodityQty ?: null,
                'netWtKgm' => $netWtKgm ?: null,
                'measCbm' => $measCbm ?: null,
                'emptyReturnAddress' => $emptyReturnAddress ?: null
            ];

            if ($actualDate) {
                $shipmentData['actualArrivalDate'] = $actualDate;
            }

            $shipment = $this->shipmentService->createShipment(
                $shipmentData,
                $this->getUser()
            );

            // Auto-authorize the linked broker if consignee has one
            if ($consignee && $consignee->getLinkedBroker()) {
                $linkedBroker = $consignee->getLinkedBroker();
                // Check if broker is approved before auto-authorizing
                if ($linkedBroker->getStatus()->value === 'APPROVED') {
                    $shipment->addAuthorizedBroker($linkedBroker);
                    $this->entityManager->flush();
                    $this->addFlash('info', 'Linked broker "' . $linkedBroker->getFullName() . '" has been automatically authorized for this shipment.');
                } else {
                    $this->addFlash('warning', 'Linked broker "' . $linkedBroker->getFullName() . '" is not approved and cannot be auto-authorized.');
                }
            } else {
                if ($consignee) {
                    $this->addFlash('info', 'This consignee has no linked broker.');
                }
            }

            $this->addFlash('success', 'Shipment created successfully');
            return $this->redirectToRoute('shipment_detail', ['id' => $shipment->getId()]);
        } catch (\Exception $e) {
            $isShippingLinesAdmin = $user->getRole()->value === 'SHIPPING_LINES_ADMIN';
            return $this->render('shipment/create.html.twig', [
                'errors' => [$e->getMessage()],
                'formData' => $request->request->all(),
                'selectedConsignee' => $consignee,
                'isShippingLinesAdmin' => $isShippingLinesAdmin
            ]);
        }
    }

    /**
     * Show shipment detail and edit form
     */
    #[Route('/{id}', name: 'shipment_detail', methods: ['GET'])]
    #[IsGranted('ROLE_USER')] // Allow both SL_STAFF and CONSIGNEE
    public function detail(int $id): Response
    {
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.payments', 'p')
            ->addSelect('p')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check access permissions based on user role
        $currentUser = $this->getUser();
        $userRole = $currentUser->getRole()->value;
        
        if ($userRole === 'CONSIGNEE') {
            // Consignees can only view their own shipments
            if (!$shipment->getConsignee() || $shipment->getConsignee()->getId() !== $currentUser->getId()) {
                return $this->render('error/shipment_access_denied.html.twig', [
                    'message' => 'You can only view your own shipments'
                ], new Response('', 403));
            }
        } elseif ($userRole === 'BROKER') {
            // Brokers can only view shipments they are authorized for
            $isAuthorized = false;
            foreach ($shipment->getAuthorizedBrokers() as $authorizedBroker) {
                if ($authorizedBroker->getId() === $currentUser->getId()) {
                    $isAuthorized = true;
                    break;
                }
            }
            if (!$isAuthorized) {
                return $this->render('error/shipment_access_denied.html.twig', [
                    'message' => 'You can only view shipments you are authorized for'
                ], new Response('', 403));
            }
        }
        // SL_STAFF, SYSTEM_ADMIN, and other admin roles can view all shipments (no restrictions)

        $isConsignee = $userRole === 'CONSIGNEE';
        $isShippingLinesAdmin = $userRole === 'SHIPPING_LINES_ADMIN';
        
        // Get all approved brokers for authorization management (only for SL_STAFF)
        $allBrokers = [];
        if (!$isConsignee && $userRole !== 'BROKER') {
            $allBrokers = $this->entityManager->getRepository(Broker::class)
                ->createQueryBuilder('b')
                ->where('b.status = :status')
                ->setParameter('status', 'APPROVED')
                ->orderBy('b.fullName', 'ASC')
                ->getQuery()
                ->getResult();
        }

        // Check if shipment has verified payment (cannot be edited)
        $isVerified = $shipment->getPayment() && $shipment->getPayment()->getStatus()->value === 'VERIFIED';
        $isSlStaff = $userRole === 'SL_STAFF';

        return $this->render('shipment/detail.html.twig', [
            'shipment' => $shipment,
            'allBrokers' => $allBrokers,
            'linkedBroker' => $shipment->getConsignee() ? $shipment->getConsignee()->getLinkedBroker() : null,
            'isVerified' => $isVerified,
            'isConsignee' => $isConsignee,
            'isSlStaff' => $isSlStaff,
            'isShippingLinesAdmin' => $isShippingLinesAdmin
        ]);
    }

    /**
     * Show shipment edit form
     */
    #[Route('/{id}/edit', name: 'shipment_edit', methods: ['GET'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function edit(int $id): Response
    {
        $user = $this->getUser();
        
        // Explicitly check that only SL_STAFF can edit shipments
        if ($user->getRole()->value !== 'SL_STAFF') {
            return $this->redirectToRoute('app_error_access_denied');
        }
        
        $isShippingLinesAdmin = $user->getRole()->value === 'SHIPPING_LINES_ADMIN';
        
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.payments', 'p')
            ->addSelect('p')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if shipment has verified payment (cannot be edited)
        if ($shipment->getPayment() && $shipment->getPayment()->getStatus()->value === 'VERIFIED') {
            $this->addFlash('error', 'Cannot edit shipment with verified payment. The shipment is finalized.');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        return $this->render('shipment/edit.html.twig', [
            'shipment' => $shipment,
            'isShippingLinesAdmin' => $isShippingLinesAdmin
        ]);
    }

    /**
     * Handle shipment update
     */
    #[Route('/{id}/update', name: 'shipment_update', methods: ['POST'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function update(int $id, Request $request): Response
    {
        $user = $this->getUser();
        
        // Explicitly check that only SL_STAFF can update shipments
        if ($user->getRole()->value !== 'SL_STAFF') {
            return $this->redirectToRoute('app_error_access_denied');
        }
        
        // Check if shipment has verified payment before allowing updates
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.payments', 'p')
            ->addSelect('p')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        if ($shipment->getPayment() && $shipment->getPayment()->getStatus()->value === 'VERIFIED') {
            $this->addFlash('error', 'Cannot update shipment with verified payment. The shipment is finalized.');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        $actualArrivalDate = $request->request->get('actualArrivalDate');
        $billingInformation = $request->request->get('billingInformation');
        $deliveryOrderNo = $request->request->get('deliveryOrderNo');
        $blNo = $request->request->get('blNo');
        $vessel = $request->request->get('vessel');
        $voyage = $request->request->get('voyage');
        $lloydsNo = $request->request->get('lloydsNo');
        $custStatus = $request->request->get('custStatus');
        $custRef = $request->request->get('custRef');
        $vesselCustomNo = $request->request->get('vesselCustomNo');
        $generalDeclarationDt = $request->request->get('generalDeclarationDt');
        $agentCustomRegNo = $request->request->get('agentCustomRegNo');
        $custId = $request->request->get('custId');
        
        // Container Information
        $containerNumber = $request->request->get('containerNumber');
        $containerType = $request->request->get('containerType');
        $containerSize = $request->request->get('containerSize');
        
        // Commodity Information
        $commodity = $request->request->get('commodity');
        $commodityPcs = $request->request->get('commodityPcs');
        $commodityQty = $request->request->get('commodityQty');
        $netWtKgm = $request->request->get('netWtKgm');
        $measCbm = $request->request->get('measCbm');
        $emptyReturnAddress = $request->request->get('emptyReturnAddress');

        try {
            $updateData = [];

            if ($actualArrivalDate) {
                $updateData['actualArrivalDate'] = new \DateTime($actualArrivalDate);
            }

            if ($billingInformation !== null) {
                $updateData['billingInformation'] = $billingInformation;
            }

            if ($deliveryOrderNo !== null) {
                $updateData['deliveryOrderNo'] = $deliveryOrderNo ?: null;
            }

            if ($blNo !== null) {
                $updateData['blNo'] = $blNo ?: null;
            }

            if ($vessel !== null) {
                $updateData['vessel'] = $vessel ?: null;
            }

            if ($voyage !== null) {
                $updateData['voyage'] = $voyage ?: null;
            }

            if ($lloydsNo !== null) {
                $updateData['lloydsNo'] = $lloydsNo ?: null;
            }

            if ($custStatus !== null) {
                $updateData['custStatus'] = $custStatus ?: null;
            }

            if ($custRef !== null) {
                $updateData['custRef'] = $custRef ?: null;
            }

            if ($vesselCustomNo !== null) {
                $updateData['vesselCustomNo'] = $vesselCustomNo ?: null;
            }

            if ($generalDeclarationDt) {
                $updateData['generalDeclarationDt'] = new \DateTime($generalDeclarationDt);
            }

            if ($agentCustomRegNo !== null) {
                $updateData['agentCustomRegNo'] = $agentCustomRegNo ?: null;
            }

            if ($custId !== null) {
                $updateData['custId'] = $custId ?: null;
            }

            // Container Information
            if ($containerNumber !== null) {
                $updateData['containerNumber'] = $containerNumber ?: null;
            }

            if ($containerType !== null) {
                $updateData['containerType'] = $containerType ?: null;
            }

            if ($containerSize !== null) {
                $updateData['containerSize'] = $containerSize ?: null;
            }

            // Commodity Information
            if ($commodity !== null) {
                $updateData['commodity'] = $commodity ?: null;
            }

            if ($commodityPcs !== null) {
                $updateData['commodityPcs'] = $commodityPcs ?: null;
            }

            if ($commodityQty !== null) {
                $updateData['commodityQty'] = $commodityQty ?: null;
            }

            if ($netWtKgm !== null) {
                $updateData['netWtKgm'] = $netWtKgm ?: null;
            }

            if ($measCbm !== null) {
                $updateData['measCbm'] = $measCbm ?: null;
            }

            if ($emptyReturnAddress !== null) {
                $updateData['emptyReturnAddress'] = $emptyReturnAddress ?: null;
            }

            $this->shipmentService->updateShipment($id, $updateData, $this->getUser());

            $this->addFlash('success', 'Shipment updated successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('shipment_detail', ['id' => $id]);
    }

    /**
     * Authorize a broker for a shipment
     */
    #[Route('/{id}/authorize-broker', name: 'shipment_authorize_broker', methods: ['POST'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function authorizeBroker(int $id, Request $request): Response
    {
        // Check if shipment has verified payment before allowing broker changes
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.payments', 'p')
            ->addSelect('p')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$shipment) {
            throw $this->createNotFoundException('Shipment not found');
        }

        if ($shipment->getPayment() && $shipment->getPayment()->getStatus()->value === 'VERIFIED') {
            $this->addFlash('error', 'Cannot modify broker authorization for shipment with verified payment. The shipment is finalized.');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        $brokerId = $request->request->get('brokerId');

        if (!$brokerId) {
            $this->addFlash('error', 'Broker ID is required');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        try {
            $broker = $this->entityManager->getRepository(Broker::class)->find($brokerId);

            if (!$broker) {
                $this->addFlash('error', 'Broker not found');
                return $this->redirectToRoute('shipment_detail', ['id' => $id]);
            }

            $this->shipmentService->addAuthorizedBroker($id, $broker, $this->getUser());
            $this->addFlash('success', 'Broker authorized successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('shipment_detail', ['id' => $id]);
    }

    /**
     * Revoke broker authorization for a shipment
     */
    #[Route('/{id}/revoke-broker/{brokerId}', name: 'shipment_revoke_broker', methods: ['POST'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function revokeBroker(int $id, int $brokerId): Response
    {
        // Check if shipment has verified payment before allowing broker changes
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.payments', 'p')
            ->addSelect('p')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$shipment) {
            throw $this->createNotFoundException('Shipment not found');
        }

        if ($shipment->getPayment() && $shipment->getPayment()->getStatus()->value === 'VERIFIED') {
            $this->addFlash('error', 'Cannot modify broker authorization for shipment with verified payment. The shipment is finalized.');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        try {
            $broker = $this->entityManager->getRepository(Broker::class)->find($brokerId);

            if (!$broker) {
                $this->addFlash('error', 'Broker not found');
                return $this->redirectToRoute('shipment_detail', ['id' => $id]);
            }

            $this->shipmentService->removeAuthorizedBroker($id, $broker, $this->getUser());
            $this->addFlash('success', 'Broker authorization revoked');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('shipment_detail', ['id' => $id]);
    }

    /**
     * Auto-authorize linked broker for this shipment
     */
    #[Route('/{id}/auto-authorize-broker', name: 'shipment_auto_authorize_broker', methods: ['POST'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function autoAuthorizeBroker(int $id): Response
    {
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.payments', 'p')
            ->addSelect('p')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$shipment) {
            throw $this->createNotFoundException('Shipment not found');
        }

        if ($shipment->getPayment() && $shipment->getPayment()->getStatus()->value === 'VERIFIED') {
            $this->addFlash('error', 'Cannot modify broker authorization for shipment with verified payment. The shipment is finalized.');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        // Enforce one broker per shipment policy
        if ($shipment->getAuthorizedBrokers()->count() > 0) {
            $this->addFlash('error', 'Only one broker can be authorized per shipment. Please revoke the current broker first.');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        $consignee = $shipment->getConsignee();
        if (!$consignee || !$consignee->getLinkedBroker()) {
            $this->addFlash('error', 'No linked broker found for this consignee');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        $linkedBroker = $consignee->getLinkedBroker();
        
        // Check if broker is approved
        if ($linkedBroker->getStatus()->value !== 'APPROVED') {
            $this->addFlash('error', 'Linked broker is not approved');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        // Check if already authorized
        if ($shipment->isAuthorizedForBroker($linkedBroker)) {
            $this->addFlash('info', 'Broker is already authorized for this shipment');
            return $this->redirectToRoute('shipment_detail', ['id' => $id]);
        }

        // Auto-authorize the broker
        $shipment->addAuthorizedBroker($linkedBroker);
        $this->entityManager->flush();

        $this->addFlash('success', 'Linked broker has been automatically authorized');
        return $this->redirectToRoute('shipment_detail', ['id' => $id]);
    }
}
