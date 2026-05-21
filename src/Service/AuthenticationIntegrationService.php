<?php

namespace App\Service;

use App\Entity\Enum\UserRole;
use App\Entity\TerminalTeamUser;
use App\Entity\Trucker;
use App\Entity\User;
use App\Service\ShippingLineAccessControlService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service for integrating Terminal Team and Trucker authentication with existing user management system
 */
class AuthenticationIntegrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private EventDispatcherInterface $eventDispatcher,
        private ShippingLineAccessControlService $shippingLineAccessControl
    ) {
    }

    /**
     * Create a Terminal Team user with proper role integration
     */
    public function createTerminalTeamUser(
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        string $department,
        array $terminalPermissions = []
    ): TerminalTeamUser {
        $user = new TerminalTeamUser();
        $user->setEmail($email);
        $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
        $user->setRole(UserRole::TERMINAL_TEAM);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setDepartment($department);
        $user->setTerminalPermissions($terminalPermissions);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Create a Trucker user with proper role integration
     */
    public function createTruckerUser(
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        ?string $phoneNumber = null,
        ?string $licenseNumber = null,
        ?string $companyName = null,
        ?string $truckPlateNumber = null
    ): Trucker {
        $user = new Trucker();
        $user->setEmail($email);
        $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
        $user->setRole(UserRole::TRUCKER);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPhoneNumber($phoneNumber);
        $user->setLicenseNumber($licenseNumber);
        $user->setCompanyName($companyName);
        $user->setTruckPlateNumber($truckPlateNumber);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Authenticate a user and create proper session integration
     */
    public function authenticateUser(UserInterface $user, string $firewallName = 'main'): void
    {
        $token = new UsernamePasswordToken($user, $firewallName, $user->getRoles());
        $this->tokenStorage->setToken($token);
    }

    /**
     * Get the appropriate dashboard route based on user role
     */
    public function getDashboardRouteForUser(User $user): string
    {
        return match ($user->getRole()) {
            UserRole::TERMINAL_TEAM => 'app_terminal_team_dashboard',
            UserRole::TRUCKER => 'trucker_dashboard',
            UserRole::SYSTEM_ADMIN => 'app_admin_dashboard',
            UserRole::SHIPPING_LINES_ADMIN => 'app_shipping_admin_dashboard',
            UserRole::EVALUATOR => 'app_evaluator_dashboard',
            UserRole::SL_STAFF => 'app_sl_staff_dashboard',
            UserRole::ACCOUNTING => 'app_accounting_dashboard_new',
            UserRole::BROKER => 'broker_workspace_selector',
            UserRole::CONSIGNEE => 'app_consignee_dashboard',
            default => 'app_home'
        };
    }

    /**
     * Check if user has access to specific functionality
     */
    public function hasAccessToFunction(User $user, string $function): bool
    {
        // Terminal Team specific access control
        if ($user instanceof TerminalTeamUser) {
            return $this->checkTerminalTeamAccess($user, $function);
        }

        // Trucker specific access control
        if ($user instanceof Trucker) {
            return $this->checkTruckerAccess($user, $function);
        }

        // Default role-based access control
        return $this->checkRoleBasedAccess($user->getRole(), $function);
    }

    /**
     * Check Terminal Team specific access permissions
     */
    private function checkTerminalTeamAccess(TerminalTeamUser $user, string $function): bool
    {
        $terminalTeamFunctions = [
            'pre_advice_verification',
            'terminal_dashboard',
            'slot_management',
            'edo_generation',
            'photo_verification',
            'booking_approval',
            'booking_rejection'
        ];

        if (!in_array($function, $terminalTeamFunctions, true)) {
            return false;
        }

        // Check specific terminal permissions if needed
        if (str_starts_with($function, 'terminal_')) {
            $terminalType = substr($function, 9); // Remove 'terminal_' prefix
            return $user->hasTerminalPermission($terminalType) || empty($user->getTerminalPermissions());
        }

        return true;
    }

    /**
     * Check Trucker specific access permissions
     */
    private function checkTruckerAccess(Trucker $user, string $function): bool
    {
        $truckerFunctions = [
            'container_search',
            'pre_advice_submission',
            'photo_upload',
            'payment_processing',
            'edo_download',
            'trucker_dashboard',
            'booking_history'
        ];

        return in_array($function, $truckerFunctions, true);
    }

    /**
     * Check general role-based access
     */
    private function checkRoleBasedAccess(UserRole $role, string $function): bool
    {
        $rolePermissions = [
            UserRole::SYSTEM_ADMIN => ['*'], // System admin has access to everything
            UserRole::SHIPPING_LINES_ADMIN => ['user_management', 'system_configuration'],
            UserRole::EVALUATOR => ['evaluation_functions'],
            UserRole::SL_STAFF => ['staff_functions'],
            UserRole::ACCOUNTING => ['accounting_functions'],
            UserRole::BROKER => ['broker_functions'],
            UserRole::CONSIGNEE => ['consignee_functions']
        ];

        $permissions = $rolePermissions[$role] ?? [];

        // System admin has access to everything
        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($function, $permissions, true);
    }

    /**
     * Update user session with additional security information
     */
    public function updateUserSession(User $user, SessionInterface $session): void
    {
        $session->set('user_id', $user->getId());
        $session->set('user_role', $user->getRole()->value);
        $session->set('user_email', $user->getEmail());
        $session->set('login_time', new \DateTime());

        // Add Terminal Team specific session data
        if ($user instanceof TerminalTeamUser) {
            $session->set('terminal_permissions', $user->getTerminalPermissions());
            $session->set('department', $user->getDepartment());
        }

        // Add Trucker specific session data
        if ($user instanceof Trucker) {
            $session->set('company_name', $user->getCompanyName());
            $session->set('license_number', $user->getLicenseNumber());
        }
    }

    /**
     * Validate user account status for authentication
     */
    public function validateUserAccountStatus(User $user): array
    {
        $errors = [];

        if ($user->isLocked()) {
            $errors[] = 'Account is locked due to multiple failed login attempts.';
        }

        if (!$user->isEmailVerified()) {
            $errors[] = 'Email address must be verified before login.';
        }

        // Check shipping line access
        if (!$this->shippingLineAccessControl->hasAccess($user)) {
            $reason = $this->shippingLineAccessControl->getAccessDenialReason($user);
            if ($reason) {
                $errors[] = $reason;
            }
        }

        return $errors;
    }

    /**
     * Get the shipping line associated with a user
     * @deprecated Use ShippingLineAccessControlService::getUserShippingLine() instead
     */
    private function getUserShippingLine(User $user): ?\App\Entity\ShippingLine
    {
        return $this->shippingLineAccessControl->getUserShippingLine($user);
    }

    /**
     * Generate API token for Trucker mobile access
     */
    public function generateTruckerApiToken(Trucker $trucker, int $validityDays = 30): string
    {
        $token = $trucker->generateApiToken($validityDays);
        $this->entityManager->flush();
        
        return $token;
    }

    /**
     * Revoke API token for Trucker
     */
    public function revokeTruckerApiToken(Trucker $trucker): void
    {
        $trucker->revokeApiToken();
        $this->entityManager->flush();
    }

    /**
     * Find user by API token for mobile authentication
     */
    public function findUserByApiToken(string $token): ?Trucker
    {
        $tokenHash = hash('sha256', $token);
        
        $trucker = $this->entityManager
            ->getRepository(Trucker::class)
            ->findOneBy(['apiTokenHash' => $tokenHash]);

        if ($trucker && $trucker->hasValidApiToken()) {
            // Update last activity
            $trucker->setLastActivityAt(new \DateTime());
            $this->entityManager->flush();
            
            return $trucker;
        }

        return null;
    }
}