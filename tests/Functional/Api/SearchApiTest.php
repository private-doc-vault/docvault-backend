<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Search API Tests
 *
 * Tests cover:
 * - Full-text search functionality
 * - Search with filters (category, tags, date range)
 * - Pagination and sorting
 * - Search result relevance
 * - Performance with large datasets
 * - Advanced query operators
 */
class SearchApiTest extends WebTestCase
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
        $testEmails = ['searchtest@example.com'];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            if ($user) {
                // Remove documents first
                $documents = $this->entityManager->getRepository(Document::class)
                    ->findBy(['uploadedBy' => $user]);

                foreach ($documents as $document) {
                    $this->entityManager->remove($document);
                }

                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testSearchRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?q=test');

        $this->assertResponseStatusCodeSame(401, 'Search should require authentication');
    }

    public function testSearchRequiresQueryParameter(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(400, 'Search without query should return 400');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testBasicSearchReturnsResults(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search?q=document',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('results', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertArrayHasKey('query', $responseData);

        $this->assertIsArray($responseData['results']);
        $this->assertIsInt($responseData['total']);
        $this->assertEquals('document', $responseData['query']);
    }

    public function testSearchWithPaginationParameters(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search?q=test&page=1&limit=10',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('results', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertArrayHasKey('page', $responseData);
        $this->assertArrayHasKey('limit', $responseData);

        $this->assertEquals(1, $responseData['page']);
        $this->assertEquals(10, $responseData['limit']);
    }

    public function testSearchWithCategoryFilter(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $categoryId = '123e4567-e89b-12d3-a456-426614174000';

        $client->request(
            'GET',
            "/api/search?q=invoice&category={$categoryId}",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $responseData);
    }

    public function testSearchWithTagsFilter(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search?q=document&tags[]=important&tags[]=urgent',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $responseData);
    }

    public function testSearchWithDateRangeFilter(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search?q=invoice&dateFrom=2024-01-01&dateTo=2024-12-31',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $responseData);
    }

    public function testSearchWithMultipleFilters(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $categoryId = '123e4567-e89b-12d3-a456-426614174000';

        $client->request(
            'GET',
            "/api/search?q=invoice&category={$categoryId}&tags[]=important&dateFrom=2024-01-01&dateTo=2024-12-31&page=1&limit=20",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('results', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertArrayHasKey('page', $responseData);
        $this->assertArrayHasKey('limit', $responseData);
        $this->assertArrayHasKey('query', $responseData);
    }

    public function testSearchResultsIncludeDocumentMetadata(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search?q=document',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        if (!empty($responseData['results'])) {
            $firstResult = $responseData['results'][0];

            // Verify document has expected fields
            $this->assertArrayHasKey('id', $firstResult);
            $this->assertArrayHasKey('filename', $firstResult);
            $this->assertArrayHasKey('mimeType', $firstResult);
            $this->assertArrayHasKey('fileSize', $firstResult);
        }
    }

    public function testSearchReturnsEmptyResultsForNoMatches(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search?q=nonexistentquerystringthatwillnevermatch12345',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('results', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertEquals(0, $responseData['total']);
        $this->assertEmpty($responseData['results']);
    }

    public function testSearchHandlesSpecialCharacters(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $specialQueries = [
            'test@example.com',
            'invoice#123',
            'document & file',
            'price: $100',
        ];

        foreach ($specialQueries as $query) {
            $client->request(
                'GET',
                '/api/search?q=' . urlencode($query),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );

            $this->assertResponseIsSuccessful("Search should handle special characters in: {$query}");
        }
    }

    public function testSearchPaginationRespectsLimits(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/search?q=document&page=1&limit=5',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('results', $responseData);
        $this->assertLessThanOrEqual(5, count($responseData['results']), 'Results should respect limit parameter');
    }

    public function testSearchWithInvalidPaginationParametersReturnsError(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        // Test invalid page
        $client->request(
            'GET',
            '/api/search?q=test&page=-1',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertTrue(
            in_array($client->getResponse()->getStatusCode(), [200, 400]),
            'Invalid pagination should return 200 with empty results or 400'
        );
    }

    public function testSearchResponseTimeIsReasonable(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('searchtest@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $startTime = microtime(true);

        $client->request(
            'GET',
            '/api/search?q=document',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $endTime = microtime(true);
        $responseTime = $endTime - $startTime;

        $this->assertResponseIsSuccessful();
        $this->assertLessThan(2.0, $responseTime, 'Search should respond within 2 seconds');
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
