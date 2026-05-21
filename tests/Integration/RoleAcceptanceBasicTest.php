<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RoleAcceptanceBasicTest extends WebTestCase
{
    public function testInvalidTokenShowsErrorPage(): void
    {
        $client = static::createClient();
        
        // Access the role acceptance page with invalid token
        $client->request('GET', '/role-acceptance/invalid_token_123');

        // Verify the page loads successfully but shows error
        $this->assertResponseIsSuccessful();
        
        // Verify error content is displayed
        $this->assertSelectorTextContains('h2', 'Invalid Link');
    }

    public function testRoleAcceptanceRouteExists(): void
    {
        $client = static::createClient();
        
        // Test that the route exists (even with invalid token)
        $client->request('GET', '/role-acceptance/test_token');
        
        // Should not return 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }
}