<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\Manifest;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing payment history and version chains
 * Provides methods to retrieve payment history, payment chains, and statistics
 * with caching for optimal performance
 */
class PaymentHistoryService implements PaymentHistoryServiceInterface
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private EntityManagerInterface $entityManager,
        private CacheInterface $paymentHistoryCache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get complete payment history for a manifest
     * Returns all payment versions ordered by version number (ascending)
     * 
     * @param Manifest $manifest The manifest to get payment history for
     * @param string $paymentType The payment type (e.g., 'final_payment')
     * @return array Array of Payment entities ordered by version
     */
    public function getPaymentHistory(Manifest $manifest, string $paymentType): array
    {
        $cacheKey = sprintf('payment_history_%d_%s', $manifest->getId(), $paymentType);
        
        try {
            return $this->paymentHistoryCache->get($cacheKey, function (ItemInterface $item) use ($manifest, $paymentType) {
                $item->expiresAfter(300); // 5 minutes
                
                $this->logger->info('Cache miss for payment history', [
                    'manifest_id' => $manifest->getId(),
                    'payment_type' => $paymentType
                ]);
                
                return $this->paymentRepository->findAllVersionsByManifest($manifest, $paymentType);
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get payment history from cache', [
                'manifest_id' => $manifest->getId(),
                'payment_type' => $paymentType,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to direct repository call
            return $this->paymentRepository->findAllVersionsByManifest($manifest, $paymentType);
        }
    }

    /**
     * Get payment chain starting from a specific payment
     * Walks backwards to find the root (v1) then builds forward chain
     * 
     * @param Payment $payment The payment to get the chain for
     * @return array Array of Payment entities from v1 to latest version
     */
    public function getPaymentChain(Payment $payment): array
    {
        $cacheKey = sprintf('payment_chain_%d', $payment->getId());
        
        try {
            return $this->paymentHistoryCache->get($cacheKey, function (ItemInterface $item) use ($payment) {
                $item->expiresAfter(300); // 5 minutes
                
                $this->logger->info('Cache miss for payment chain', [
                    'payment_id' => $payment->getId(),
                    'version' => $payment->getVersion()
                ]);
                
                return $this->paymentRepository->getPaymentChain($payment);
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get payment chain from cache', [
                'payment_id' => $payment->getId(),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to direct repository call
            return $this->paymentRepository->getPaymentChain($payment);
        }
    }

    /**
     * Get payment statistics for a manifest
     * Includes total versions, rejections, current version, and submission dates
     * 
     * @param Manifest $manifest The manifest to get statistics for
     * @param string $paymentType The payment type (e.g., 'final_payment')
     * @return array Statistics array with keys: total_versions, total_rejections, 
     *               current_version, first_submission, last_submission
     */
    public function getPaymentStatistics(Manifest $manifest, string $paymentType): array
    {
        $cacheKey = sprintf('payment_statistics_%d_%s', $manifest->getId(), $paymentType);
        
        try {
            return $this->paymentHistoryCache->get($cacheKey, function (ItemInterface $item) use ($manifest, $paymentType) {
                $item->expiresAfter(300); // 5 minutes
                
                $this->logger->info('Cache miss for payment statistics', [
                    'manifest_id' => $manifest->getId(),
                    'payment_type' => $paymentType
                ]);
                
                $versions = $this->paymentRepository->findAllVersionsByManifest($manifest, $paymentType);
                
                if (empty($versions)) {
                    return [
                        'total_versions' => 0,
                        'total_rejections' => 0,
                        'current_version' => 0,
                        'first_submission' => null,
                        'last_submission' => null,
                    ];
                }
                
                $rejections = array_filter($versions, function (Payment $payment) {
                    return $payment->getStatus()->value === 'rejected';
                });
                
                $lastPayment = end($versions);
                
                return [
                    'total_versions' => count($versions),
                    'total_rejections' => count($rejections),
                    'current_version' => $lastPayment->getVersion(),
                    'first_submission' => $versions[0]->getCreatedAt(),
                    'last_submission' => $lastPayment->getCreatedAt(),
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get payment statistics from cache', [
                'manifest_id' => $manifest->getId(),
                'payment_type' => $paymentType,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to direct calculation
            $versions = $this->paymentRepository->findAllVersionsByManifest($manifest, $paymentType);
            
            if (empty($versions)) {
                return [
                    'total_versions' => 0,
                    'total_rejections' => 0,
                    'current_version' => 0,
                    'first_submission' => null,
                    'last_submission' => null,
                ];
            }
            
            $rejections = array_filter($versions, function (Payment $payment) {
                return $payment->getStatus()->value === 'rejected';
            });
            
            $lastPayment = end($versions);
            
            return [
                'total_versions' => count($versions),
                'total_rejections' => count($rejections),
                'current_version' => $lastPayment->getVersion(),
                'first_submission' => $versions[0]->getCreatedAt(),
                'last_submission' => $lastPayment->getCreatedAt(),
            ];
        }
    }

    /**
     * Invalidate payment history cache for a manifest
     * Should be called when a new payment is submitted or validated
     * 
     * @param Manifest $manifest The manifest to invalidate cache for
     * @param string $paymentType The payment type
     */
    public function invalidatePaymentHistoryCache(Manifest $manifest, string $paymentType): void
    {
        $cacheKeys = [
            sprintf('payment_history_%d_%s', $manifest->getId(), $paymentType),
            sprintf('payment_statistics_%d_%s', $manifest->getId(), $paymentType),
        ];
        
        try {
            foreach ($cacheKeys as $cacheKey) {
                $this->paymentHistoryCache->delete($cacheKey);
            }
            
            $this->logger->info('Invalidated payment history cache', [
                'manifest_id' => $manifest->getId(),
                'payment_type' => $paymentType
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate payment history cache', [
                'manifest_id' => $manifest->getId(),
                'payment_type' => $paymentType,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate payment chain cache for a specific payment
     * Should be called when a payment is updated or a new version is created
     * 
     * @param Payment $payment The payment to invalidate cache for
     */
    public function invalidatePaymentChainCache(Payment $payment): void
    {
        // Invalidate cache for this payment and all payments in its chain
        $chain = $this->paymentRepository->getPaymentChain($payment);
        
        try {
            foreach ($chain as $chainPayment) {
                $cacheKey = sprintf('payment_chain_%d', $chainPayment->getId());
                $this->paymentHistoryCache->delete($cacheKey);
            }
            
            $this->logger->info('Invalidated payment chain cache', [
                'payment_id' => $payment->getId(),
                'chain_length' => count($chain)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate payment chain cache', [
                'payment_id' => $payment->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
