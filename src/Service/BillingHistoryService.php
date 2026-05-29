<?php

namespace App\Service;

use App\Entity\Billing;
use App\Entity\EDORenewalRequest;
use App\Repository\BillingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing billing history and version chains
 * Provides methods to retrieve billing history, billing chains, and statistics
 * with caching for optimal performance
 */
class BillingHistoryService implements BillingHistoryServiceInterface
{
    public function __construct(
        private BillingRepository $billingRepository,
        private EntityManagerInterface $entityManager,
        private CacheInterface $cache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get complete billing history for a renewal request
     * Returns all billing versions ordered by version number (ascending)
     * 
     * @param EDORenewalRequest $renewalRequest The renewal request to get billing history for
     * @return array Array of Billing entities ordered by version
     */
    public function getBillingHistory(EDORenewalRequest $renewalRequest): array
    {
        $cacheKey = sprintf('billing_history_%d', $renewalRequest->getId());
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($renewalRequest) {
                $item->expiresAfter(300); // 5 minutes
                
                $this->logger->info('Cache miss for billing history', [
                    'renewal_request_id' => $renewalRequest->getId()
                ]);
                
                return $this->billingRepository->findAllVersionsByRenewalRequest($renewalRequest);
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get billing history from cache', [
                'renewal_request_id' => $renewalRequest->getId(),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to direct repository call
            return $this->billingRepository->findAllVersionsByRenewalRequest($renewalRequest);
        }
    }

    /**
     * Get billing chain starting from a specific billing
     * Walks backwards to find the root (v1) then builds forward chain
     * 
     * @param Billing $billing The billing to get the chain for
     * @return array Array of Billing entities from v1 to latest version
     */
    public function getBillingChain(Billing $billing): array
    {
        $cacheKey = sprintf('billing_chain_%d', $billing->getId());
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($billing) {
                $item->expiresAfter(300); // 5 minutes
                
                $this->logger->info('Cache miss for billing chain', [
                    'billing_id' => $billing->getId(),
                    'version' => $billing->getVersion()
                ]);
                
                return $this->billingRepository->getBillingChain($billing);
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get billing chain from cache', [
                'billing_id' => $billing->getId(),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to direct repository call
            return $this->billingRepository->getBillingChain($billing);
        }
    }

    /**
     * Get billing statistics for a renewal request
     * Includes total versions, current version, and submission dates
     * 
     * @param EDORenewalRequest $renewalRequest The renewal request to get statistics for
     * @return array Statistics array with keys: total_versions, current_version, 
     *               first_submission, last_submission
     */
    public function getBillingStatistics(EDORenewalRequest $renewalRequest): array
    {
        $cacheKey = sprintf('billing_statistics_%d', $renewalRequest->getId());
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($renewalRequest) {
                $item->expiresAfter(300); // 5 minutes
                
                $this->logger->info('Cache miss for billing statistics', [
                    'renewal_request_id' => $renewalRequest->getId()
                ]);
                
                $versions = $this->billingRepository->findAllVersionsByRenewalRequest($renewalRequest);
                
                if (empty($versions)) {
                    return [
                        'total_versions' => 0,
                        'current_version' => 0,
                        'first_submission' => null,
                        'last_submission' => null,
                    ];
                }
                
                $lastBilling = end($versions);
                
                return [
                    'total_versions' => count($versions),
                    'current_version' => $lastBilling->getVersion(),
                    'first_submission' => $versions[0]->getPaymentSubmittedAt(),
                    'last_submission' => $lastBilling->getPaymentSubmittedAt(),
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get billing statistics from cache', [
                'renewal_request_id' => $renewalRequest->getId(),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to direct calculation
            $versions = $this->billingRepository->findAllVersionsByRenewalRequest($renewalRequest);
            
            if (empty($versions)) {
                return [
                    'total_versions' => 0,
                    'current_version' => 0,
                    'first_submission' => null,
                    'last_submission' => null,
                ];
            }
            
            $lastBilling = end($versions);
            
            return [
                'total_versions' => count($versions),
                'current_version' => $lastBilling->getVersion(),
                'first_submission' => $versions[0]->getPaymentSubmittedAt(),
                'last_submission' => $lastBilling->getPaymentSubmittedAt(),
            ];
        }
    }

    /**
     * Invalidate billing history cache for a renewal request
     * Should be called when a new billing is submitted or updated
     * 
     * @param EDORenewalRequest $renewalRequest The renewal request to invalidate cache for
     */
    public function invalidateBillingHistoryCache(EDORenewalRequest $renewalRequest): void
    {
        $cacheKeys = [
            sprintf('billing_history_%d', $renewalRequest->getId()),
            sprintf('billing_statistics_%d', $renewalRequest->getId()),
        ];
        
        try {
            foreach ($cacheKeys as $cacheKey) {
                $this->cache->delete($cacheKey);
            }
            
            $this->logger->info('Invalidated billing history cache', [
                'renewal_request_id' => $renewalRequest->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate billing history cache', [
                'renewal_request_id' => $renewalRequest->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate billing chain cache for a specific billing
     * Should be called when a billing is updated or a new version is created
     * 
     * @param Billing $billing The billing to invalidate cache for
     */
    public function invalidateBillingChainCache(Billing $billing): void
    {
        // Invalidate cache for this billing and all billings in its chain
        $chain = $this->billingRepository->getBillingChain($billing);
        
        try {
            foreach ($chain as $chainBilling) {
                $cacheKey = sprintf('billing_chain_%d', $chainBilling->getId());
                $this->cache->delete($cacheKey);
            }
            
            $this->logger->info('Invalidated billing chain cache', [
                'billing_id' => $billing->getId(),
                'chain_length' => count($chain)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate billing chain cache', [
                'billing_id' => $billing->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
