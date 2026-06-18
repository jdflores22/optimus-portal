<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redirects legacy /manifest/create URLs to the current NOA/BL workflow.
 */
#[Route('/manifest')]
class LegacyManifestController extends AbstractController
{
    #[Route('/create', name: 'legacy_manifest_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->json([
                'success' => false,
                'error' => 'This endpoint is deprecated. Brokers should upload BL from Manifest Details (/broker/manifests).',
            ], Response::HTTP_GONE);
        }

        if ($this->isGranted('ROLE_BROKER')) {
            $this->addFlash('info', 'BL upload is now done from each manifest’s detail page.');

            return $this->redirectToRoute('broker_manifest_list');
        }

        if ($this->isGranted('ROLE_SL_STAFF')) {
            return $this->redirectToRoute('manifest_workflow_list');
        }

        throw $this->createAccessDeniedException();
    }
}
