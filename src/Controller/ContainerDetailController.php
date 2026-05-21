<?php

namespace App\Controller;

use App\Service\ContainerDataInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ContainerDetailController extends AbstractController
{
    public function __construct(
        private ContainerDataInterface $containerDataService
    ) {
    }

    #[Route('/shipping-admin/container/{containerNumber}/details', name: 'app_container_detail')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function containerDetail(string $containerNumber): Response
    {
        // Get current user's shipping line scope
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // Get container data filtered by shipping line
        $containerData = $this->containerDataService->getContainerDetailByNumber(
            $containerNumber,
            $shippingLine
        );
        
        if (!$containerData) {
            throw new NotFoundHttpException('Container not found or you do not have access to view this container.');
        }
        
        return $this->render('container_detail/view.html.twig', [
            'containerNumber' => $containerNumber,
            'containerDetails' => $containerData,
        ]);
    }

    #[Route('/terminal-team/container/{containerNumber}/details', name: 'app_terminal_team_container_detail')]
    #[IsGranted('ROLE_TERMINAL_TEAM')]
    public function terminalTeamContainerDetail(string $containerNumber): Response
    {
        // Terminal team uses the same shipping line scope as their admin
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // Get container data filtered by shipping line
        $containerData = $this->containerDataService->getContainerDetailByNumber(
            $containerNumber,
            $shippingLine
        );
        
        if (!$containerData) {
            throw new NotFoundHttpException('Container not found or you do not have access to view this container.');
        }
        
        return $this->render('container_detail/view.html.twig', [
            'containerNumber' => $containerNumber,
            'containerDetails' => $containerData,
        ]);
    }
}