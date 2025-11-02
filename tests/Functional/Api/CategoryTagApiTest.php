<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Category;
use App\Entity\DocumentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Category and Tag Management API Tests
 *
 * Tests cover:
 * - Category CRUD operations
 * - Hierarchical category structure
 * - Tag CRUD operations
 * - Tag usage tracking
 * - Permission checks
 */
class CategoryTagApiTest extends WebTestCase
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
            // Find all users with emails starting with 'categorytest'
            $users = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.email LIKE :pattern')
                ->setParameter('pattern', 'categorytest%')
                ->getQuery()
                ->getResult();

            foreach ($users as $user) {
                $this->entityManager->remove($user);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        // Clean up test categories
        $testCategories = $this->entityManager->getRepository(Category::class)
            ->createQueryBuilder('c')
            ->where('c.name LIKE :prefix')
            ->setParameter('prefix', 'Test Category%')
            ->getQuery()
            ->getResult();

        foreach ($testCategories as $category) {
            $this->entityManager->remove($category);
        }

        // Clean up test tags
        $testTags = $this->entityManager->getRepository(DocumentTag::class)
            ->createQueryBuilder('t')
            ->where('t.name LIKE :prefix')
            ->setParameter('prefix', 'test-tag%')
            ->getQuery()
            ->getResult();

        foreach ($testTags as $tag) {
            $this->entityManager->remove($tag);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    // ========== Category Tests ==========

    public function testListCategoriesRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/categories');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListCategoriesReturnsArray(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/categories',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
    }

    public function testCreateCategoryRequiresAdminRole(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        // User without admin role
        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'POST',
            '/api/categories',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'name' => 'Test Category',
                'description' => 'Test Description'
            ])
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateCategoryWithValidData(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $categoryName = 'Test Category ' . uniqid();

        $client->request(
            'POST',
            '/api/categories',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'name' => $categoryName,
                'description' => 'Test Description'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('name', $responseData);
        $this->assertEquals($categoryName, $responseData['name']);
        $this->assertEquals('Test Description', $responseData['description']);
    }

    public function testCreateCategoryWithoutNameReturnsError(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'POST',
            '/api/categories',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'description' => 'Test Description'
            ])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateHierarchicalCategory(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        // Create parent category
        $parentName = 'Test Category Parent ' . uniqid();
        $client->request(
            'POST',
            '/api/categories',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['name' => $parentName])
        );

        $parentData = json_decode($client->getResponse()->getContent(), true);
        $parentId = $parentData['id'];

        // Create child category
        $childName = 'Test Category Child ' . uniqid();
        $client->request(
            'POST',
            '/api/categories',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'name' => $childName,
                'parentId' => $parentId
            ])
        );

        $this->assertResponseStatusCodeSame(201);

        $childData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($parentId, $childData['parentId']);
    }

    // ========== Tag Tests ==========

    public function testListTagsRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/tags');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListTagsReturnsArray(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/tags',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
    }

    public function testCreateTagWithValidData(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $tagName = 'test-tag-' . uniqid();

        $client->request(
            'POST',
            '/api/tags',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'name' => $tagName,
                'color' => '#FF5733'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('name', $responseData);
        $this->assertEquals($tagName, $responseData['name']);
    }

    public function testCreateTagWithoutNameReturnsError(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'POST',
            '/api/tags',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'color' => '#FF5733'
            ])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testTagIncludesUsageCount(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('categorytest' . self::$testCounter . '@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/tags',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        if (!empty($responseData)) {
            $firstTag = $responseData[0];
            $this->assertArrayHasKey('usageCount', $firstTag);
            $this->assertIsInt($firstTag['usageCount']);
        }
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
