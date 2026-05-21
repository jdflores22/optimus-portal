<?php

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(__DIR__.'/.env');

$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$container = $kernel->getContainer();

try {
    $controller = $container->get('App\Controller\ManifestWorkflowController');
    echo "Controller loaded successfully!\n";
    
    // Check if method exists
    if (method_exists($controller, 'bulkImportManifests')) {
        echo "Method bulkImportManifests exists!\n";
    } else {
        echo "Method bulkImportManifests NOT found!\n";
    }
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
