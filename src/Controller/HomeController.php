<?php

namespace App\Controller;

use App\Entity\Enum\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // If user is logged in, redirect to their role-specific dashboard
        if ($this->getUser()) {
            $user = $this->getUser();
            $role = $user->getRole();
            
            return match ($role) {
                UserRole::SYSTEM_ADMIN => $this->redirectToRoute('app_admin_dashboard'),
                UserRole::SHIPPING_LINES_ADMIN => $this->redirectToRoute('app_shipping_admin_dashboard'),
                UserRole::EVALUATOR => $this->redirectToRoute('app_evaluator_dashboard'),
                UserRole::SL_STAFF => $this->redirectToRoute('app_sl_staff_dashboard'),
                UserRole::ACCOUNTING => $this->redirectToRoute('app_accounting_dashboard_new'),
                UserRole::BROKER => $this->redirectToRoute('broker_workspace_selector'),
                UserRole::CONSIGNEE => $this->redirectToRoute('app_consignee_dashboard'),
                UserRole::TRUCKER => $this->redirectToRoute('trucker_dashboard'),
                default => $this->render('features/index.html.twig')
            };
        }

        return $this->render('features/index.html.twig');
    }

    #[Route('/features', name: 'app_features')]
    public function features(): Response
    {
        // Redirect to homepage for backward compatibility
        return $this->redirectToRoute('app_home');
    }
}
