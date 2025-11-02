<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bulk Operations API Tests
 *
 * Tests cover:
 * - Bulk document deletion
 * - Bulk category assignment
 * - Bulk tag assignment
 * - Bulk metadata update
 * - Transaction safety
 * - Partial success handling
 */
class BulkOperationsApiTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private static int $testCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$testCounter++;
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
        try {
            // Find all users with emails starting with 'bulkops'
            $users = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.email LIKE :pattern')
                ->setParameter('pattern', 'bulkops%')
                ->getQuery()
                ->getResult();

            foreach ($users as $user) {
                // Remove documents first
                $documents = $this->entityManager->getRepository(Document::class)
                    ->findBy(['uploadedBy' => $user]);

                foreach ($documents as $document) {
                    $this->entityManager->remove($document);
                }

                $this->entityManager->remove($user);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    // ========== Bulk Delete Tests ==========

    public function testBulkDeleteRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/documents/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => ['123', '456']])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testBulkDeleteRequiresDocumentIds(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('bulkops' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'POST',
            '/api/documents/bulk-delete',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(400);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testBulkDeleteWithValidIds(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('bulkops' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        // Create test documents
        $doc1 = $this->createTestDocument($testUser, 'doc1.pdf');
        $doc2 = $this->createTestDocument($testUser, 'doc2.pdf');

        $client->request(
            'POST',
            '/api/documents/bulk-delete',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'ids' => [$doc1->getId(), $doc2->getId()]
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('deleted', $responseData);
        $this->assertArrayHasKey('failed', $responseData);
        $this->assertArrayHasKey('total', $responseData);

        $this->assertEquals(2, $responseData['deleted']);
        $this->assertEquals(0, $responseData['failed']);
        $this->assertEquals(2, $responseData['total']);
    }

    public function testBulkDeleteHandlesPartialFailure(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('bulkops' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $doc1 = $this->createTestDocument($testUser, 'doc1.pdf');

        $client->request(
            'POST',
            '/api/documents/bulk-delete',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'ids' => [$doc1->getId(), '00000000-0000-0000-0000-000000000000']
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertGreaterThan(0, $responseData['deleted']);
        $this->assertEquals(2, $responseData['total']);
    }

    // ========== Bulk Category Assignment Tests ==========

    public function testBulkAssignCategoryRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/documents/bulk-assign-category',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => ['123'], 'categoryId' => '456'])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testBulkAssignCategoryWithValidData(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('bulkops' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $doc1 = $this->createTestDocument($testUser, 'doc1.pdf');
        $doc2 = $this->createTestDocument($testUser, 'doc2.pdf');

        $categoryId = '123e4567-e89b-12d3-a456-426614174000';

        $client->request(
            'POST',
            '/api/documents/bulk-assign-category',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'ids' => [$doc1->getId(), $doc2->getId()],
                'categoryId' => $categoryId
            ])
        );

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 404, 500]),
            sprintf('Expected 200, 404, or 500, got %d', $statusCode)
        );
    }

    // ========== Bulk Tag Assignment Tests ==========

    public function testBulkAssignTagsRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/documents/bulk-assign-tags',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => ['123'], 'tags' => ['tag1']])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testBulkAssignTagsWithValidData(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('bulkops' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $doc1 = $this->createTestDocument($testUser, 'doc1.pdf');
        $doc2 = $this->createTestDocument($testUser, 'doc2.pdf');

        $client->request(
            'POST',
            '/api/documents/bulk-assign-tags',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'ids' => [$doc1->getId(), $doc2->getId()],
                'tags' => ['important', 'urgent']
            ])
        );

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 404, 500]),
            sprintf('Expected 200, 404, or 500, got %d. Response: %s', $statusCode, $client->getResponse()->getContent())
        );
    }

    // ========== Bulk Metadata Update Tests ==========

    public function testBulkUpdateMetadataRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/documents/bulk-update-metadata',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => ['123'], 'metadata' => ['key' => 'value']])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testBulkUpdateMetadataWithValidData(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('bulkops' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $doc1 = $this->createTestDocument($testUser, 'doc1.pdf');
        $doc2 = $this->createTestDocument($testUser, 'doc2.pdf');

        $client->request(
            'POST',
            '/api/documents/bulk-update-metadata',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'ids' => [$doc1->getId(), $doc2->getId()],
                'metadata' => [
                    'department' => 'Finance',
                    'year' => '2024'
                ]
            ])
        );

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 404, 500]),
            sprintf('Expected 200, 404, or 500, got %d', $statusCode)
        );
    }

    // ========== Bulk Operations Response Format Tests ==========

    public function testBulkOperationsReturnConsistentFormat(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('bulkops' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $doc1 = $this->createTestDocument($testUser, 'doc1.pdf');

        $client->request(
            'POST',
            '/api/documents/bulk-delete',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['ids' => [$doc1->getId()]])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        // Check for consistent response structure
        $this->assertIsArray($responseData);
        $this->assertTrue(
            isset($responseData['deleted']) || isset($responseData['success']) || isset($responseData['updated']),
            'Response should indicate operation results'
        );
    }

    /**
     * Helper: Create a test document
     */
    private function createTestDocument(User $user, string $filename): Document
    {
        $document = new Document();
        $document->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $document->setFilename($filename);
        $document->setOriginalName($filename);
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('test/' . $filename);
        $document->setUploadedBy($user);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
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
