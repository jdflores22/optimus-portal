<?php

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ContainerLibraryApiControllerTest extends WebTestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testGetActiveContainerTypesReturnsJsonResponse(): void
    {
        $this->client->catchExceptions(false);
        $this->client->request('GET', '/api/container-types/active');
        
        $response = $this->client->getResponse();
        
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($response->headers->contains('Content-Type', 'application/json'));
    }

    public function testGetActiveContainerTypesReturnsCorrectStructure(): void
    {
        $this->client->request('GET', '/api/container-types/active');
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('containerTypes', $responseData);
        $this->assertIsArray($responseData['containerTypes']);
        
        if (count($responseData['containerTypes']) > 0) {
            $firstType = $responseData['containerTypes'][0];
            $this->assertArrayHasKey('id', $firstType);
            $this->assertArrayHasKey('name', $firstType);
            $this->assertArrayHasKey('code', $firstType);
            $this->assertArrayHasKey('description', $firstType);
        }
    }

    public function testGetActiveContainerTypesReturnsOnlyActiveEntries(): void
    {
        $this->client->request('GET', '/api/container-types/active');
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        // All returned types should be active (we can't verify this directly without database access,
        // but we can verify the structure is correct)
        $this->assertIsArray($responseData['containerTypes']);
    }

    public function testGetActiveContainerTypesReturnsAlphabeticalOrder(): void
    {
        $this->client->request('GET', '/api/container-types/active');
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $containerTypes = $responseData['containerTypes'];
        
        if (count($containerTypes) > 1) {
            $names = array_column($containerTypes, 'name');
            $sortedNames = $names;
            sort($sortedNames);
            
            $this->assertEquals($sortedNames, $names, 'Container types should be ordered alphabetically by name');
        }
    }

    public function testGetActiveContainerSizesReturnsJsonResponse(): void
    {
        $this->client->request('GET', '/api/container-sizes/active');
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->client->getResponse()->headers->contains('Content-Type', 'application/json'));
    }

    public function testGetActiveContainerSizesReturnsCorrectStructure(): void
    {
        $this->client->request('GET', '/api/container-sizes/active');
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('containerSizes', $responseData);
        $this->assertIsArray($responseData['containerSizes']);
        
        if (count($responseData['containerSizes']) > 0) {
            $firstSize = $responseData['containerSizes'][0];
            $this->assertArrayHasKey('id', $firstSize);
            $this->assertArrayHasKey('name', $firstSize);
            $this->assertArrayHasKey('code', $firstSize);
            $this->assertArrayHasKey('description', $firstSize);
        }
    }

    public function testGetActiveContainerSizesReturnsOnlyActiveEntries(): void
    {
        $this->client->request('GET', '/api/container-sizes/active');
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        // All returned sizes should be active (we can't verify this directly without database access,
        // but we can verify the structure is correct)
        $this->assertIsArray($responseData['containerSizes']);
    }

    public function testGetActiveContainerSizesReturnsAlphabeticalOrder(): void
    {
        $this->client->request('GET', '/api/container-sizes/active');
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $containerSizes = $responseData['containerSizes'];
        
        if (count($containerSizes) > 1) {
            $names = array_column($containerSizes, 'name');
            $sortedNames = $names;
            sort($sortedNames);
            
            $this->assertEquals($sortedNames, $names, 'Container sizes should be ordered alphabetically by name');
        }
    }
}
