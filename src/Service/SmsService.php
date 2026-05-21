<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class SmsService implements SmsServiceInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ?string $smsProvider = null,
        private ?string $smsApiKey = null
    ) {
    }

    /**
     * Send SMS message to a phone number
     */
    public function sendSms(string $phoneNumber, string $message): bool
    {
        if (!$this->isAvailable()) {
            $this->logger->warning('SMS service not configured, skipping SMS delivery', [
                'phone_number' => $phoneNumber
            ]);
            return false;
        }

        try {
            // Validate phone number format
            if (!$this->isValidPhoneNumber($phoneNumber)) {
                $this->logger->error('Invalid phone number format for SMS', [
                    'phone_number' => $phoneNumber
                ]);
                return false;
            }

            // For now, simulate SMS sending
            // In production, this would integrate with services like Twilio, AWS SNS, etc.
            $this->logger->info('SMS sent successfully (simulated)', [
                'phone_number' => $phoneNumber,
                'message_length' => strlen($message),
                'provider' => $this->smsProvider
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to send SMS', [
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if SMS service is available/configured
     */
    public function isAvailable(): bool
    {
        return !empty($this->smsProvider) && !empty($this->smsApiKey);
    }

    /**
     * Get delivery status for a message (if supported)
     */
    public function getDeliveryStatus(string $messageId): ?string
    {
        // In production, this would query the SMS provider's API
        return null;
    }

    /**
     * Validate phone number format
     */
    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        // Basic validation - in production, use a proper phone number validation library
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phoneNumber) === 1;
    }
}