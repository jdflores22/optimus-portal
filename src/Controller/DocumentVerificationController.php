<?php

namespace App\Controller;

use App\Service\DocumentVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DocumentVerificationController extends AbstractController
{
    public function __construct(
        private DocumentVerificationService $verificationService,
    ) {
    }

    #[Route('/verify/document/{token}', name: 'document_verification_show', methods: ['GET'])]
    public function show(string $token): Response
    {
        if ($token === DocumentVerificationService::PREVIEW_SAMPLE_TOKEN) {
            return $this->render('document_verification/show.html.twig', [
                'isPreviewSample' => true,
                'verification' => null,
                'summary' => [
                    'document_number' => 'NOA-20260616-0003',
                    'bl_number' => 'BL-PH-2026-0042',
                    'vessel_number' => 'MV PACIFIC STAR',
                    'eta' => '2026-06-20 08:00',
                    'port_location' => 'Manila North Harbor',
                    'consignee_name' => 'ABC Trading Corporation',
                    'container_count' => '3',
                    'company_name' => 'OPTIMUS Shipping Lines',
                    'generated_at' => date('Y-m-d H:i:s'),
                ],
                'documentTypeLabel' => 'Notice of Arrival (NOA)',
                'verifiedAt' => new \DateTime(),
            ]);
        }

        $verification = $this->verificationService->findByToken($token);
        if (!$verification) {
            return $this->render('document_verification/not_found.html.twig', [
                'token' => $token,
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        $summary = $verification->getSummary() ?? [];

        return $this->render('document_verification/show.html.twig', [
            'isPreviewSample' => false,
            'verification' => $verification,
            'summary' => $summary,
            'documentTypeLabel' => $verification->getDocumentType()->getLabel(),
            'verifiedAt' => new \DateTime(),
        ]);
    }
}
