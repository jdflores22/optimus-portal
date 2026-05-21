<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for testing FlyonUI interactive components
 * This is a temporary test controller for validating FlyonUI JavaScript integration
 */
class FlyonUITestController extends AbstractController
{
    #[Route('/test/flyonui-components', name: 'test_flyonui_components')]
    public function testComponents(): Response
    {
        return $this->render('test/flyonui_components_test.html.twig');
    }
}
