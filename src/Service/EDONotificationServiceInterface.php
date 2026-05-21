<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\EDOBilling;

/**
 * Interface for eDO-related notifications
 * 
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5
 */
interface EDONotificationServiceInterface
{
    /**
     * Send expiration notifications to Broker and Consignee
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function notifyExpiration(ElectronicDeliveryOrder $edo): void;

    /**
     * Send billing notifications to Broker and Consignee
     * 
     * @param EDOBilling $billing
     * @return void
     */
    public function notifyBilling(EDOBilling $billing): void;

    /**
     * Send eDO generation notifications to Broker and Consignee
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function notifyEDOGenerated(ElectronicDeliveryOrder $edo): void;
}
