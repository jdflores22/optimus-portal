<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Repository\ContainerRepository;
use Psr\Log\LoggerInterface;

class ContainerSearchService
{
    public function __construct(
        private ContainerRepository $containerRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Search for a container by container number
     * 
     * @param string $containerNumber The container number to search for
     * @return Container|null The container if found, null otherwise
     */
    public function findByContainerNumber(string $containerNumber): ?Container
    {
        // Normalize container number to uppercase for search
        $containerNumber = strtoupper(trim($containerNumber));
        
        $this->logger->info('Searching for container', ['containerNumber' => $containerNumber]);
        
        $container = $this->containerRepository->findByContainerNumber($containerNumber);
        
        if ($container === null) {
            $this->logger->info('Container not found', ['containerNumber' => $containerNumber]);
        } else {
            $this->logger->info('Container found', [
                'containerNumber' => $containerNumber,
                'status' => $container->getStatus()->value,
                'type' => $container->getContainerType()->getCode(),
                'size' => $container->getContainerSize()->getCode()
            ]);
        }
        
        return $container;
    }

    /**
     * Validate if a container is available for return
     * 
     * @param string $containerNumber The container number to validate
     * @return bool True if container is available for return, false otherwise
     */
    public function isAvailableForReturn(string $containerNumber): bool
    {
        $container = $this->findByContainerNumber($containerNumber);
        
        if ($container === null) {
            return false;
        }
        
        return $this->validateContainerAvailability($container);
    }

    /**
     * Validate container availability based on status and business rules
     * 
     * @param Container $container The container to validate
     * @return bool True if container is available for return, false otherwise
     */
    public function validateContainerAvailability(Container $container): bool
    {
        // Check if container status allows return
        if ($container->getStatus() !== ContainerStatus::AVAILABLE_FOR_RETURN) {
            $this->logger->info('Container not available for return due to status', [
                'containerNumber' => $container->getContainerNumber(),
                'status' => $container->getStatus()->value
            ]);
            return false;
        }

        // Check if expected return date is in the future or today
        $today = new \DateTime('today');
        if ($container->getExpectedReturnDate() < $today) {
            $this->logger->info('Container return date has passed', [
                'containerNumber' => $container->getContainerNumber(),
                'expectedReturnDate' => $container->getExpectedReturnDate()->format('Y-m-d'),
                'today' => $today->format('Y-m-d')
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check container status
     * 
     * @param string $containerNumber The container number to check
     * @return ContainerStatus|null The container status if found, null otherwise
     */
    public function getContainerStatus(string $containerNumber): ?ContainerStatus
    {
        $container = $this->findByContainerNumber($containerNumber);
        
        return $container?->getStatus();
    }

    /**
     * Get container details for display
     * 
     * @param string $containerNumber The container number
     * @return array|null Container details array or null if not found
     */
    public function getContainerDetails(string $containerNumber): ?array
    {
        $container = $this->findByContainerNumber($containerNumber);
        
        if ($container === null) {
            return null;
        }
        
        return [
            'id' => $container->getId(),
            'containerNumber' => $container->getContainerNumber(),
            'size' => $container->getContainerSize()->getCode(),
            'type' => $container->getContainerType()->getCode(),
            'status' => $container->getStatus()->value,
            'currentLocation' => $container->getCurrentLocation(),
            'expectedReturnDate' => $container->getExpectedReturnDate()->format('Y-m-d'),
            'isAvailableForReturn' => $this->validateContainerAvailability($container),
            'createdAt' => $container->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $container->getUpdatedAt()->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Search containers by multiple criteria
     * 
     * @param array $criteria Search criteria (status, type, size, etc.)
     * @return Container[] Array of matching containers
     */
    public function searchContainers(array $criteria = []): array
    {
        $this->logger->info('Searching containers with criteria', $criteria);
        
        if (empty($criteria)) {
            return $this->containerRepository->findAvailableForReturn();
        }
        
        $queryBuilder = $this->containerRepository->createQueryBuilder('c');
        
        if (isset($criteria['status'])) {
            $queryBuilder->andWhere('c.status = :status')
                        ->setParameter('status', $criteria['status']);
        }
        
        if (isset($criteria['type'])) {
            $queryBuilder->andWhere('c.type = :type')
                        ->setParameter('type', $criteria['type']);
        }
        
        if (isset($criteria['size'])) {
            $queryBuilder->andWhere('c.size = :size')
                        ->setParameter('size', $criteria['size']);
        }
        
        if (isset($criteria['location'])) {
            $queryBuilder->andWhere('c.currentLocation LIKE :location')
                        ->setParameter('location', '%' . $criteria['location'] . '%');
        }
        
        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Validate container number format
     * 
     * @param string $containerNumber The container number to validate
     * @return bool True if format is valid, false otherwise
     */
    public function validateContainerNumberFormat(string $containerNumber): bool
    {
        // Normalize to uppercase for validation
        $containerNumber = strtoupper(trim($containerNumber));
        
        // Basic container number format validation
        // Container numbers are typically 11 characters: 4 uppercase letters + 7 digits
        $pattern = '/^[A-Z]{4}[0-9]{7}$/';
        
        return preg_match($pattern, $containerNumber) === 1;
    }
}