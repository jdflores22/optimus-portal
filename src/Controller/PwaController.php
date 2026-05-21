<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PwaController extends AbstractController
{
    #[Route('/.well-known/assetlinks.json', name: 'pwa_assetlinks', methods: ['GET'])]
    public function assetlinks(): JsonResponse
    {
        $assetlinks = [
            [
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'web',
                    'site' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']
                ]
            ]
        ];
        
        return new JsonResponse($assetlinks);
    }
    
    #[Route('/manifest.json', name: 'pwa_manifest', methods: ['GET'])]
    public function manifest(): Response
    {
        $manifestPath = $this->getParameter('kernel.project_dir') . '/public/manifest.json';
        
        if (!file_exists($manifestPath)) {
            throw $this->createNotFoundException('Manifest file not found');
        }
        
        $manifest = file_get_contents($manifestPath);
        
        return new Response(
            $manifest,
            Response::HTTP_OK,
            ['Content-Type' => 'application/manifest+json']
        );
    }
    
    #[Route('/pwa/debug', name: 'pwa_debug', methods: ['GET'])]
    public function debug(): Response
    {
        return $this->render('pwa/debug.html.twig');
    }
}

