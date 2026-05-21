<?php

namespace App\Service;

use App\Entity\ConsigneeBrokerRelationship;
use App\Entity\ReferralCode;
use App\Entity\User;
use App\Repository\ConsigneeBrokerRelationshipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BrokerRelationshipService
{
    public function __construct(
        private ConsigneeBrokerRelationshipRepository $relationshipRepo,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Create a new consignee-broker relationship
     */
    public function createRelationship(
        User $consignee,
        User $broker,
        ReferralCode $referralCode
    ): ConsigneeBrokerRelationship {
        // Check if relationship already exists
        $existing = $this->relationshipRepo->findOneBy([
            'consignee' => $consignee,
            'broker' => $broker
        ]);
        
        if ($existing) {
            // Reactivate if it was terminated
            if ($existing->isTerminated()) {
                $existing->activate();
                $this->em->flush();
                
                $this->logger->info('Broker relationship reactivated', [
                    'consignee_id' => $consignee->getId(),
                    'broker_id' => $broker->getId()
                ]);
                
                return $existing;
            }
            
            throw new \InvalidArgumentException('Relationship already exists between this consignee and broker');
        }
        
        $relationship = new ConsigneeBrokerRelationship();
        $relationship->setConsignee($consignee);
        $relationship->setBroker($broker);
        $relationship->setReferralCode($referralCode);
        $relationship->setStatus(ConsigneeBrokerRelationship::STATUS_ACTIVE);
        
        $this->em->persist($relationship);
        $this->em->flush();
        
        $this->logger->info('Broker relationship created', [
            'consignee_id' => $consignee->getId(),
            'broker_id' => $broker->getId(),
            'referral_code' => $referralCode->getCode()
        ]);
        
        return $relationship;
    }

    /**
     * Get active brokers for a consignee
     */
    public function getActiveBrokersForConsignee(User $consignee): array
    {
        return $this->relationshipRepo->findActiveBrokersForConsignee($consignee);
    }

    /**
     * Get active consignees for a broker
     */
    public function getActiveConsigneesForBroker(User $broker): array
    {
        return $this->relationshipRepo->findActiveConsigneesForBroker($broker);
    }

    /**
     * Check if an active relationship exists
     */
    public function hasActiveRelationship(User $consignee, User $broker): bool
    {
        return $this->relationshipRepo->hasActiveRelationship($consignee, $broker);
    }

    /**
     * Get relationship between consignee and broker
     */
    public function getRelationship(User $consignee, User $broker): ?ConsigneeBrokerRelationship
    {
        return $this->relationshipRepo->findOneBy([
            'consignee' => $consignee,
            'broker' => $broker
        ]);
    }

    /**
     * Suspend a relationship
     */
    public function suspendRelationship(
        ConsigneeBrokerRelationship $relationship,
        User $suspendedBy,
        string $reason
    ): void {
        $relationship->suspend($suspendedBy, $reason);
        $this->em->flush();
        
        $this->logger->warning('Broker relationship suspended', [
            'consignee_id' => $relationship->getConsignee()->getId(),
            'broker_id' => $relationship->getBroker()->getId(),
            'suspended_by' => $suspendedBy->getId(),
            'reason' => $reason
        ]);
    }

    /**
     * Activate a suspended relationship
     */
    public function activateRelationship(ConsigneeBrokerRelationship $relationship): void
    {
        $relationship->activate();
        $this->em->flush();
        
        $this->logger->info('Broker relationship activated', [
            'consignee_id' => $relationship->getConsignee()->getId(),
            'broker_id' => $relationship->getBroker()->getId()
        ]);
    }

    /**
     * Terminate a relationship permanently
     */
    public function terminateRelationship(ConsigneeBrokerRelationship $relationship): void
    {
        $relationship->terminate();
        $this->em->flush();
        
        $this->logger->warning('Broker relationship terminated', [
            'consignee_id' => $relationship->getConsignee()->getId(),
            'broker_id' => $relationship->getBroker()->getId()
        ]);
    }

    /**
     * Get manifest count per broker for a consignee
     */
    public function getManifestCountPerBroker(User $consignee): array
    {
        return $this->relationshipRepo->countManifestsPerBroker($consignee);
    }

    /**
     * Get all relationships for a broker (any status)
     */
    public function getAllRelationshipsForBroker(User $broker): array
    {
        return $this->relationshipRepo->findAllForBroker($broker);
    }

    /**
     * Get all relationships for a consignee (any status)
     */
    public function getAllRelationshipsForConsignee(User $consignee): array
    {
        return $this->relationshipRepo->findAllForConsignee($consignee);
    }

    /**
     * Get relationships created with a specific referral code
     */
    public function getRelationshipsByReferralCode(int $referralCodeId): array
    {
        return $this->relationshipRepo->findByReferralCode($referralCodeId);
    }

    /**
     * Count active brokers for a consignee
     */
    public function countActiveBrokers(User $consignee): int
    {
        return count($this->getActiveBrokersForConsignee($consignee));
    }

    /**
     * Count active consignees for a broker
     */
    public function countActiveConsignees(User $broker): int
    {
        return count($this->getActiveConsigneesForBroker($broker));
    }
}
