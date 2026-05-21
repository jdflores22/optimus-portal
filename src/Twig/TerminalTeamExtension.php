<?php

namespace App\Twig;

use App\Entity\Enum\PreAdviceStatus;
use App\Entity\PreAdviceRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class TerminalTeamExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security
    ) {
    }

    public function getGlobals(): array
    {
        $pendingPreAdviceCount = 0;
        
        $user = $this->security->getUser();
        
        // Only calculate for Terminal Team users
        if ($user && method_exists($user, 'getRole') && $user->getRole()->value === 'TERMINAL_TEAM') {
            // Get the user's shipping line scope
            if (method_exists($user, 'getShippingLineScope')) {
                $shippingLine = $user->getShippingLineScope();
                
                if ($shippingLine !== null) {
                    // Count pending pre-advice requests for this shipping line
                    $pendingPreAdviceCount = $this->entityManager
                        ->getRepository(PreAdviceRequest::class)
                        ->createQueryBuilder('p')
                        ->select('COUNT(p.id)')
                        ->where('p.status = :status')
                        ->andWhere('p.shippingLine = :shippingLine')
                        ->setParameter('status', PreAdviceStatus::PENDING)
                        ->setParameter('shippingLine', $shippingLine)
                        ->getQuery()
                        ->getSingleScalarResult();
                }
            }
        }
        
        return [
            'pending_pre_advice_count' => $pendingPreAdviceCount,
        ];
    }
}
