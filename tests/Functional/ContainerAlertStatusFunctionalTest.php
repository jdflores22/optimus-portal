<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ContainerAlertStatusFunctionalTest extends WebTestCase
{
    public function testUnauthorizedAccess(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false); // This will show the actual exception

        // Make API request without authorization
        $client->request(
            'POST',
            '/api/containers/TEST123/alert/pause',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['reason' => 'Test'])
        );

        $response = $client->getResponse();
        
        // Debug: print response content if not 401
        if ($response->getStatusCode() !== 401) {
            echo "Response status: " . $response->getStatusCode() . "\n";
            echo "Response content: " . $response->getContent() . "\n";
        }
        
        $this->assertEquals(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication required', $data['error']);
    }
}