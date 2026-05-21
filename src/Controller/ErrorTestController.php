<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for testing custom error pages in development
 */
#[Route('/test-errors')]
class ErrorTestController extends AbstractController
{
    #[Route('/404', name: 'test_error_404')]
    public function test404(): Response
    {
        throw $this->createNotFoundException('This is a test 404 error');
    }

    #[Route('/403', name: 'test_error_403')]
    public function test403(): Response
    {
        throw $this->createAccessDeniedException('This is a test 403 error');
    }

    #[Route('/401', name: 'test_error_401')]
    public function test401(): Response
    {
        return $this->render('bundles/TwigBundle/Exception/error401.html.twig', [
            'status_code' => 401,
            'status_text' => 'Unauthorized'
        ], new Response('', 401));
    }

    #[Route('/500', name: 'test_error_500')]
    public function test500(): Response
    {
        throw new \Exception('This is a test 500 error');
    }

    #[Route('/503', name: 'test_error_503')]
    public function test503(): Response
    {
        return $this->render('bundles/TwigBundle/Exception/error503.html.twig', [
            'status_code' => 503,
            'status_text' => 'Service Unavailable'
        ], new Response('', 503));
    }

    #[Route('/422', name: 'test_error_422')]
    public function test422(): Response
    {
        return $this->render('bundles/TwigBundle/Exception/error422.html.twig', [
            'status_code' => 422,
            'status_text' => 'Unprocessable Entity'
        ], new Response('', 422));
    }

    #[Route('/generic', name: 'test_error_generic')]
    public function testGeneric(): Response
    {
        return $this->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => 418,
            'status_text' => 'I\'m a teapot'
        ], new Response('', 418));
    }

    #[Route('', name: 'test_errors_index')]
    public function index(): Response
    {
        return $this->render('error_test/index.html.twig');
    }
}