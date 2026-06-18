<?php

namespace App\Twig;

use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\UserRole;
use App\Entity\StaffUser;
use App\Service\AccreditationWorkflowService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class AccreditationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private Security $security,
        private AccreditationWorkflowService $accreditationService
    ) {
    }

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof StaffUser || $user->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
            return ['shippingAdminPendingAccreditationCount' => 0];
        }

        $shippingLine = $user->getShippingLineScope();
        if (!$shippingLine) {
            return ['shippingAdminPendingAccreditationCount' => 0];
        }

        return [
            'shippingAdminPendingAccreditationCount' => $this->accreditationService
                ->countPendingFinalApprovalForShippingLine($shippingLine),
        ];
    }
}
