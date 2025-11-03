<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\SavedSearch;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for Saved Search API endpoints
 *
 * Tests all CRUD operations, access control, and search execution
 */
class SavedSearchApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $testUser;
    private User $otherUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Clean up any existing test users first
        $this->cleanupTestUsers();

        // Create test users
        $this->testUser = $this->createUser('testuser@example.com', 'Test User');
        $this->otherUser = $this->createUser('otheruser@example.com', 'Other User');
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->cleanupTestUsers();
        }
        parent::tearDown();
    }

    private function cleanupTestUsers(): void
    {
        $testEmails = ['testuser@example.com', 'otheruser@example.com'];
        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $this->entityManager->remove($user);
            }
        }
        $this->entityManager->flush();
    }

    private function createUser(string $email, string $name): User
    {
        $user = new User();
        $user->setId(Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setFirstName($name);
        $user->setPassword('$2y$13$test'); // Dummy hash
        $user->setRoles(['ROLE_USER']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function authenticateAs(User $user): void
    {
        $this->client->loginUser($user);
    }

    private function createSavedSearch(
        User $user,
        string $name,
        string $query,
        array $filters = [],
        bool $isPublic = false
    ): SavedSearch {
        $savedSearch = new SavedSearch();
        $savedSearch->setId(Uuid::uuid4()->toString());
        $savedSearch->setName($name);
        $savedSearch->setQuery($query);
        $savedSearch->setFilters($filters);
        $savedSearch->setIsPublic($isPublic);
        $savedSearch->setUser($user);

        $this->entityManager->persist($savedSearch);
        $this->entityManager->flush();

        return $savedSearch;
    }

    /**
     * Test that listing saved searches requires authentication
     */
    public function testListSavedSearchesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/saved-searches');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Test listing user's saved searches
     */
    public function testListSavedSearches(): void
    {
        $this->authenticateAs($this->testUser);

        // Create test saved searches
        $this->createSavedSearch($this->testUser, 'My Search 1', 'invoice', ['category' => 'finance']);
        $this->createSavedSearch($this->testUser, 'My Search 2', 'contract', [], false);

        // Create a public search by another user
        $this->createSavedSearch($this->otherUser, 'Public Search', 'report', [], true);

        // Create a private search by another user (should not be visible)
        $this->createSavedSearch($this->otherUser, 'Private Search', 'private', [], false);

        $this->client->request('GET', '/api/saved-searches');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should see own searches + public searches from others
        $this->assertGreaterThanOrEqual(3, count($data));

        $names = array_column($data, 'name');
        $this->assertContains('My Search 1', $names);
        $this->assertContains('My Search 2', $names);
        $this->assertContains('Public Search', $names);
        $this->assertNotContains('Private Search', $names);
    }

    /**
     * Test creating a saved search
     */
    public function testCreateSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $payload = [
            'name' => 'New Saved Search',
            'query' => 'test query',
            'filters' => ['category' => 'invoices', 'dateFrom' => '2025-01-01'],
            'description' => 'Test description',
            'isPublic' => false
        ];

        $this->client->request(
            'POST',
            '/api/saved-searches',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('New Saved Search', $data['name']);
        $this->assertEquals('test query', $data['query']);
        $this->assertEquals($payload['filters'], $data['filters']);
        $this->assertEquals('Test description', $data['description']);
        $this->assertFalse($data['isPublic']);
        $this->assertArrayHasKey('createdAt', $data);
    }

    /**
     * Test creating a saved search without required fields fails
     */
    public function testCreateSavedSearchWithoutRequiredFields(): void
    {
        $this->authenticateAs($this->testUser);

        // Missing 'query'
        $payload = [
            'name' => 'Incomplete Search'
        ];

        $this->client->request(
            'POST',
            '/api/saved-searches',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test getting a saved search by ID
     */
    public function testGetSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $savedSearch = $this->createSavedSearch(
            $this->testUser,
            'Test Search',
            'query text',
            ['category' => 'test']
        );

        $this->client->request('GET', '/api/saved-searches/' . $savedSearch->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals($savedSearch->getId(), $data['id']);
        $this->assertEquals('Test Search', $data['name']);
        $this->assertEquals('query text', $data['query']);
        $this->assertEquals(['category' => 'test'], $data['filters']);
        $this->assertTrue($data['isOwner']);
    }

    /**
     * Test getting a non-existent saved search returns 404
     */
    public function testGetNonExistentSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $fakeId = Uuid::uuid4()->toString();
        $this->client->request('GET', '/api/saved-searches/' . $fakeId);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Test accessing another user's private saved search is forbidden
     */
    public function testGetOtherUserPrivateSavedSearchForbidden(): void
    {
        $this->authenticateAs($this->testUser);

        $privateSavedSearch = $this->createSavedSearch(
            $this->otherUser,
            'Private Search',
            'private query',
            [],
            false
        );

        $this->client->request('GET', '/api/saved-searches/' . $privateSavedSearch->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Test accessing another user's public saved search is allowed
     */
    public function testGetOtherUserPublicSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $publicSavedSearch = $this->createSavedSearch(
            $this->otherUser,
            'Public Search',
            'public query',
            [],
            true
        );

        $this->client->request('GET', '/api/saved-searches/' . $publicSavedSearch->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('Public Search', $data['name']);
        $this->assertFalse($data['isOwner']); // Not the owner
    }

    /**
     * Test executing a saved search
     */
    public function testExecuteSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $savedSearch = $this->createSavedSearch(
            $this->testUser,
            'Execute Test',
            'test',
            ['limit' => 10]
        );

        $this->client->request('GET', '/api/saved-searches/' . $savedSearch->getId() . '/execute');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('savedSearchId', $data);
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals($savedSearch->getId(), $data['savedSearchId']);
    }

    /**
     * Test executing a saved search increments usage count
     */
    public function testExecuteSavedSearchIncrementsUsageCount(): void
    {
        $this->authenticateAs($this->testUser);

        $savedSearch = $this->createSavedSearch(
            $this->testUser,
            'Usage Test',
            'test'
        );

        $initialUsageCount = $savedSearch->getUsageCount();

        $this->client->request('GET', '/api/saved-searches/' . $savedSearch->getId() . '/execute');

        $this->assertResponseIsSuccessful();

        // Refresh entity from database
        $this->entityManager->refresh($savedSearch);

        $this->assertEquals($initialUsageCount + 1, $savedSearch->getUsageCount());
        $this->assertNotNull($savedSearch->getLastUsedAt());
    }

    /**
     * Test updating a saved search
     */
    public function testUpdateSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $savedSearch = $this->createSavedSearch(
            $this->testUser,
            'Original Name',
            'original query'
        );

        $payload = [
            'name' => 'Updated Name',
            'query' => 'updated query',
            'filters' => ['category' => 'updated'],
            'description' => 'Updated description',
            'isPublic' => true
        ];

        $this->client->request(
            'PUT',
            '/api/saved-searches/' . $savedSearch->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('Updated Name', $data['name']);
        $this->assertEquals('updated query', $data['query']);
        $this->assertEquals(['category' => 'updated'], $data['filters']);
        $this->assertEquals('Updated description', $data['description']);
        $this->assertTrue($data['isPublic']);
    }

    /**
     * Test updating another user's saved search is forbidden
     */
    public function testUpdateOtherUserSavedSearchForbidden(): void
    {
        $this->authenticateAs($this->testUser);

        $otherUserSearch = $this->createSavedSearch(
            $this->otherUser,
            'Other User Search',
            'query'
        );

        $payload = ['name' => 'Hacked Name'];

        $this->client->request(
            'PUT',
            '/api/saved-searches/' . $otherUserSearch->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Test deleting a saved search
     */
    public function testDeleteSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $savedSearch = $this->createSavedSearch(
            $this->testUser,
            'To Delete',
            'delete query'
        );

        $searchId = $savedSearch->getId();

        $this->client->request('DELETE', '/api/saved-searches/' . $searchId);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);

        // Verify deletion
        $deleted = $this->entityManager->find(SavedSearch::class, $searchId);
        $this->assertNull($deleted);
    }

    /**
     * Test deleting another user's saved search is forbidden
     */
    public function testDeleteOtherUserSavedSearchForbidden(): void
    {
        $this->authenticateAs($this->testUser);

        $otherUserSearch = $this->createSavedSearch(
            $this->otherUser,
            'Other User Search',
            'query'
        );

        $this->client->request('DELETE', '/api/saved-searches/' . $otherUserSearch->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Test deleting a non-existent saved search returns 404
     */
    public function testDeleteNonExistentSavedSearch(): void
    {
        $this->authenticateAs($this->testUser);

        $fakeId = Uuid::uuid4()->toString();
        $this->client->request('DELETE', '/api/saved-searches/' . $fakeId);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

}
