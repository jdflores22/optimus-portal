<?php

namespace App\Controller\Api;

use App\Service\ShipmentService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\Enum\UserRole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/shipments', name: 'api_shipments_')]
class ShipmentApiController extends BaseApiController
{
    public function __construct(
        private ShipmentService $shipmentService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function searchShipments(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only brokers and staff can search shipments
        $roleCheck = $this->requireRole($user, [
            UserRole::BROKER->value, 
            UserRole::SL_STAFF->value, 
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::SYSTEM_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $criteria = [
                'manifestNumber' => $request->query->get('manifest_number'),
                'arrivalDateFrom' => $request->query->get('arrival_date_from'),
                'arrivalDateTo' => $request->query->get('arrival_date_to'),
                'consignee' => $request->query->get('consignee')
            ];

            // Filter out null values
            $criteria = array_filter($criteria, fn($value) => $value !== null);

            $shipments = $this->shipmentService->searchShipments($criteria, $user);

            $result = array_map(function($shipment) {
                return [
                    'id' => $shipment->getId(),
                    'manifest_number' => $shipment->getManifestNumber(),
                    'notice_of_arrival_date' => $shipment->getNoticeOfArrivalDate()?->format('Y-m-d'),
                    'actual_arrival_date' => $shipment->getActualArrivalDate()?->format('Y-m-d'),
                    'billing_information' => $shipment->getBillingInformation(),
                    'created_by' => [
                        'id' => $shipment->getCreatedBy()->getId(),
                        'email' => $shipment->getCreatedBy()->getEmail()
                    ],
                    'authorized_brokers' => array_map(function($broker) {
                        return [
                            'id' => $broker->getId(),
                            'email' => $broker->getEmail(),
                            'business_name' => $broker->getFullName()
                        ];
                    }, $shipment->getAuthorizedBrokers()->toArray())
                ];
            }, $shipments);

            return $this->jsonResponse([
                'shipments' => $result,
                'total' => count($result),
                'criteria' => $criteria
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to search shipments: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'])]
    public function getShipmentDetail(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $shipment = $this->shipmentService->getShipmentById($id);
            
            if (!$shipment) {
                return $this->errorResponse('Shipment not found', 404);
            }

            // Check authorization
            if (!$this->shipmentService->authorizeAccess($shipment, $user)) {
                return $this->errorResponse('Access denied', 403);
            }

            return $this->jsonResponse([
                'id' => $shipment->getId(),
                'manifest_number' => $shipment->getManifestNumber(),
                'notice_of_arrival_date' => $shipment->getNoticeOfArrivalDate()?->format('Y-m-d'),
                'actual_arrival_date' => $shipment->getActualArrivalDate()?->format('Y-m-d'),
                'billing_information' => $shipment->getBillingInformation(),
                'created_by' => [
                    'id' => $shipment->getCreatedBy()->getId(),
                    'email' => $shipment->getCreatedBy()->getEmail()
                ],
                'authorized_brokers' => array_map(function($broker) {
                    return [
                        'id' => $broker->getId(),
                        'email' => $broker->getEmail(),
                        'business_name' => $broker->getFullName()
                    ];
                }, $shipment->getAuthorizedBrokers()->toArray()),
                'payments' => array_map(function($payment) {
                    return [
                        'id' => $payment->getId(),
                        'status' => $payment->getStatus()->value,
                        'verified_at' => $payment->getVerifiedAt()?->format('Y-m-d H:i:s'),
                        'edo' => $payment->getEdo() ? [
                            'edo_number' => $payment->getEdo()->getEdoNumber(),
                            'generated_at' => $payment->getEdo()->getGeneratedAt()->format('Y-m-d H:i:s')
                        ] : null
                    ];
                }, $shipment->getPayments()->toArray())
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve shipment details', 500);
        }
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createShipment(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SL-Staff and admins can create shipments
        $roleCheck = $this->requireRole($user, [
            UserRole::SL_STAFF->value, 
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::SYSTEM_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        if (!$data) {
            return $this->errorResponse('Invalid JSON data');
        }

        if (!isset($data['manifest_number']) || !isset($data['billing_information'])) {
            return $this->errorResponse('Manifest number and billing information are required');
        }

        try {
            $shipmentData = [
                'manifestNumber' => $data['manifest_number'],
                'noticeOfArrivalDate' => isset($data['notice_of_arrival_date']) ? new \DateTime($data['notice_of_arrival_date']) : null,
                'actualArrivalDate' => isset($data['actual_arrival_date']) ? new \DateTime($data['actual_arrival_date']) : null,
                'billingInformation' => $data['billing_information']
            ];

            $shipment = $this->shipmentService->createShipment($shipmentData, $user);

            return $this->jsonResponse([
                'success' => true,
                'shipment_id' => $shipment->getId(),
                'manifest_number' => $shipment->getManifestNumber()
            ], 201);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create shipment: ' . $e->getMessage(), 400);
        }
    }
}