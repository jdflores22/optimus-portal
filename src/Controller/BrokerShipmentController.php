<?php

namespace App\Controller;

use App\Entity\Broker;
use App\Entity\PaymentVerification;
use App\Entity\ShipmentRecord;
use App\Entity\AccreditationSubmission;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\PaymentStatus;
use App\Service\FileService;
use App\Service\ShipmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/broker/shipments')]
#[IsGranted('ROLE_BROKER')]
class BrokerShipmentController extends AbstractController
{
    public function __construct(
        private ShipmentService $shipmentService,
        private EntityManagerInterface $entityManager,
        private FileService $fileService
    ) {
    }

    /**
     * Search shipments interface for brokers
     */
    #[Route('/search', name: 'broker_shipment_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof Broker) {
            throw $this->createAccessDeniedException('Only brokers can access this page');
        }

        // Check if broker has approved accreditation
        $accreditationSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->findOneBy(['applicant' => $user], ['submittedAt' => 'DESC']);
        
        if (!$accreditationSubmission || $accreditationSubmission->getStatus() !== AccreditationStatus::APPROVED) {
            $status = $accreditationSubmission ? $accreditationSubmission->getStatus()->value : 'NOT_SUBMITTED';
            return $this->render('broker/accreditation_pending.html.twig', [
                'status' => $status,
                'accreditationStatus' => $accreditationSubmission?->getStatus()->value ?? 'NOT_SUBMITTED'
            ]);
        }

        $blNumber = $request->query->get('blNumber', '');
        $arrivalDateFrom = $request->query->get('arrivalDateFrom', '');
        $arrivalDateTo = $request->query->get('arrivalDateTo', '');
        $consigneeId = $request->query->get('consigneeId', '');

        // Build search criteria
        $criteria = [];
        
        if ($blNumber) {
            $criteria['blNumber'] = $blNumber;
        }
        
        if ($arrivalDateFrom) {
            $dateFrom = \DateTime::createFromFormat('Y-m-d', $arrivalDateFrom);
            if ($dateFrom) {
                $criteria['arrivalDateFrom'] = $dateFrom;
            }
        }
        
        if ($arrivalDateTo) {
            $dateTo = \DateTime::createFromFormat('Y-m-d', $arrivalDateTo);
            if ($dateTo) {
                $dateTo->setTime(23, 59, 59);
                $criteria['arrivalDateTo'] = $dateTo;
            }
        }
        
        if ($consigneeId) {
            $criteria['consigneeId'] = $consigneeId;
        }

        // Search shipments (only returns authorized shipments)
        $shipments = $this->shipmentService->searchShipments($criteria, $user);

        // Get broker's linked consignees for filter dropdown
        $linkedConsignees = $user->getLinkedConsignees();

        return $this->render('broker/shipment_search.html.twig', [
            'shipments' => $shipments,
            'linkedConsignees' => $linkedConsignees,
            'filters' => [
                'blNumber' => $blNumber,
                'arrivalDateFrom' => $arrivalDateFrom,
                'arrivalDateTo' => $arrivalDateTo,
                'consigneeId' => $consigneeId
            ]
        ]);
    }

    /**
     * View shipment details for broker
     */
    #[Route('/{id}', name: 'broker_shipment_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof Broker) {
            throw $this->createAccessDeniedException('Only brokers can access this page');
        }

        // Check if broker has approved accreditation
        $accreditationSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->findOneBy(['applicant' => $user], ['submittedAt' => 'DESC']);
        
        if (!$accreditationSubmission || $accreditationSubmission->getStatus() !== AccreditationStatus::APPROVED) {
            $status = $accreditationSubmission ? $accreditationSubmission->getStatus()->value : 'NOT_SUBMITTED';
            return $this->render('broker/accreditation_pending.html.twig', [
                'status' => $status,
                'accreditationStatus' => $accreditationSubmission?->getStatus()->value ?? 'NOT_SUBMITTED'
            ]);
        }

        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->find($id);

        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if broker is authorized to view this shipment
        if (!$this->shipmentService->authorizeAccess($shipment, $user)) {
            // Instead of throwing exception, render custom error page
            return $this->render('error/shipment_access_denied.html.twig', [
                'shipmentId' => $id
            ], new Response('', 403));
        }

        return $this->render('broker/shipment_detail.html.twig', [
            'shipment' => $shipment
        ]);
    }

    /**
     * Submit payment proof for a shipment
     */
    #[Route('/{id}/submit-payment', name: 'broker_submit_payment', methods: ['POST'])]
    public function submitPayment(int $id, Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof Broker) {
            throw $this->createAccessDeniedException('Only brokers can submit payments');
        }

        // Check if broker has approved accreditation
        $accreditationSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->findOneBy(['applicant' => $user], ['submittedAt' => 'DESC']);
        
        if (!$accreditationSubmission || $accreditationSubmission->getStatus() !== AccreditationStatus::APPROVED) {
            throw $this->createAccessDeniedException('Your broker accreditation must be approved before you can submit payments');
        }

        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->find($id);

        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if broker is authorized
        if (!$this->shipmentService->authorizeAccess($shipment, $user)) {
            // Instead of throwing exception, render custom error page
            return $this->render('error/shipment_access_denied.html.twig', [
                'shipmentId' => $id
            ], new Response('', 403));
        }

        // Check if payment already exists
        if ($shipment->getPayment()) {
            $this->addFlash('error', 'Payment has already been submitted for this shipment');
            return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
        }

        /** @var UploadedFile|null $proofFile */
        $proofFile = $request->files->get('proofOfPayment');

        if (!$proofFile) {
            $this->addFlash('error', 'Please upload proof of payment');
            return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
        }

        // Validate file
        $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        $maxSize = 10 * 1024 * 1024; // 10MB

        if (!in_array($proofFile->getMimeType(), $allowedMimeTypes)) {
            $this->addFlash('error', 'Invalid file type. Only PDF, JPG, and PNG files are allowed');
            return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
        }

        if ($proofFile->getSize() > $maxSize) {
            $this->addFlash('error', 'File size exceeds 10MB limit');
            return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
        }

        try {
            // Store the file using FileService
            $storedFile = $this->fileService->uploadFile($proofFile, 'payment_proof', $user);

            // Create payment verification record
            $payment = new PaymentVerification();
            $payment->setShipment($shipment);
            $payment->setBroker($user);
            $payment->setProofFilePath($storedFile->getFileId());
            $payment->setStatus(PaymentStatus::PENDING_VALIDATION);

            $this->entityManager->persist($payment);
            $this->entityManager->flush();

            $this->addFlash('success', 'Payment proof submitted successfully. It will be reviewed by accounting staff.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to submit payment proof: ' . $e->getMessage());
        }

        return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
    }

    /**
     * Claim a shipment for a linked consignee
     */
    #[Route('/{id}/claim', name: 'broker_claim_shipment', methods: ['POST'])]
    public function claimShipment(int $id, Request $request): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof Broker) {
            throw $this->createAccessDeniedException('Only brokers can claim shipments');
        }

        // Check if broker has approved accreditation
        $accreditationSubmission = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->findOneBy(['applicant' => $user], ['submittedAt' => 'DESC']);
        
        if (!$accreditationSubmission || $accreditationSubmission->getStatus() !== AccreditationStatus::APPROVED) {
            $status = $accreditationSubmission ? $accreditationSubmission->getStatus()->value : 'NOT_SUBMITTED';
            return $this->render('broker/accreditation_pending.html.twig', [
                'status' => $status,
                'accreditationStatus' => $accreditationSubmission?->getStatus()->value ?? 'NOT_SUBMITTED'
            ]);
        }

        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('claim_shipment_' . $id, $token)) {
            $this->addFlash('error', 'Invalid security token');
            return $this->redirectToRoute('app_broker_dashboard');
        }
        
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)->find($id);
        
        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if this shipment belongs to a consignee linked to this broker
        $consignee = $shipment->getConsignee();
        if (!$consignee || $consignee->getLinkedBroker() !== $user) {
            $this->addFlash('error', 'You can only claim shipments for your linked consignees');
            return $this->redirectToRoute('app_broker_dashboard');
        }

        // Check if already authorized
        if ($shipment->isAuthorizedForBroker($user)) {
            $this->addFlash('info', 'You are already authorized for this shipment');
            return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
        }

        // Authorize the broker for this shipment
        $shipment->addAuthorizedBroker($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'Shipment claimed successfully! You now have access to this shipment.');
        return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
    }

    /**
     * Download EDO PDF for a verified payment
     */
    #[Route('/{id}/edo/download', name: 'broker_download_edo', methods: ['GET'])]
    public function downloadEdo(int $id): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof Broker) {
            throw $this->createAccessDeniedException('Only brokers can access this page');
        }

        // Get the shipment
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)->find($id);
        
        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if broker has access to this shipment
        if (!$this->shipmentService->authorizeAccess($shipment, $user)) {
            // Instead of throwing exception, render custom error page
            return $this->render('error/shipment_access_denied.html.twig', [
                'shipmentId' => $id
            ], new Response('', 403));
        }

        // Get the latest payment for this shipment
        $payment = $this->entityManager->getRepository(PaymentVerification::class)
            ->findOneBy(['shipment' => $shipment], ['createdAt' => 'DESC']);

        if (!$payment) {
            throw $this->createNotFoundException('No payment found for this shipment');
        }

        // Check if payment is verified and has EDO
        if ($payment->getStatus() !== PaymentStatus::VERIFIED || !$payment->getEdo()) {
            throw $this->createAccessDeniedException('EDO is not available. Payment must be verified first.');
        }

        $edo = $payment->getEdo();
        
        // Check if EDO belongs to this broker
        if ($payment->getBroker()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only download your own EDO documents');
        }

        try {
            // Decrypt and serve the EDO file
            $decryptedContent = $this->fileService->decryptFile($edo->getPdfPath());
            
            $response = new Response($decryptedContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'attachment; filename="EDO_' . $edo->getEdoNumber() . '.pdf"');
            $response->headers->set('Content-Length', strlen($decryptedContent));
            
            return $response;
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unable to download EDO file. Please contact support.');
            return $this->redirectToRoute('broker_shipment_detail', ['id' => $id]);
        }
    }
}
