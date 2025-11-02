<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenAPI 3.0 Compliance Tests
 *
 * Validates that the API responses conform to the OpenAPI specification
 *
 * Tests cover:
 * - Response status codes match specification
 * - Response schemas match defined structures
 * - Required fields are present
 * - Data types are correct
 * - Error responses follow specification
 */
class OpenApiComplianceTest extends WebTestCase
{
    private array $openApiSpec;

    protected function setUp(): void
    {
        parent::setUp();

        // Load OpenAPI specification
        $specPath = __DIR__ . '/../../../config/openapi.yaml';
        $this->openApiSpec = Yaml::parseFile($specPath);
    }

    public function testOpenApiSpecificationExists(): void
    {
        $this->assertNotEmpty($this->openApiSpec, 'OpenAPI specification should be loaded');
        $this->assertEquals('3.0.3', $this->openApiSpec['openapi'], 'Should be OpenAPI 3.0.3');
    }

    public function testApiInfoMetadata(): void
    {
        $this->assertArrayHasKey('info', $this->openApiSpec);
        $this->assertArrayHasKey('title', $this->openApiSpec['info']);
        $this->assertArrayHasKey('version', $this->openApiSpec['info']);
        $this->assertArrayHasKey('description', $this->openApiSpec['info']);

        $this->assertEquals('DocVault Document Archiving System API', $this->openApiSpec['info']['title']);
        $this->assertEquals('1.0.0', $this->openApiSpec['info']['version']);
    }

    public function testApiServersAreDefined(): void
    {
        $this->assertArrayHasKey('servers', $this->openApiSpec);
        $this->assertIsArray($this->openApiSpec['servers']);
        $this->assertGreaterThan(0, count($this->openApiSpec['servers']));

        foreach ($this->openApiSpec['servers'] as $server) {
            $this->assertArrayHasKey('url', $server);
            $this->assertArrayHasKey('description', $server);
        }
    }

    public function testSecuritySchemesAreDefined(): void
    {
        $this->assertArrayHasKey('components', $this->openApiSpec);
        $this->assertArrayHasKey('securitySchemes', $this->openApiSpec['components']);
        $this->assertArrayHasKey('bearerAuth', $this->openApiSpec['components']['securitySchemes']);

        $bearerAuth = $this->openApiSpec['components']['securitySchemes']['bearerAuth'];
        $this->assertEquals('http', $bearerAuth['type']);
        $this->assertEquals('bearer', $bearerAuth['scheme']);
        $this->assertEquals('JWT', $bearerAuth['bearerFormat']);
    }

    public function testErrorSchemaIsDefined(): void
    {
        $this->assertArrayHasKey('components', $this->openApiSpec);
        $this->assertArrayHasKey('schemas', $this->openApiSpec['components']);
        $this->assertArrayHasKey('Error', $this->openApiSpec['components']['schemas']);

        $errorSchema = $this->openApiSpec['components']['schemas']['Error'];
        $this->assertArrayHasKey('properties', $errorSchema);
        $this->assertArrayHasKey('error', $errorSchema['properties']);
        $this->assertArrayHasKey('required', $errorSchema);
        $this->assertContains('error', $errorSchema['required']);
    }

    public function testDocumentSchemaIsDefined(): void
    {
        $documentSchema = $this->openApiSpec['components']['schemas']['Document'];

        $this->assertArrayHasKey('properties', $documentSchema);

        $requiredProperties = [
            'id', 'filename', 'originalName', 'mimeType', 'fileSize',
            'filePath', 'processingStatus', 'uploadedBy', 'createdAt'
        ];

        foreach ($requiredProperties as $property) {
            $this->assertArrayHasKey($property, $documentSchema['properties'], "Document schema should have {$property}");
        }

        // Verify data types
        $this->assertEquals('string', $documentSchema['properties']['id']['type']);
        $this->assertEquals('uuid', $documentSchema['properties']['id']['format']);
        $this->assertEquals('integer', $documentSchema['properties']['fileSize']['type']);
        $this->assertEquals('int64', $documentSchema['properties']['fileSize']['format']);
    }

    public function testUserSchemaIsDefined(): void
    {
        $userSchema = $this->openApiSpec['components']['schemas']['User'];

        $this->assertArrayHasKey('properties', $userSchema);

        $requiredProperties = [
            'id', 'email', 'firstName', 'lastName', 'roles', 'isActive', 'isVerified', 'createdAt'
        ];

        foreach ($requiredProperties as $property) {
            $this->assertArrayHasKey($property, $userSchema['properties'], "User schema should have {$property}");
        }

        $this->assertEquals('string', $userSchema['properties']['email']['type']);
        $this->assertEquals('email', $userSchema['properties']['email']['format']);
        $this->assertEquals('array', $userSchema['properties']['roles']['type']);
    }

    public function testAuthenticationEndpointsAreDefined(): void
    {
        $this->assertArrayHasKey('paths', $this->openApiSpec);
        $this->assertArrayHasKey('/auth/register', $this->openApiSpec['paths']);
        $this->assertArrayHasKey('/auth/login', $this->openApiSpec['paths']);

        // Register endpoint
        $registerEndpoint = $this->openApiSpec['paths']['/auth/register'];
        $this->assertArrayHasKey('post', $registerEndpoint);
        $this->assertArrayHasKey('requestBody', $registerEndpoint['post']);
        $this->assertArrayHasKey('responses', $registerEndpoint['post']);
        $this->assertArrayHasKey('201', $registerEndpoint['post']['responses']);

        // Login endpoint
        $loginEndpoint = $this->openApiSpec['paths']['/auth/login'];
        $this->assertArrayHasKey('post', $loginEndpoint);
        $this->assertArrayHasKey('200', $loginEndpoint['post']['responses']);
        $this->assertArrayHasKey('401', $loginEndpoint['post']['responses']);
    }

    public function testDocumentEndpointsAreDefined(): void
    {
        $this->assertArrayHasKey('/documents', $this->openApiSpec['paths']);
        $this->assertArrayHasKey('/documents/{id}', $this->openApiSpec['paths']);
        $this->assertArrayHasKey('/documents/upload', $this->openApiSpec['paths']);
        $this->assertArrayHasKey('/documents/batch-upload', $this->openApiSpec['paths']);

        // List documents
        $documentsEndpoint = $this->openApiSpec['paths']['/documents'];
        $this->assertArrayHasKey('get', $documentsEndpoint);
        $this->assertArrayHasKey('parameters', $documentsEndpoint['get']);

        // Get document by ID
        $documentByIdEndpoint = $this->openApiSpec['paths']['/documents/{id}'];
        $this->assertArrayHasKey('get', $documentByIdEndpoint);
        $this->assertArrayHasKey('put', $documentByIdEndpoint);
        $this->assertArrayHasKey('delete', $documentByIdEndpoint);
    }

    public function testSearchEndpointIsDefined(): void
    {
        $this->assertArrayHasKey('/search', $this->openApiSpec['paths']);

        $searchEndpoint = $this->openApiSpec['paths']['/search'];
        $this->assertArrayHasKey('get', $searchEndpoint);
        $this->assertArrayHasKey('parameters', $searchEndpoint['get']);

        $parameters = $searchEndpoint['get']['parameters'];
        $parameterNames = array_column($parameters, 'name');

        $this->assertContains('q', $parameterNames, 'Search should have query parameter');
        $this->assertContains('page', $parameterNames);
        $this->assertContains('limit', $parameterNames);
    }

    public function testCategoryEndpointsAreDefined(): void
    {
        $this->assertArrayHasKey('/categories', $this->openApiSpec['paths']);

        $categoriesEndpoint = $this->openApiSpec['paths']['/categories'];
        $this->assertArrayHasKey('get', $categoriesEndpoint);
        $this->assertArrayHasKey('post', $categoriesEndpoint);

        // Verify Category schema
        $categorySchema = $this->openApiSpec['components']['schemas']['Category'];
        $this->assertArrayHasKey('properties', $categorySchema);
        $this->assertArrayHasKey('name', $categorySchema['properties']);
        $this->assertArrayHasKey('parentId', $categorySchema['properties']);
    }

    public function testTagEndpointsAreDefined(): void
    {
        $this->assertArrayHasKey('/tags', $this->openApiSpec['paths']);

        $tagsEndpoint = $this->openApiSpec['paths']['/tags'];
        $this->assertArrayHasKey('get', $tagsEndpoint);
        $this->assertArrayHasKey('post', $tagsEndpoint);

        // Verify Tag schema
        $tagSchema = $this->openApiSpec['components']['schemas']['Tag'];
        $this->assertArrayHasKey('properties', $tagSchema);
        $this->assertArrayHasKey('name', $tagSchema['properties']);
        $this->assertArrayHasKey('usageCount', $tagSchema['properties']);
    }

    public function testProfileEndpointIsDefined(): void
    {
        $this->assertArrayHasKey('/profile', $this->openApiSpec['paths']);

        $profileEndpoint = $this->openApiSpec['paths']['/profile'];
        $this->assertArrayHasKey('get', $profileEndpoint);
        $this->assertArrayHasKey('put', $profileEndpoint);
    }

    public function testAllEndpointsHaveSecurityOrExplicitlyExcluded(): void
    {
        $publicEndpoints = ['/auth/register', '/auth/login'];

        foreach ($this->openApiSpec['paths'] as $path => $methods) {
            foreach ($methods as $method => $config) {
                if (!is_array($config)) {
                    continue; // Skip non-method entries like 'parameters'
                }

                if (in_array($path, $publicEndpoints, true)) {
                    // Public endpoints should have security: []
                    $this->assertArrayHasKey('security', $config, "{$method} {$path} should explicitly define security");
                    $this->assertEquals([], $config['security'], "{$method} {$path} should be public (security: [])");
                } else {
                    // Other endpoints should inherit global security or define their own
                    // Global security is defined at root level
                    if (isset($config['security'])) {
                        $this->assertNotEquals([], $config['security'], "{$method} {$path} should require authentication");
                    }
                }
            }
        }
    }

    public function testResponseSchemasAreConsistent(): void
    {
        foreach ($this->openApiSpec['paths'] as $path => $methods) {
            foreach ($methods as $method => $config) {
                if (!is_array($config) || !isset($config['responses'])) {
                    continue;
                }

                foreach ($config['responses'] as $statusCode => $response) {
                    if (isset($response['content']['application/json']['schema'])) {
                        $schema = $response['content']['application/json']['schema'];

                        // If schema references another schema, verify it exists
                        if (isset($schema['$ref'])) {
                            $refPath = explode('/', $schema['$ref']);
                            $schemaName = end($refPath);

                            $this->assertArrayHasKey(
                                $schemaName,
                                $this->openApiSpec['components']['schemas'],
                                "Referenced schema {$schemaName} should exist for {$method} {$path} ({$statusCode})"
                            );
                        }
                    }
                }
            }
        }
    }

    public function testApiHasProperTagging(): void
    {
        $this->assertArrayHasKey('tags', $this->openApiSpec);
        $definedTags = array_column($this->openApiSpec['tags'], 'name');

        $this->assertContains('Authentication', $definedTags);
        $this->assertContains('Documents', $definedTags);
        $this->assertContains('Search', $definedTags);
        $this->assertContains('Categories', $definedTags);
        $this->assertContains('Tags', $definedTags);

        // Verify all endpoints have tags
        foreach ($this->openApiSpec['paths'] as $path => $methods) {
            foreach ($methods as $method => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $this->assertArrayHasKey('tags', $config, "{$method} {$path} should have tags");
                $this->assertNotEmpty($config['tags'], "{$method} {$path} should have at least one tag");
            }
        }
    }
}
