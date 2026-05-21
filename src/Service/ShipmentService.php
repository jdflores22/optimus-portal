<?php

namespace App\Service;

use App\Entity\Broker;
use App\Entity\ShipmentRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ShipmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditService $auditService
    ) {
    }

    /**
     * Create a new shipment record
     * 
     * @param array $data Shipment data with all fields
     * @param User $staff The SL-Staff user creating the shipment
     * @return ShipmentRecord The created shipment
     * @throws \InvalidArgumentException If required data is missing
     */
    public function createShipment(array $data, User $staff): ShipmentRecord
    {
        if (empty($data['manifestNumber'])) {
            throw new \InvalidArgumentException('Manifest number is required');
        }

        if (empty($data['noticeOfArrivalDate'])) {
            throw new \InvalidArgumentException('Notice of arrival date is required');
        }

        if (empty($data['billingInformation'])) {
            throw new \InvalidArgumentException('Billing information is required');
        }

        $shipment = new ShipmentRecord();
        $shipment->setManifestNumber($data['manifestNumber']);
        $shipment->setNoticeOfArrivalDate($data['noticeOfArrivalDate']);
        $shipment->setBillingInformation($data['billingInformation']);
        $shipment->setCreatedBy($staff);

        // Set consignee if provided
        if (!empty($data['consignee'])) {
            $shipment->setConsignee($data['consignee']);
        }

        // Set optional fields
        if (!empty($data['deliveryOrderNo'])) {
            $shipment->setDeliveryOrderNo($data['deliveryOrderNo']);
        }

        if (!empty($data['blNo'])) {
            $shipment->setBlNo($data['blNo']);
        }

        if (!empty($data['vessel'])) {
            $shipment->setVessel($data['vessel']);
        }

        if (!empty($data['voyage'])) {
            $shipment->setVoyage($data['voyage']);
        }

        if (!empty($data['lloydsNo'])) {
            $shipment->setLloydsNo($data['lloydsNo']);
        }

        if (!empty($data['custStatus'])) {
            $shipment->setCustStatus($data['custStatus']);
        }

        if (!empty($data['custRef'])) {
            $shipment->setCustRef($data['custRef']);
        }

        if (!empty($data['generalDeclarationDt'])) {
            $shipment->setGeneralDeclarationDt($data['generalDeclarationDt']);
        }

        if (!empty($data['vesselCustomNo'])) {
            $shipment->setVesselCustomNo($data['vesselCustomNo']);
        }

        if (!empty($data['agentCustomRegNo'])) {
            $shipment->setAgentCustomRegNo($data['agentCustomRegNo']);
        }

        if (!empty($data['custId'])) {
            $shipment->setCustId($data['custId']);
        }

        if (!empty($data['actualArrivalDate'])) {
            $shipment->setActualArrivalDate($data['actualArrivalDate']);
        }

        // Container Information
        if (!empty($data['containerNumber'])) {
            $shipment->setContainerNumber($data['containerNumber']);
        }

        if (!empty($data['containerType'])) {
            $shipment->setContainerType($data['containerType']);
        }

        if (!empty($data['containerSize'])) {
            $shipment->setContainerSize($data['containerSize']);
        }

        // Commodity Information
        if (!empty($data['commodity'])) {
            $shipment->setCommodity($data['commodity']);
        }

        if (!empty($data['commodityPcs'])) {
            $shipment->setCommodityPcs($data['commodityPcs']);
        }

        if (!empty($data['commodityQty'])) {
            $shipment->setCommodityQty($data['commodityQty']);
        }

        if (!empty($data['netWtKgm'])) {
            $shipment->setNetWtKgm($data['netWtKgm']);
        }

        if (!empty($data['measCbm'])) {
            $shipment->setMeasCbm($data['measCbm']);
        }

        if (!empty($data['emptyReturnAddress'])) {
            $shipment->setEmptyReturnAddress($data['emptyReturnAddress']);
        }

        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        // Log the creation action
        $this->auditService->logAction(
            $staff,
            'create_shipment',
            'ShipmentRecord',
            $shipment->getId(),
            [
                'manifestNumber' => $data['manifestNumber'],
                'noticeOfArrivalDate' => $data['noticeOfArrivalDate']->format('Y-m-d H:i:s'),
                'billingInformation' => substr($data['billingInformation'], 0, 50) . '...'
            ]
        );

        return $shipment;
    }

    /**
     * Auto-authorize linked broker for existing shipments when consignee gets linked
     */
    public function autoAuthorizeBrokerForConsigneeShipments(Consignee $consignee): void
    {
        $linkedBroker = $consignee->getLinkedBroker();
        
        if (!$linkedBroker || $linkedBroker->getStatus()->value !== 'APPROVED') {
            return;
        }

        // Find all shipments for this consignee that don't have the broker authorized
        $shipments = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->where('s.consignee = :consignee')
            ->andWhere(':broker NOT MEMBER OF s.authorizedBrokers')
            ->setParameter('consignee', $consignee)
            ->setParameter('broker', $linkedBroker)
            ->getQuery()
            ->getResult();

        foreach ($shipments as $shipment) {
            $shipment->addAuthorizedBroker($linkedBroker);
        }

        if (!empty($shipments)) {
            $this->entityManager->flush();
        }
    }

    /**
     * Update a shipment record
     * 
     * @param int $shipmentId The shipment ID
     * @param array $data The updated data
     * @param User $user The user performing the update
     * @throws \InvalidArgumentException If shipment not found
     */
    public function updateShipment(int $shipmentId, array $data, User $user): void
    {
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->find($shipmentId);

        if (!$shipment) {
            throw new \InvalidArgumentException('Shipment not found');
        }

        // Track changes for audit log
        $changes = [];

        if (isset($data['actualArrivalDate']) && $data['actualArrivalDate'] !== $shipment->getActualArrivalDate()) {
            $changes['actualArrivalDate'] = [
                'from' => $shipment->getActualArrivalDate()?->format('Y-m-d H:i:s'),
                'to' => $data['actualArrivalDate']->format('Y-m-d H:i:s')
            ];
            $shipment->setActualArrivalDate($data['actualArrivalDate']);
        }

        if (isset($data['billingInformation']) && $data['billingInformation'] !== $shipment->getBillingInformation()) {
            $changes['billingInformation'] = [
                'from' => substr($shipment->getBillingInformation(), 0, 50) . '...',
                'to' => substr($data['billingInformation'], 0, 50) . '...'
            ];
            $shipment->setBillingInformation($data['billingInformation']);
        }

        // Handle all the new fields
        if (isset($data['deliveryOrderNo']) && $data['deliveryOrderNo'] !== $shipment->getDeliveryOrderNo()) {
            $changes['deliveryOrderNo'] = [
                'from' => $shipment->getDeliveryOrderNo(),
                'to' => $data['deliveryOrderNo']
            ];
            $shipment->setDeliveryOrderNo($data['deliveryOrderNo']);
        }

        if (isset($data['blNo']) && $data['blNo'] !== $shipment->getBlNo()) {
            $changes['blNo'] = [
                'from' => $shipment->getBlNo(),
                'to' => $data['blNo']
            ];
            $shipment->setBlNo($data['blNo']);
        }

        if (isset($data['vessel']) && $data['vessel'] !== $shipment->getVessel()) {
            $changes['vessel'] = [
                'from' => $shipment->getVessel(),
                'to' => $data['vessel']
            ];
            $shipment->setVessel($data['vessel']);
        }

        if (isset($data['voyage']) && $data['voyage'] !== $shipment->getVoyage()) {
            $changes['voyage'] = [
                'from' => $shipment->getVoyage(),
                'to' => $data['voyage']
            ];
            $shipment->setVoyage($data['voyage']);
        }

        if (isset($data['lloydsNo']) && $data['lloydsNo'] !== $shipment->getLloydsNo()) {
            $changes['lloydsNo'] = [
                'from' => $shipment->getLloydsNo(),
                'to' => $data['lloydsNo']
            ];
            $shipment->setLloydsNo($data['lloydsNo']);
        }

        if (isset($data['custStatus']) && $data['custStatus'] !== $shipment->getCustStatus()) {
            $changes['custStatus'] = [
                'from' => $shipment->getCustStatus(),
                'to' => $data['custStatus']
            ];
            $shipment->setCustStatus($data['custStatus']);
        }

        if (isset($data['custRef']) && $data['custRef'] !== $shipment->getCustRef()) {
            $changes['custRef'] = [
                'from' => $shipment->getCustRef(),
                'to' => $data['custRef']
            ];
            $shipment->setCustRef($data['custRef']);
        }

        if (isset($data['vesselCustomNo']) && $data['vesselCustomNo'] !== $shipment->getVesselCustomNo()) {
            $changes['vesselCustomNo'] = [
                'from' => $shipment->getVesselCustomNo(),
                'to' => $data['vesselCustomNo']
            ];
            $shipment->setVesselCustomNo($data['vesselCustomNo']);
        }

        if (isset($data['generalDeclarationDt']) && $data['generalDeclarationDt'] !== $shipment->getGeneralDeclarationDt()) {
            $changes['generalDeclarationDt'] = [
                'from' => $shipment->getGeneralDeclarationDt()?->format('Y-m-d'),
                'to' => $data['generalDeclarationDt']->format('Y-m-d')
            ];
            $shipment->setGeneralDeclarationDt($data['generalDeclarationDt']);
        }

        if (isset($data['agentCustomRegNo']) && $data['agentCustomRegNo'] !== $shipment->getAgentCustomRegNo()) {
            $changes['agentCustomRegNo'] = [
                'from' => $shipment->getAgentCustomRegNo(),
                'to' => $data['agentCustomRegNo']
            ];
            $shipment->setAgentCustomRegNo($data['agentCustomRegNo']);
        }

        if (isset($data['custId']) && $data['custId'] !== $shipment->getCustId()) {
            $changes['custId'] = [
                'from' => $shipment->getCustId(),
                'to' => $data['custId']
            ];
            $shipment->setCustId($data['custId']);
        }

        // Container Information
        if (isset($data['containerNumber']) && $data['containerNumber'] !== $shipment->getContainerNumber()) {
            $changes['containerNumber'] = [
                'from' => $shipment->getContainerNumber(),
                'to' => $data['containerNumber']
            ];
            $shipment->setContainerNumber($data['containerNumber']);
        }

        if (isset($data['containerType']) && $data['containerType'] !== $shipment->getContainerType()) {
            $changes['containerType'] = [
                'from' => $shipment->getContainerType(),
                'to' => $data['containerType']
            ];
            $shipment->setContainerType($data['containerType']);
        }

        if (isset($data['containerSize']) && $data['containerSize'] !== $shipment->getContainerSize()) {
            $changes['containerSize'] = [
                'from' => $shipment->getContainerSize(),
                'to' => $data['containerSize']
            ];
            $shipment->setContainerSize($data['containerSize']);
        }

        // Commodity Information
        if (isset($data['commodity']) && $data['commodity'] !== $shipment->getCommodity()) {
            $changes['commodity'] = [
                'from' => $shipment->getCommodity(),
                'to' => $data['commodity']
            ];
            $shipment->setCommodity($data['commodity']);
        }

        if (isset($data['commodityPcs']) && $data['commodityPcs'] !== $shipment->getCommodityPcs()) {
            $changes['commodityPcs'] = [
                'from' => $shipment->getCommodityPcs(),
                'to' => $data['commodityPcs']
            ];
            $shipment->setCommodityPcs($data['commodityPcs']);
        }

        if (isset($data['commodityQty']) && $data['commodityQty'] !== $shipment->getCommodityQty()) {
            $changes['commodityQty'] = [
                'from' => $shipment->getCommodityQty(),
                'to' => $data['commodityQty']
            ];
            $shipment->setCommodityQty($data['commodityQty']);
        }

        if (isset($data['netWtKgm']) && $data['netWtKgm'] !== $shipment->getNetWtKgm()) {
            $changes['netWtKgm'] = [
                'from' => $shipment->getNetWtKgm(),
                'to' => $data['netWtKgm']
            ];
            $shipment->setNetWtKgm($data['netWtKgm']);
        }

        if (isset($data['measCbm']) && $data['measCbm'] !== $shipment->getMeasCbm()) {
            $changes['measCbm'] = [
                'from' => $shipment->getMeasCbm(),
                'to' => $data['measCbm']
            ];
            $shipment->setMeasCbm($data['measCbm']);
        }

        if (isset($data['emptyReturnAddress']) && $data['emptyReturnAddress'] !== $shipment->getEmptyReturnAddress()) {
            $changes['emptyReturnAddress'] = [
                'from' => substr($shipment->getEmptyReturnAddress() ?? '', 0, 50) . '...',
                'to' => substr($data['emptyReturnAddress'] ?? '', 0, 50) . '...'
            ];
            $shipment->setEmptyReturnAddress($data['emptyReturnAddress']);
        }

        $this->entityManager->flush();

        // Log the update action
        if (!empty($changes)) {
            $this->auditService->logAction(
                $user,
                'update_shipment',
                'ShipmentRecord',
                $shipment->getId(),
                $changes
            );
        }
    }

    /**
     * Search for shipments with broker authorization filtering
     * 
     * @param array $criteria Search criteria (blNumber, arrivalDateFrom, arrivalDateTo, consigneeId)
     * @param User $user The user performing the search
     * @return array Array of ShipmentRecord entities
     */
    public function searchShipments(array $criteria, User $user): array
    {
        $qb = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s');

        // Apply authorization filtering based on user role
        if ($user instanceof Broker) {
            // Only return shipments authorized for this broker
            $qb->join('s.authorizedBrokers', 'b')
               ->where('b.id = :brokerId')
               ->setParameter('brokerId', $user->getId());
        } elseif (!in_array($user->getRole()->value, ['SL_STAFF', 'SHIPPING_LINES_ADMIN', 'SYSTEM_ADMIN'])) {
            // Non-staff, non-broker users cannot search shipments
            return [];
        }

        // Apply search filters
        if (!empty($criteria['blNumber'])) {
            $qb->andWhere('s.blNo LIKE :blNumber')
               ->setParameter('blNumber', '%' . $criteria['blNumber'] . '%');
        }

        if (!empty($criteria['arrivalDateFrom'])) {
            $qb->andWhere('s.noticeOfArrivalDate >= :dateFrom')
               ->setParameter('dateFrom', $criteria['arrivalDateFrom']);
        }

        if (!empty($criteria['arrivalDateTo'])) {
            $qb->andWhere('s.noticeOfArrivalDate <= :dateTo')
               ->setParameter('dateTo', $criteria['arrivalDateTo']);
        }

        if (!empty($criteria['consignee']) && $user instanceof Broker) {
            // Verify the consignee is linked to this broker
            $consigneeLinked = false;
            foreach ($user->getLinkedConsignees() as $consignee) {
                if (stripos($consignee->getBusinessName(), $criteria['consignee']) !== false) {
                    $consigneeLinked = true;
                    break;
                }
            }

            // If consignee is not linked to broker, return empty results
            if (!$consigneeLinked) {
                return [];
            }
        }

        $qb->orderBy('s.noticeOfArrivalDate', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Add a broker to the authorized brokers list for a shipment
     * 
     * @param int $shipmentId The shipment ID
     * @param Broker $broker The broker to authorize
     * @param User $staff The staff user performing the authorization
     * @throws \InvalidArgumentException If shipment not found
     */
    public function addAuthorizedBroker(int $shipmentId, Broker $broker, User $staff): void
    {
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->find($shipmentId);

        if (!$shipment) {
            throw new \InvalidArgumentException('Shipment not found');
        }

        // Enforce one broker per shipment policy
        if ($shipment->getAuthorizedBrokers()->count() > 0) {
            throw new \InvalidArgumentException('Only one broker can be authorized per shipment. Please revoke the current broker first.');
        }

        if (!$shipment->isAuthorizedForBroker($broker)) {
            $shipment->addAuthorizedBroker($broker);
            $this->entityManager->flush();

            $this->auditService->logAction(
                $staff,
                'authorize_broker',
                'ShipmentRecord',
                $shipment->getId(),
                [
                    'brokerId' => $broker->getId(),
                    'brokerName' => $broker->getFullName()
                ]
            );
        }
    }

    /**
     * Remove a broker from the authorized brokers list for a shipment
     * 
     * @param int $shipmentId The shipment ID
     * @param Broker $broker The broker to remove
     * @param User $staff The staff user performing the removal
     * @throws \InvalidArgumentException If shipment not found
     */
    public function removeAuthorizedBroker(int $shipmentId, Broker $broker, User $staff): void
    {
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)
            ->find($shipmentId);

        if (!$shipment) {
            throw new \InvalidArgumentException('Shipment not found');
        }

        if ($shipment->isAuthorizedForBroker($broker)) {
            $shipment->removeAuthorizedBroker($broker);
            $this->entityManager->flush();

            $this->auditService->logAction(
                $staff,
                'revoke_broker_authorization',
                'ShipmentRecord',
                $shipment->getId(),
                [
                    'brokerId' => $broker->getId(),
                    'brokerName' => $broker->getFullName()
                ]
            );
        }
    }

    /**
     * Get shipment by ID
     * 
     * @param int $id The shipment ID
     * @return ShipmentRecord|null The shipment or null
     */
    public function getShipmentById(int $id): ?ShipmentRecord
    {
        return $this->entityManager->getRepository(ShipmentRecord::class)
            ->find($id);
    }

    /**
     * Check if a user is authorized to access a shipment
     * 
     * @param ShipmentRecord $shipment The shipment
     * @param User $user The user to check
     * @return bool True if authorized, false otherwise
     */
    public function authorizeAccess(ShipmentRecord $shipment, User $user): bool
    {
        // Staff members can access all shipments
        if (in_array($user->getRole()->value, ['SL_STAFF', 'SHIPPING_LINES_ADMIN', 'SYSTEM_ADMIN'])) {
            return true;
        }

        // Brokers can only access shipments they are authorized for
        if ($user instanceof Broker) {
            return $shipment->isAuthorizedForBroker($user);
        }

        return false;
    }
}
