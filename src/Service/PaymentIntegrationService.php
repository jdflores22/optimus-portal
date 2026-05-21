<?php

namespace App\Service;

use App\Entity\PreAdviceRequest;
use App\Entity\Trucker;
use App\Entity\User;
use App\Entity\Enum\PreAdviceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for integrating FREE-ADVICE payments with existing payment verification system
 */
class PaymentIntegrationService
{
    private const PRE_ADVICE_FEE = 50.00; // Base fee for FREE-ADVICE submission
    private const PAYMENT_TIMEOUT_MINUTES = 30; // Payment timeout in minutes

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentService $paymentService,
        private AuditService $auditService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Calculate FREE-ADVICE fee based on container and terminal
     */
    public function calculatePreAdviceFee(PreAdviceRequest $preAdviceRequest): float
    {
        $baseFee = self::PRE_ADVICE_FEE;
        $container = $preAdviceRequest->getContainer();
        $terminal = $preAdviceRequest->getSelectedTerminal();

        // Add container size surcharge
        $containerSizeSurcharge = match ($container->getContainerSize()->getCode()) {
            '20FT' => 0.00,
            '40FT' => 10.00,
            '40HC' => 15.00,
            '45FT' => 20.00,
            default => 0.00
        };

        // Add terminal type surcharge
        $terminalSurcharge = match ($terminal->getType()->value) {
            'CY' => 0.00,
            'ATI' => 5.00,
            'ICTSI' => 10.00,
            default => 0.00
        };

        $totalFee = $baseFee + $containerSizeSurcharge + $terminalSurcharge;

        $this->logger->info('FREE-ADVICE fee calculated', [
            'pre_advice_id' => $preAdviceRequest->getId(),
            'base_fee' => $baseFee,
            'container_size_surcharge' => $containerSizeSurcharge,
            'terminal_surcharge' => $terminalSurcharge,
            'total_fee' => $totalFee
        ]);

        return $totalFee;
    }

    /**
     * Generate payment reference for FREE-ADVICE request
     */
    public function generatePaymentReference(PreAdviceRequest $preAdviceRequest): string
    {
        $prefix = 'FA'; // FREE-ADVICE
        $timestamp = date('YmdHis');
        $preAdviceId = str_pad($preAdviceRequest->getId(), 6, '0', STR_PAD_LEFT);
        $random = str_pad(random_int(0, 999), 3, '0', STR_PAD_LEFT);

        return $prefix . $timestamp . $preAdviceId . $random;
    }

    /**
     * Process payment for FREE-ADVICE request
     */
    public function processPreAdvicePayment(
        PreAdviceRequest $preAdviceRequest,
        string $paymentMethod,
        array $paymentData
    ): array {
        $fee = $this->calculatePreAdviceFee($preAdviceRequest);
        $paymentReference = $this->generatePaymentReference($preAdviceRequest);

        // Update FREE-ADVICE request with payment reference
        $preAdviceRequest->setPaymentReference($paymentReference);
        $this->entityManager->flush();

        // Process payment based on method
        $paymentResult = match ($paymentMethod) {
            'credit_card' => $this->processCreditCardPayment($preAdviceRequest, $fee, $paymentData),
            'bank_transfer' => $this->processBankTransferPayment($preAdviceRequest, $fee, $paymentData),
            'digital_wallet' => $this->processDigitalWalletPayment($preAdviceRequest, $fee, $paymentData),
            default => throw new \InvalidArgumentException('Unsupported payment method: ' . $paymentMethod)
        };

        $this->logger->info('Pre-advice payment processed', [
            'pre_advice_id' => $preAdviceRequest->getId(),
            'payment_reference' => $paymentReference,
            'payment_method' => $paymentMethod,
            'fee' => $fee,
            'success' => $paymentResult['success']
        ]);

        // Log payment attempt
        $this->auditService->logAction(
            $preAdviceRequest->getTrucker(),
            'process_pre_advice_payment',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'payment_reference' => $paymentReference,
                'payment_method' => $paymentMethod,
                'fee' => $fee,
                'success' => $paymentResult['success'],
                'transaction_id' => $paymentResult['transaction_id'] ?? null
            ]
        );

        return $paymentResult;
    }

    /**
     * Verify payment for pre-advice request
     */
    public function verifyPreAdvicePayment(
        PreAdviceRequest $preAdviceRequest,
        string $transactionId
    ): bool {
        // Simulate payment verification (in real implementation, this would call external payment gateway)
        $isVerified = $this->verifyPaymentWithGateway($transactionId);

        if ($isVerified) {
            // Mark payment as verified in pre-advice request
            $preAdviceRequest->setPaymentVerified(true);
            $preAdviceRequest->setPaymentVerifiedAt(new \DateTime());
            $this->entityManager->flush();

            $this->logger->info('Pre-advice payment verified', [
                'pre_advice_id' => $preAdviceRequest->getId(),
                'transaction_id' => $transactionId,
                'payment_reference' => $preAdviceRequest->getPaymentReference()
            ]);

            // Log payment verification
            $this->auditService->logAction(
                $preAdviceRequest->getTrucker(),
                'verify_pre_advice_payment',
                'PreAdviceRequest',
                $preAdviceRequest->getId(),
                [
                    'transaction_id' => $transactionId,
                    'payment_reference' => $preAdviceRequest->getPaymentReference(),
                    'verified_at' => $preAdviceRequest->getPaymentVerifiedAt()->format('Y-m-d H:i:s')
                ]
            );
        } else {
            $this->logger->warning('Pre-advice payment verification failed', [
                'pre_advice_id' => $preAdviceRequest->getId(),
                'transaction_id' => $transactionId,
                'payment_reference' => $preAdviceRequest->getPaymentReference()
            ]);
        }

        return $isVerified;
    }

    /**
     * Handle payment failure and retry logic
     */
    public function handlePaymentFailure(
        PreAdviceRequest $preAdviceRequest,
        string $failureReason,
        array $failureDetails = []
    ): void {
        // Increment failure count
        $failureCount = $preAdviceRequest->getPaymentFailureCount() + 1;
        $preAdviceRequest->setPaymentFailureCount($failureCount);
        $preAdviceRequest->setLastPaymentFailureReason($failureReason);
        $preAdviceRequest->setLastPaymentFailureAt(new \DateTime());

        // If too many failures, mark as failed
        if ($failureCount >= 3) {
            $preAdviceRequest->setStatus(PreAdviceStatus::CANCELLED);
            $preAdviceRequest->setRejectionReason('Payment failed after multiple attempts: ' . $failureReason);
        }

        $this->entityManager->flush();

        $this->logger->warning('Pre-advice payment failed', [
            'pre_advice_id' => $preAdviceRequest->getId(),
            'failure_reason' => $failureReason,
            'failure_count' => $failureCount,
            'payment_reference' => $preAdviceRequest->getPaymentReference(),
            'failure_details' => $failureDetails
        ]);

        // Log payment failure
        $this->auditService->logAction(
            $preAdviceRequest->getTrucker(),
            'pre_advice_payment_failed',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'failure_reason' => $failureReason,
                'failure_count' => $failureCount,
                'payment_reference' => $preAdviceRequest->getPaymentReference(),
                'failure_details' => $failureDetails
            ]
        );
    }

    /**
     * Check if payment is expired
     */
    public function isPaymentExpired(PreAdviceRequest $preAdviceRequest): bool
    {
        if (!$preAdviceRequest->getPaymentReference()) {
            return false; // No payment initiated
        }

        if ($preAdviceRequest->isPaymentVerified()) {
            return false; // Payment already verified
        }

        $paymentInitiatedAt = $preAdviceRequest->getCreatedAt();
        $expirationTime = clone $paymentInitiatedAt;
        $expirationTime->add(new \DateInterval('PT' . self::PAYMENT_TIMEOUT_MINUTES . 'M'));

        return new \DateTime() > $expirationTime;
    }

    /**
     * Cancel expired payments
     */
    public function cancelExpiredPayments(): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $expiredRequests = $qb->select('pa')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.paymentReference IS NOT NULL')
            ->andWhere('pa.paymentVerified = false')
            ->andWhere('pa.status = :pending')
            ->andWhere('pa.createdAt < :expiredTime')
            ->setParameter('pending', PreAdviceStatus::PENDING)
            ->setParameter('expiredTime', new \DateTime('-' . self::PAYMENT_TIMEOUT_MINUTES . ' minutes'))
            ->getQuery()
            ->getResult();

        $cancelledCount = 0;

        foreach ($expiredRequests as $request) {
            $request->setStatus(PreAdviceStatus::CANCELLED);
            $request->setRejectionReason('Payment expired after ' . self::PAYMENT_TIMEOUT_MINUTES . ' minutes');
            $cancelledCount++;

            $this->logger->info('Pre-advice payment expired and cancelled', [
                'pre_advice_id' => $request->getId(),
                'payment_reference' => $request->getPaymentReference(),
                'created_at' => $request->getCreatedAt()->format('Y-m-d H:i:s')
            ]);
        }

        if ($cancelledCount > 0) {
            $this->entityManager->flush();
        }

        return $cancelledCount;
    }

    /**
     * Get payment statistics for dashboard
     */
    public function getPaymentStatistics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        // Total payments
        $totalPayments = $qb->select('COUNT(pa.id)')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.paymentReference IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        // Verified payments
        $verifiedPayments = $qb->select('COUNT(pa.id)')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.paymentVerified = true')
            ->getQuery()
            ->getSingleScalarResult();

        // Failed payments
        $failedPayments = $qb->select('COUNT(pa.id)')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.paymentFailureCount > 0')
            ->getQuery()
            ->getSingleScalarResult();

        // Payments today
        $today = new \DateTime('today');
        $paymentsToday = $qb->select('COUNT(pa.id)')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.paymentVerifiedAt >= :today')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        // Total revenue
        $totalRevenue = $qb->select('COUNT(pa.id) * :fee')
            ->from(PreAdviceRequest::class, 'pa')
            ->where('pa.paymentVerified = true')
            ->setParameter('fee', self::PRE_ADVICE_FEE)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_payments' => (int)$totalPayments,
            'verified_payments' => (int)$verifiedPayments,
            'failed_payments' => (int)$failedPayments,
            'pending_payments' => (int)$totalPayments - (int)$verifiedPayments - (int)$failedPayments,
            'payments_today' => (int)$paymentsToday,
            'success_rate' => $totalPayments > 0 ? round(($verifiedPayments / $totalPayments) * 100, 2) : 0,
            'total_revenue' => (float)$totalRevenue
        ];
    }

    /**
     * Process credit card payment
     */
    private function processCreditCardPayment(
        PreAdviceRequest $preAdviceRequest,
        float $fee,
        array $paymentData
    ): array {
        // Simulate credit card processing
        // In real implementation, this would integrate with payment gateway like Stripe, PayPal, etc.
        
        $cardNumber = $paymentData['card_number'] ?? '';
        $expiryMonth = $paymentData['expiry_month'] ?? '';
        $expiryYear = $paymentData['expiry_year'] ?? '';
        $cvv = $paymentData['cvv'] ?? '';

        // Basic validation
        if (empty($cardNumber) || empty($expiryMonth) || empty($expiryYear) || empty($cvv)) {
            return [
                'success' => false,
                'error' => 'Missing required credit card information',
                'error_code' => 'MISSING_CARD_INFO'
            ];
        }

        // Simulate payment processing
        $transactionId = 'CC_' . uniqid();
        $success = random_int(1, 10) > 2; // 80% success rate for simulation

        if ($success) {
            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'payment_method' => 'credit_card',
                'amount' => $fee,
                'currency' => 'USD'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Credit card payment declined',
                'error_code' => 'CARD_DECLINED',
                'transaction_id' => $transactionId
            ];
        }
    }

    /**
     * Process bank transfer payment
     */
    private function processBankTransferPayment(
        PreAdviceRequest $preAdviceRequest,
        float $fee,
        array $paymentData
    ): array {
        // Simulate bank transfer processing
        $accountNumber = $paymentData['account_number'] ?? '';
        $routingNumber = $paymentData['routing_number'] ?? '';

        if (empty($accountNumber) || empty($routingNumber)) {
            return [
                'success' => false,
                'error' => 'Missing required bank account information',
                'error_code' => 'MISSING_BANK_INFO'
            ];
        }

        $transactionId = 'BT_' . uniqid();
        
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'payment_method' => 'bank_transfer',
            'amount' => $fee,
            'currency' => 'USD',
            'status' => 'pending_verification' // Bank transfers typically require manual verification
        ];
    }

    /**
     * Process digital wallet payment
     */
    private function processDigitalWalletPayment(
        PreAdviceRequest $preAdviceRequest,
        float $fee,
        array $paymentData
    ): array {
        // Simulate digital wallet processing (PayPal, Apple Pay, Google Pay, etc.)
        $walletType = $paymentData['wallet_type'] ?? '';
        $walletId = $paymentData['wallet_id'] ?? '';

        if (empty($walletType) || empty($walletId)) {
            return [
                'success' => false,
                'error' => 'Missing required digital wallet information',
                'error_code' => 'MISSING_WALLET_INFO'
            ];
        }

        $transactionId = 'DW_' . uniqid();
        $success = random_int(1, 10) > 1; // 90% success rate for simulation

        if ($success) {
            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'payment_method' => 'digital_wallet',
                'wallet_type' => $walletType,
                'amount' => $fee,
                'currency' => 'USD'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Digital wallet payment failed',
                'error_code' => 'WALLET_DECLINED',
                'transaction_id' => $transactionId
            ];
        }
    }

    /**
     * Verify payment with external gateway
     */
    private function verifyPaymentWithGateway(string $transactionId): bool
    {
        // Simulate payment gateway verification
        // In real implementation, this would call the payment gateway's API
        
        $this->logger->info('Verifying payment with gateway', [
            'transaction_id' => $transactionId
        ]);

        // Simulate verification delay
        usleep(500000); // 0.5 second delay

        // Simulate 95% success rate
        return random_int(1, 20) > 1;
    }
}