<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for testing custom error pages in development.
 * Available in dev for any authenticated user; production requires system admin.
 */
#[Route('/test-errors')]
class ErrorTestController extends AbstractController
{
    public function __construct(private KernelInterface $kernel)
    {
    }

    #[Route('/404', name: 'test_error_404')]
    public function test404(): Response
    {
        $this->assertDevOrSystemAdmin();
        throw $this->createNotFoundException('This is a test 404 error');
    }

    #[Route('/403', name: 'test_error_403')]
    public function test403(): Response
    {
        $this->assertDevOrSystemAdmin();
        throw $this->createAccessDeniedException('This is a test 403 error');
    }

    #[Route('/401', name: 'test_error_401')]
    public function test401(): Response
    {
        $this->assertDevOrSystemAdmin();

        return $this->render('bundles/TwigBundle/Exception/error401.html.twig', [
            'status_code' => 401,
            'status_text' => 'Unauthorized',
        ], new Response('', 401));
    }

    #[Route('/500', name: 'test_error_500')]
    public function test500(): Response
    {
        $this->assertDevOrSystemAdmin();
        throw new \Exception('This is a test 500 error');
    }

    #[Route('/503', name: 'test_error_503')]
    public function test503(): Response
    {
        $this->assertDevOrSystemAdmin();

        return $this->render('bundles/TwigBundle/Exception/error503.html.twig', [
            'status_code' => 503,
            'status_text' => 'Service Unavailable',
        ], new Response('', 503));
    }

    #[Route('/422', name: 'test_error_422')]
    public function test422(): Response
    {
        $this->assertDevOrSystemAdmin();

        return $this->render('bundles/TwigBundle/Exception/error422.html.twig', [
            'status_code' => 422,
            'status_text' => 'Unprocessable Entity',
        ], new Response('', 422));
    }

    #[Route('/generic', name: 'test_error_generic')]
    public function testGeneric(): Response
    {
        $this->assertDevOrSystemAdmin();

        return $this->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => 418,
            'status_text' => 'I\'m a teapot',
        ], new Response('', 418));
    }

    #[Route('', name: 'test_errors_index')]
    public function index(): Response
    {
        $this->assertDevOrSystemAdmin();

        return $this->render('error_test/index.html.twig');
    }

    private function assertDevOrSystemAdmin(): void
    {
        if ($this->kernel->getEnvironment() === 'dev') {
            return;
        }

        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
    }
}
