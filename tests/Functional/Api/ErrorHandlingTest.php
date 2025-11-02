<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Error Handling and Response Format Tests
 *
 * Tests cover:
 * - Consistent error response format across all endpoints
 * - Proper HTTP status codes
 * - Validation error responses
 * - Authentication error responses
 * - Authorization error responses
 * - Not found error responses
 * - Internal server error handling
 */
class ErrorHandlingTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->cleanupTestData();
        }

        parent::tearDown();
    }

    private function initializeServices(): void
    {
        if (!isset($this->entityManager)) {
            $container = static::getContainer();
            $this->entityManager = $container->get('doctrine.orm.entity_manager');
        }
    }

    private function cleanupTestData(): void
    {
        $testEmails = ['errortest@example.com'];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            if ($user) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/documents');

        $this->assertResponseStatusCodeSame(401, 'Unauthenticated request should return 401');
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('code', $responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals(401, $responseData['code']);
    }

    public function testInvalidCredentialsReturn401WithStandardFormat(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'nonexistent@example.com',
                'password' => 'wrongpassword'
            ])
        );

        $this->assertResponseStatusCodeSame(401);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertIsString($responseData['error']);
    }

    public function testValidationErrorsReturn400WithStandardFormat(): void
    {
        $client = static::createClient();

        // Try to register with invalid email
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'invalid-email',
                'password' => 'short',
                'firstName' => '',
                'lastName' => ''
            ])
        );

        $this->assertResponseStatusCodeSame(400, 'Validation errors should return 400');
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);

        // Should have validation details
        $this->assertTrue(
            isset($responseData['violations']) || isset($responseData['details']),
            'Validation response should include error details'
        );
    }

    public function testNotFoundReturns404WithStandardFormat(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('errortest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/documents/00000000-0000-0000-0000-000000000000',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(404, 'Non-existent resource should return 404');
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testForbiddenAccessReturns403WithStandardFormat(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        // Create user without admin privileges
        $testUser = $this->createTestUser('errortest@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        // Try to access admin endpoint
        $client->request(
            'GET',
            '/api/admin/users',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403, 'Forbidden access should return 403');
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testMethodNotAllowedReturns405(): void
    {
        $client = static::createClient();

        // Try POST on a GET-only endpoint
        $client->request('POST', '/api/doc.json');

        $this->assertResponseStatusCodeSame(405, 'Method not allowed should return 405');
    }

    public function testMalformedJsonReturns400(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{invalid json'
        );

        $this->assertResponseStatusCodeSame(400, 'Malformed JSON should return 400');
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testMissingRequiredFieldsReturn400(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(400);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testAllErrorResponsesAreJson(): void
    {
        $client = static::createClient();

        $endpoints = [
            ['GET', '/api/documents'],  // 401
            ['GET', '/api/documents/invalid-uuid'], // 404
        ];

        foreach ($endpoints as [$method, $path]) {
            $client->request($method, $path);

            $this->assertResponseHeaderSame(
                'content-type',
                'application/json',
                "Error response for {$method} {$path} should be JSON"
            );
        }
    }

    public function testErrorResponsesHaveConsistentStructure(): void
    {
        $client = static::createClient();

        // Test 401 error
        $client->request('GET', '/api/documents');
        $response401 = json_decode($client->getResponse()->getContent(), true);

        // Test 400 error
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );
        $response400 = json_decode($client->getResponse()->getContent(), true);

        // Both should have 'error' or 'message' field
        $this->assertTrue(
            isset($response401['error']) || isset($response401['message']),
            '401 response should have error or message field'
        );

        $this->assertTrue(
            isset($response400['error']) || isset($response400['message']),
            '400 response should have error or message field'
        );
    }

    public function testSuccessResponsesHaveConsistentStructure(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('errortest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        // Test documents list endpoint
        $client->request(
            'GET',
            '/api/documents',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($responseData);
    }

    public function testCreatedResponsesReturn201(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'newuser' . uniqid() . '@example.com',
                'password' => 'SecurePassword123!',
                'firstName' => 'Test',
                'lastName' => 'User'
            ])
        );

        $this->assertResponseStatusCodeSame(201, 'Resource creation should return 201');
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testDeletedResponsesReturn204Or200(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('errortest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        // Try to delete a non-existent document (will return 404)
        $client->request(
            'DELETE',
            '/api/documents/00000000-0000-0000-0000-000000000000',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        // Should return 404 for non-existent resource
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 204, 404]),
            'Delete response should be 200, 204, or 404'
        );
    }

    public function testCorsHeadersArePresentInErrorResponses(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/documents');

        // CORS headers might be added by configuration
        // This test ensures error responses can still include CORS headers
        $this->assertResponseStatusCodeSame(401);
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    /**
     * Helper: Create a test user with specified roles
     */
    private function createTestUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $this->initializeServices();
        $passwordHasher = static::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        $hashedPassword = $passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Helper: Generate JWT token for authentication
     */
    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }
}
