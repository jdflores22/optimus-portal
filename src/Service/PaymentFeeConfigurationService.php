<?php

namespace App\Service;

use App\Entity\PaymentFeeConfiguration;
use App\Entity\User;
use App\Repository\PaymentFeeConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;

class PaymentFeeConfigurationService implements PaymentFeeConfigurationServiceInterface
{
    private const FEE_TYPE_MANIFEST_ACCESS = 'manifest_access';
    private const FEE_TYPE_EDO = 'edo';
    private const DEFAULT_FEE_AMOUNT = 500.00;
    private const DEFAULT_EDO_FEE_AMOUNT = 750.00;

    public function __construct(
        private readonly PaymentFeeConfigurationRepository $repository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function getCurrentEDOFee(): float
    {
        $currentConfig = $this->repository->getCurrentFeeByType(self::FEE_TYPE_EDO);

        if ($currentConfig === null) {
            return self::DEFAULT_EDO_FEE_AMOUNT;
        }

        return $currentConfig->getAmount();
    }

    public function updateEDOFee(float $newAmount, User $admin): void
    {
        if ($newAmount <= 0) {
            throw new \InvalidArgumentException('Fee amount must be a positive decimal value');
        }

        $currentAmount = $this->getCurrentEDOFee();
        
        // Deactivate the current active configuration
        $currentConfig = $this->repository->getCurrentFeeByType(self::FEE_TYPE_EDO);
        if ($currentConfig) {
            $currentConfig->setIsActive(false);
            $this->entityManager->persist($currentConfig);
        }

        // Create and activate new configuration
        $newConfig = new PaymentFeeConfiguration();
        $newConfig->setFeeType(self::FEE_TYPE_EDO);
        $newConfig->setAmount($newAmount);
        $newConfig->setConfiguredBy($admin);
        $newConfig->setConfiguredAt(new \DateTime());
        $newConfig->setPreviousAmount($currentAmount);
        $newConfig->setIsActive(true);

        $this->entityManager->persist($newConfig);
        $this->entityManager->flush();
        
        // Clear entity manager cache to ensure fresh data on next read
        $this->entityManager->clear();
    }

    public function getEDOFeeConfigurationHistory(): array
    {
        // Use the repository method that disables caching
        return $this->repository->getFeeHistoryByType(self::FEE_TYPE_EDO);
    }

    public function getCurrentManifestAccessFee(): float
    {
        $currentConfig = $this->repository->getCurrentFeeByType(self::FEE_TYPE_MANIFEST_ACCESS);

        if ($currentConfig === null) {
            return self::DEFAULT_FEE_AMOUNT;
        }

        return $currentConfig->getAmount();
    }

    public function updateManifestAccessFee(float $newAmount, User $admin): void
    {
        if ($newAmount <= 0) {
            throw new \InvalidArgumentException('Fee amount must be a positive decimal value');
        }

        $currentAmount = $this->getCurrentManifestAccessFee();
        $currentQrCode = $this->getCurrentQrCodePath();
        
        // Deactivate the current active configuration
        $currentConfig = $this->repository->getCurrentFeeByType(self::FEE_TYPE_MANIFEST_ACCESS);
        if ($currentConfig) {
            $currentConfig->setIsActive(false);
            $this->entityManager->persist($currentConfig);
        }

        // Create and activate new configuration (copy QR code from previous)
        $newConfig = new PaymentFeeConfiguration();
        $newConfig->setFeeType(self::FEE_TYPE_MANIFEST_ACCESS);
        $newConfig->setAmount($newAmount);
        $newConfig->setConfiguredBy($admin);
        $newConfig->setConfiguredAt(new \DateTime());
        $newConfig->setPreviousAmount($currentAmount);
        $newConfig->setIsActive(true);
        $newConfig->setQrCodePath($currentQrCode); // Copy QR code from previous config

        $this->entityManager->persist($newConfig);
        $this->entityManager->flush();
        
        // Clear entity manager cache to ensure fresh data on next read
        $this->entityManager->clear();
    }

    public function getFeeConfigurationHistory(): array
    {
        // Use the repository method that disables caching
        return $this->repository->getFeeHistoryByType(self::FEE_TYPE_MANIFEST_ACCESS);
    }

    public function getCurrentQrCodePath(): ?string
    {
        $currentConfig = $this->repository->getCurrentFeeByType(self::FEE_TYPE_MANIFEST_ACCESS);

        if ($currentConfig === null) {
            return null;
        }

        return $currentConfig->getQrCodePath();
    }

    public function updateQrCode(?string $qrCodePath, User $admin): void
    {
        $currentConfig = $this->repository->getCurrentFeeByType(self::FEE_TYPE_MANIFEST_ACCESS);
        
        if ($currentConfig) {
            $currentConfig->setQrCodePath($qrCodePath);
            $this->entityManager->persist($currentConfig);
            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }
}
