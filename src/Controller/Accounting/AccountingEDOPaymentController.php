<?php

namespace App\Controller\Accounting;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legacy route alias — eDO access payments belong to system administrators only.
 * Redirects to /admin/edo-payments (or back to accounting dashboard for other roles).
 */
#[Route('/accounting/billing-payments')]
class AccountingEDOPaymentController extends AbstractController
{
    #[Route('/', name: 'accounting_billing_payments_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isGranted('ROLE_SYSTEM_ADMIN')) {
            $query = $request->query->all();

            return $this->redirectToRoute('admin_edo_payments_index', $query, Response::HTTP_MOVED_PERMANENTLY);
        }

        $this->addFlash(
            'info',
            'eDO access payments are validated by system administrators, not shipping-line accounting.'
        );

        return $this->redirectToRoute('app_accounting_dashboard_new');
    }

    #[Route('/{id}/validate', name: 'accounting_billing_payment_validate', methods: ['POST'])]
    public function validate(int $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_SYSTEM_ADMIN')) {
            return $this->json([
                'success' => false,
                'message' => 'eDO access payments may only be validated by system administrators.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => false,
            'message' => 'This endpoint has moved. Use /admin/edo-payments instead.',
        ], Response::HTTP_GONE);
    }

    #[Route('/{id}/receipt', name: 'accounting_billing_payment_receipt', methods: ['GET'])]
    public function viewReceipt(int $id, Request $request): Response
    {
        if ($this->isGranted('ROLE_SYSTEM_ADMIN')) {
            return $this->redirectToRoute('admin_edo_payment_receipt', [
                'id' => $id,
                'inline' => $request->query->get('inline', 'true'),
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        throw $this->createAccessDeniedException('eDO payment receipts are only available to system administrators.');
    }
}
