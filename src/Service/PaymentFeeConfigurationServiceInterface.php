<?php

namespace App\Service;

use App\Entity\User;

interface PaymentFeeConfigurationServiceInterface
{
    /**
     * Get current eDO payment fee
     * 
     * @return float The current eDO fee amount
     */
    public function getCurrentEDOFee(): float;
    
    /**
     * Update eDO payment fee
     * 
     * @param float $newAmount The new eDO fee amount (must be positive)
     * @param User $admin The SYSTEM_ADMIN performing the update
     * @throws \InvalidArgumentException If amount is not positive
     */
    public function updateEDOFee(float $newAmount, User $admin): void;
    
    /**
     * Get eDO fee configuration history
     * 
     * @return array Array of PaymentFeeConfiguration entities for eDO in descending chronological order
     */
    public function getEDOFeeConfigurationHistory(): array;

    /**
     * Get current manifest access payment fee
     * 
     * @return float The current fee amount
     */
    public function getCurrentManifestAccessFee(): float;
    
    /**
     * Update manifest access payment fee
     * 
     * @param float $newAmount The new fee amount (must be positive)
     * @param User $admin The SYSTEM_ADMIN performing the update
     * @throws \InvalidArgumentException If amount is not positive
     */
    public function updateManifestAccessFee(float $newAmount, User $admin): void;
    
    /**
     * Get payment fee configuration history
     * 
     * @return array Array of PaymentFeeConfiguration entities in descending chronological order
     */
    public function getFeeConfigurationHistory(): array;

    /**
     * Get current QR code path for manifest access payment
     * 
     * @return string|null The QR code file path or null if not set
     */
    public function getCurrentQrCodePath(): ?string;

    /**
     * Update QR code for manifest access payment
     * 
     * @param string|null $qrCodePath The QR code file path
     * @param User $admin The SYSTEM_ADMIN performing the update
     */
    public function updateQrCode(?string $qrCodePath, User $admin): void;
}
