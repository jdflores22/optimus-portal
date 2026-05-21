<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RoleAcceptanceSimpleTest extends WebTestCase
{
    public function testRoleAcceptanceControllerResponds(): void
    {
        $client = static::createClient();
        
        // Test that the controller responds (even with invalid token)
        $client->request('GET', '/role-acceptance/test_token_123');
        
        // Should not return 404 or 500
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(404, $statusCode, 'Route should exist');
        $this->assertNotEquals(500, $statusCode, 'Controller should not throw errors');
        
        // Should return 200 (success page with error message)
        $this->assertEquals(200, $statusCode);
    }
}