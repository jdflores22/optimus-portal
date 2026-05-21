<?php

namespace App\Service;

use App\Entity\ReferralCode;
use App\Entity\User;
use App\Repository\ReferralCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ReferralCodeService
{
    public function __construct(
        private ReferralCodeRepository $referralCodeRepo,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Generate a new referral code for a consignee
     */
    public function generateCode(
        User $consignee,
        ?int $maxUses = null,
        ?\DateTime $expiresAt = null
    ): ReferralCode {
        $code = $this->generateUniqueCode();
        
        $referralCode = new ReferralCode();
        $referralCode->setConsignee($consignee);
        $referralCode->setCode($code);
        $referralCode->setMaxUses($maxUses);
        $referralCode->setExpiresAt($expiresAt);
        $referralCode->setCreatedBy($consignee);
        
        $this->em->persist($referralCode);
        $this->em->flush();
        
        $this->logger->info('Referral code generated', [
            'code' => $code,
            'consignee_id' => $consignee->getId(),
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s')
        ]);
        
        return $referralCode;
    }

    /**
     * Validate a referral code
     * 
     * @return array{valid: bool, error?: string, referralCode?: ReferralCode}
     */
    public function validateCode(string $code): array
    {
        $referralCode = $this->referralCodeRepo->findOneBy(['code' => $code]);
        
        if (!$referralCode) {
            return [
                'valid' => false,
                'error' => 'Invalid referral code'
            ];
        }
        
        if (!$referralCode->isActive()) {
            return [
                'valid' => false,
                'error' => 'This referral code has been deactivated'
            ];
        }
        
        if ($referralCode->isExpired()) {
            return [
                'valid' => false,
                'error' => 'This referral code has expired'
            ];
        }
        
        // Check if code has already reached max uses (already used up)
        if ($referralCode->hasReachedMaxUses()) {
            return [
                'valid' => false,
                'error' => 'This referral code has already been used and is no longer available'
            ];
        }
        
        return [
            'valid' => true,
            'referralCode' => $referralCode
        ];
    }

    /**
     * Increment the usage count of a referral code
     */
    public function incrementUsage(ReferralCode $referralCode): void
    {
        $referralCode->incrementUses();
        $this->em->flush();
        
        $this->logger->info('Referral code usage incremented', [
            'code' => $referralCode->getCode(),
            'current_uses' => $referralCode->getCurrentUses(),
            'max_uses' => $referralCode->getMaxUses()
        ]);
        
        // Auto-deactivate if max uses reached
        if ($referralCode->hasReachedMaxUses()) {
            $this->deactivateCode($referralCode);
        }
    }

    /**
     * Deactivate a referral code
     */
    public function deactivateCode(ReferralCode $referralCode): void
    {
        $referralCode->setIsActive(false);
        $referralCode->setDeactivatedAt(new \DateTime());
        $this->em->flush();
        
        $this->logger->info('Referral code deactivated', [
            'code' => $referralCode->getCode(),
            'consignee_id' => $referralCode->getConsignee()->getId()
        ]);
    }

    /**
     * Reactivate a referral code
     */
    public function reactivateCode(ReferralCode $referralCode): void
    {
        // Check if it can be reactivated
        if ($referralCode->hasReachedMaxUses()) {
            throw new \InvalidArgumentException('Cannot reactivate code that has reached max uses');
        }
        
        if ($referralCode->isExpired()) {
            throw new \InvalidArgumentException('Cannot reactivate expired code');
        }
        
        $referralCode->setIsActive(true);
        $referralCode->setDeactivatedAt(null);
        $this->em->flush();
        
        $this->logger->info('Referral code reactivated', [
            'code' => $referralCode->getCode(),
            'consignee_id' => $referralCode->getConsignee()->getId()
        ]);
    }

    /**
     * Get all active codes for a consignee
     */
    public function getActiveCodesForConsignee(User $consignee): array
    {
        return $this->referralCodeRepo->findActiveByConsignee($consignee);
    }

    /**
     * Get codes with usage statistics for a consignee
     */
    public function getCodesWithStats(User $consignee): array
    {
        return $this->referralCodeRepo->findWithUsageStats($consignee);
    }

    /**
     * Clean up expired codes (can be run as a scheduled task)
     */
    public function cleanupExpiredCodes(): int
    {
        $expiredCodes = $this->referralCodeRepo->findExpiredActiveCodes();
        $count = 0;
        
        foreach ($expiredCodes as $code) {
            $this->deactivateCode($code);
            $count++;
        }
        
        $this->logger->info('Expired referral codes cleaned up', ['count' => $count]);
        
        return $count;
    }

    /**
     * Clean up codes that reached max uses (can be run as a scheduled task)
     */
    public function cleanupMaxUsedCodes(): int
    {
        $maxUsedCodes = $this->referralCodeRepo->findMaxUsedActiveCodes();
        $count = 0;
        
        foreach ($maxUsedCodes as $code) {
            $this->deactivateCode($code);
            $count++;
        }
        
        $this->logger->info('Max-used referral codes cleaned up', ['count' => $count]);
        
        return $count;
    }

    /**
     * Generate a unique referral code
     */
    private function generateUniqueCode(): string
    {
        $maxAttempts = 10;
        $attempt = 0;
        
        do {
            $code = 'CONS-' . strtoupper(bin2hex(random_bytes(4)));
            $existing = $this->referralCodeRepo->findOneBy(['code' => $code]);
            $attempt++;
            
            if ($attempt >= $maxAttempts) {
                throw new \RuntimeException('Failed to generate unique referral code after ' . $maxAttempts . ' attempts');
            }
        } while ($existing !== null);
        
        return $code;
    }
}
