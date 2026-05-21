<?php

namespace App\Service;

interface SmsServiceInterface
{
    /**
     * Send SMS message to a phone number
     */
    public function sendSms(string $phoneNumber, string $message): bool;

    /**
     * Check if SMS service is available/configured
     */
    public function isAvailable(): bool;

    /**
     * Get delivery status for a message (if supported)
     */
    public function getDeliveryStatus(string $messageId): ?string;
}