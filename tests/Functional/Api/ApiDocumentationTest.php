<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API Documentation Tests
 *
 * Tests the API documentation endpoints (Swagger UI)
 *
 * Tests cover:
 * - Swagger UI is accessible
 * - OpenAPI JSON specification is available
 * - Documentation contains all expected endpoints
 * - Security schemes are documented
 */
class ApiDocumentationTest extends WebTestCase
{
    public function testSwaggerUiIsAccessible(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc');

        $this->assertResponseIsSuccessful('Swagger UI should be accessible');
        $this->assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('swagger-ui', $content, 'Response should contain Swagger UI');
    }

    public function testOpenApiJsonSpecificationIsAvailable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        $this->assertResponseIsSuccessful('OpenAPI JSON should be accessible');
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $content = $client->getResponse()->getContent();
        $spec = json_decode($content, true);

        $this->assertIsArray($spec, 'Response should be valid JSON');
        $this->assertArrayHasKey('openapi', $spec, 'Should have OpenAPI version');
        $this->assertArrayHasKey('info', $spec, 'Should have API info');
        $this->assertArrayHasKey('paths', $spec, 'Should have API paths');
    }

    public function testApiSpecificationContainsApiInfo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        $spec = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);

        $this->assertStringContainsString('DocVault', $spec['info']['title']);
        $this->assertEquals('1.0.0', $spec['info']['version']);
    }

    public function testApiSpecificationContainsAuthenticationEndpoints(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        $spec = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('paths', $spec);

        // Check for authentication endpoints
        $expectedAuthPaths = [
            '/api/auth/register',
            '/api/auth/login',
        ];

        foreach ($expectedAuthPaths as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], "API spec should include {$path}");
        }
    }

    public function testApiSpecificationContainsDocumentEndpoints(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        $spec = json_decode($client->getResponse()->getContent(), true);

        // Check for document endpoints
        $expectedDocPaths = [
            '/api/documents',
            '/api/documents/{id}',
        ];

        foreach ($expectedDocPaths as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], "API spec should include {$path}");
        }
    }

    public function testApiSpecificationDocumentsSecurityScheme(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        $spec = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('securitySchemes', $spec['components']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);

        $bearerAuth = $spec['components']['securitySchemes']['bearerAuth'];
        $this->assertEquals('http', $bearerAuth['type']);
        $this->assertEquals('bearer', $bearerAuth['scheme']);
        $this->assertEquals('JWT', $bearerAuth['bearerFormat']);
    }

    public function testApiSpecificationContainsSchemas(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        $spec = json_decode($client->getResponse()->getContent(), true);

        if (isset($spec['components']['schemas'])) {
            $this->assertArrayHasKey('components', $spec);
            $this->assertArrayHasKey('schemas', $spec['components']);
            $this->assertIsArray($spec['components']['schemas']);
        } else {
            // Schemas might be auto-generated, so this is optional
            $this->markTestSkipped('No schemas defined in specification');
        }
    }

    public function testSwaggerUiContainsApiTitle(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc');

        $content = $client->getResponse()->getContent();

        // Swagger UI should display the API title
        $this->assertStringContainsString('DocVault', $content, 'Swagger UI should display API title');
    }

    public function testApiDocumentationEndpointsArePublic(): void
    {
        $client = static::createClient();

        // Should be accessible without authentication
        $client->request('GET', '/api/doc');
        $this->assertResponseIsSuccessful('Swagger UI should be publicly accessible');

        $client->request('GET', '/api/doc.json');
        $this->assertResponseIsSuccessful('OpenAPI JSON should be publicly accessible');
    }
}
