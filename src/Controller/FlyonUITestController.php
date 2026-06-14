<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
/**
 * Controller for testing FlyonUI interactive components.
 * Available in dev for any authenticated user; production requires system admin.
 */
class FlyonUITestController extends AbstractController
{
    public function __construct(private KernelInterface $kernel)
    {
    }

    #[Route('/test/flyonui-components', name: 'test_flyonui_components')]
    public function testComponents(): Response
    {
        $this->assertDevOrSystemAdmin();

        return $this->render('test/flyonui_components_test.html.twig');
    }

    private function assertDevOrSystemAdmin(): void
    {
        if ($this->kernel->getEnvironment() === 'dev') {
            return;
        }

        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
    }
}
