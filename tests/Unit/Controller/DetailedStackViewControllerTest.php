<?php

namespace App\Tests\Unit\Controller;

use App\Controller\DetailedStackViewController;
use PHPUnit\Framework\TestCase;

class DetailedStackViewControllerTest extends TestCase
{

    /**
     * Test that the controller has the correct route annotation
     */
    public function testControllerHasCorrectRouteAnnotation(): void
    {
        $reflection = new \ReflectionClass(DetailedStackViewController::class);
        $method = $reflection->getMethod('detailedStackView');
        $attributes = $method->getAttributes();
        
        $routeFound = false;
        $securityFound = false;
        
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === 'Symfony\Component\Routing\Attribute\Route') {
                $routeFound = true;
                $arguments = $attribute->getArguments();
                $this->assertEquals('/shipping-admin/depot/{depotId}/detailed-stack', $arguments[0]);
                $this->assertEquals('app_detailed_stack_view', $arguments['name']);
            }
            
            if ($attribute->getName() === 'Symfony\Component\Security\Http\Attribute\IsGranted') {
                $securityFound = true;
                $arguments = $attribute->getArguments();
                $this->assertEquals('ROLE_SHIPPING_LINES_ADMIN', $arguments[0]);
            }
        }
        
        $this->assertTrue($routeFound, 'Route attribute not found');
        $this->assertTrue($securityFound, 'Security attribute not found');
    }

    /**
     * Test that sample data structure is consistent and meets requirements
     */
    public function testSampleDataStructure(): void
    {
        // Test the sample data structure by examining the controller code
        $reflection = new \ReflectionClass(DetailedStackViewController::class);
        $method = $reflection->getMethod('detailedStackView');
        
        // Verify method exists and has correct signature
        $this->assertTrue($method->isPublic());
        $this->assertEquals('detailedStackView', $method->getName());
        
        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('depotId', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());
    }

    /**
     * Test sample container data requirements by examining the source code
     */
    public function testSampleContainerDataRequirements(): void
    {
        // Read the controller source to verify it uses the service properly
        $controllerSource = file_get_contents(__DIR__ . '/../../../src/Controller/DetailedStackViewController.php');
        
        // Verify we use the container data service
        $this->assertStringContainsString('$sampleContainers = $this->containerDataService->getSampleContainerData', $controllerSource);
        
        // Verify depot name retrieval from service
        $this->assertStringContainsString('$depotFullName = $this->containerDataService->getDepotFullName', $controllerSource);
        
        // Verify TEU calculation uses service
        $this->assertStringContainsString('$totalTEU = $this->containerDataService->calculateTotalTEU', $controllerSource);
        
        // Verify service injection in constructor
        $this->assertStringContainsString('ContainerDataService $containerDataService', $controllerSource);
        
        // Verify template rendering with correct variables
        $this->assertStringContainsString("'containers' => \$sampleContainers", $controllerSource);
        $this->assertStringContainsString("'totalTEU' => \$totalTEU", $controllerSource);
        $this->assertStringContainsString("'depotName' => \$depotFullName", $controllerSource);
    }
}