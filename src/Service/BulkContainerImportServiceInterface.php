<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\User;
use App\ValueObject\BulkValidationResult;
use App\ValueObject\ImportResult;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Interface for bulk container import with CY allocation support
 */
interface BulkContainerImportServiceInterface
{
    /**
     * Validate bulk container allocations before import
     * Checks capacity for all containers atomically
     * 
     * @param array $containerData Array of container data from import file
     * @param ShippingLine $shippingLine The shipping line context
     * @return BulkValidationResult Validation result with detailed errors
     */
    public function validateBulkAllocations(
        array $containerData,
        ShippingLine $shippingLine
    ): BulkValidationResult;

    /**
     * Import containers with CY allocations
     * Supports default CY for containers without specified location
     * 
     * @param UploadedFile $file The CSV file containing container data
     * @param ShippingLine $shippingLine The shipping line context
     * @param User $user The user performing the import
     * @param ShippingLineTerminalAllocation|null $defaultAllocation Default CY allocation for containers without specified location
     * @return ImportResult Import result with success/failure and error details
     */
    public function importWithAllocations(
        UploadedFile $file,
        ShippingLine $shippingLine,
        User $user,
        ?ShippingLineTerminalAllocation $defaultAllocation = null
    ): ImportResult;
}
