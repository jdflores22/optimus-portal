<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legacy payment validation URLs — redirect to canonical accounting routes.
 */
#[Route('/admin/payment-validation')]
class PaymentValidationController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_payment_validation_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->redirectToRoute('app_accounting_dashboard_new', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/final-payment', name: 'admin_payment_validation_final_payment', methods: ['GET'])]
    public function finalPayments(): Response
    {
        return $this->redirectToRoute('accounting_payment_final_list', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/final-payment/{id}', name: 'admin_payment_validation_final_payment_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function finalPaymentDetail(int $id): Response
    {
        return $this->redirectToRoute('accounting_payment_final_detail', ['id' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/edo-access', name: 'admin_payment_validation_edo_access', methods: ['GET'])]
    public function edoAccessList(): Response
    {
        return $this->redirectToRoute('admin_edo_payments_index', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/edo-access/{id}', name: 'admin_payment_validation_edo_access_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edoAccessDetail(int $id): Response
    {
        return $this->redirectToRoute('admin_edo_payments_index', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @deprecated Use /admin/payment-validation/edo-access
     */
    #[Route('/manifest-access', name: 'admin_payment_validation_manifest_access', methods: ['GET'])]
    public function manifestAccessList(): Response
    {
        return $this->redirectToRoute('admin_payment_validation_edo_access', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @deprecated Use /admin/payment-validation/edo-access/{id}
     */
    #[Route('/manifest-access/{id}', name: 'admin_payment_validation_manifest_access_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function manifestAccessDetail(int $id): Response
    {
        return $this->redirectToRoute('admin_payment_validation_edo_access_detail', ['id' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }
}
