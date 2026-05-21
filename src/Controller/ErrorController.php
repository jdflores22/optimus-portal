<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Custom error controller for handling error pages
 */
class ErrorController extends AbstractController
{
    public function __construct(
        private Environment $twig
    ) {
    }

    public function show(FlattenException $exception, Request $request): Response
    {
        $statusCode = $exception->getStatusCode();
        
        // Map status codes to templates
        $templates = [
            401 => 'bundles/TwigBundle/Exception/error401.html.twig',
            403 => 'bundles/TwigBundle/Exception/error403.html.twig',
            404 => 'bundles/TwigBundle/Exception/error404.html.twig',
            422 => 'bundles/TwigBundle/Exception/error422.html.twig',
            500 => 'bundles/TwigBundle/Exception/error500.html.twig',
            503 => 'bundles/TwigBundle/Exception/error503.html.twig',
        ];
        
        // Use specific template if available, otherwise use generic error template
        $template = $templates[$statusCode] ?? 'bundles/TwigBundle/Exception/error.html.twig';
        
        // Check if template exists, fallback to generic if not
        if (!$this->twig->getLoader()->exists($template)) {
            $template = 'bundles/TwigBundle/Exception/error.html.twig';
        }
        
        return $this->render($template, [
            'status_code' => $statusCode,
            'status_text' => Response::$statusTexts[$statusCode] ?? 'Error',
            'exception' => $exception,
        ]);
    }
}