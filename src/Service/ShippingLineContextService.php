<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\UserShippingLinePreference;
use App\Repository\UserShippingLinePreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Manages shipping line context for users
 * Handles session storage and database persistence of selected shipping line
 */
class ShippingLineContextService
{
    private const SESSION_KEY = 'selected_shipping_line_id';

    public function __construct(
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private UserShippingLinePreferenceRepository $preferenceRepository
    ) {
    }

    /**
     * Set the current shipping line for a user
     */
    public function setCurrentShippingLine(User $user, ShippingLine $shippingLine): void
    {
        // Store in session
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $shippingLine->getId());

        // Persist to database for future logins
        $this->saveUserPreference($user, $shippingLine);
    }

    /**
     * Get the current shipping line for a user
     * Returns null if no shipping line is selected
     */
    public function getCurrentShippingLine(User $user): ?ShippingLine
    {
        // Try to get from session first
        $session = $this->requestStack->getSession();
        $shippingLineId = $session->get(self::SESSION_KEY);

        if ($shippingLineId) {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($shippingLineId);
            if ($shippingLine && $shippingLine->isActive()) {
                return $shippingLine;
            }
        }

        // Fall back to user's last selected shipping line from database
        $preference = $this->preferenceRepository->findByUser($user);
        if ($preference && $preference->getLastSelectedShippingLine()) {
            $shippingLine = $preference->getLastSelectedShippingLine();
            if ($shippingLine->isActive()) {
                // Restore to session
                $session->set(self::SESSION_KEY, $shippingLine->getId());
                return $shippingLine;
            }
        }

        return null;
    }

    /**
     * Get all shipping lines the user has approved accreditation for
     */
    public function getUserApprovedShippingLines(User $user): array
    {
        // This will be implemented when AccreditationService is updated
        // For now, return all active shipping lines
        return $this->entityManager->getRepository(ShippingLine::class)
            ->findBy(['isActive' => true], ['brandName' => 'ASC']);
    }

    /**
     * Switch to a different shipping line
     */
    public function switchShippingLine(User $user, int $shippingLineId): void
    {
        $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($shippingLineId);
        
        if (!$shippingLine) {
            throw new \InvalidArgumentException("Shipping line with ID {$shippingLineId} not found");
        }

        if (!$shippingLine->isActive()) {
            throw new \InvalidArgumentException("Shipping line {$shippingLine->getBrandName()} is not active");
        }

        $this->setCurrentShippingLine($user, $shippingLine);
    }

    /**
     * Clear shipping line context (logout scenario)
     */
    public function clearShippingLineContext(User $user): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
    }

    /**
     * Save user's shipping line preference to database
     */
    private function saveUserPreference(User $user, ShippingLine $shippingLine): void
    {
        $preference = $this->preferenceRepository->findByUser($user);

        if (!$preference) {
            $preference = new UserShippingLinePreference();
            $preference->setUser($user);
        }

        $preference->setLastSelectedShippingLine($shippingLine);
        $this->preferenceRepository->savePreference($preference);
    }
}
