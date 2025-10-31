<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\SearchHistory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for Search History API endpoints
 *
 * Tests history listing, pagination, and clearing
 */
class SearchHistoryApiTest extends WebTestCase
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

        // Create test users
        $this->testUser = $this->createUser('testuser@example.com', 'Test User');
        $this->otherUser = $this->createUser('otheruser@example.com', 'Other User');
    }

    private function createUser(string $email, string $name): User
    {
        $user = new User();
        $user->setId(Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setName($name);
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

    private function createSearchHistory(
        User $user,
        string $query,
        array $filters = [],
        int $resultCount = 0
    ): SearchHistory {
        $history = new SearchHistory();
        $history->setId(Uuid::uuid4()->toString());
        $history->setQuery($query);
        $history->setFilters($filters);
        $history->setResultCount($resultCount);
        $history->setUser($user);

        $this->entityManager->persist($history);
        $this->entityManager->flush();

        return $history;
    }

    /**
     * Test that getting search history requires authentication
     */
    public function testGetSearchHistoryRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/saved-searches/history');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Test getting user's search history
     */
    public function testGetSearchHistory(): void
    {
        $this->authenticateAs($this->testUser);

        // Create test history entries
        $this->createSearchHistory($this->testUser, 'invoice search', ['category' => 'finance'], 15);
        $this->createSearchHistory($this->testUser, 'contract search', [], 8);
        $this->createSearchHistory($this->testUser, 'report search', ['dateFrom' => '2025-01-01'], 23);

        // Create history for other user (should not be visible)
        $this->createSearchHistory($this->otherUser, 'other user search', [], 5);

        $this->client->request('GET', '/api/saved-searches/history');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should only see own history
        $this->assertGreaterThanOrEqual(3, count($data));

        $queries = array_column($data, 'query');
        $this->assertContains('invoice search', $queries);
        $this->assertContains('contract search', $queries);
        $this->assertContains('report search', $queries);
        $this->assertNotContains('other user search', $queries);

        // Verify data structure
        $firstEntry = $data[0];
        $this->assertArrayHasKey('id', $firstEntry);
        $this->assertArrayHasKey('query', $firstEntry);
        $this->assertArrayHasKey('filters', $firstEntry);
        $this->assertArrayHasKey('resultCount', $firstEntry);
        $this->assertArrayHasKey('createdAt', $firstEntry);
    }

    /**
     * Test search history is ordered by creation date (newest first)
     */
    public function testSearchHistoryOrderedByDate(): void
    {
        $this->authenticateAs($this->testUser);

        // Create entries with delays
        $oldest = $this->createSearchHistory($this->testUser, 'oldest search', [], 1);
        sleep(1);
        $middle = $this->createSearchHistory($this->testUser, 'middle search', [], 2);
        sleep(1);
        $newest = $this->createSearchHistory($this->testUser, 'newest search', [], 3);

        $this->client->request('GET', '/api/saved-searches/history');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // First item should be the newest
        $this->assertEquals('newest search', $data[0]['query']);
        $this->assertEquals('middle search', $data[1]['query']);
        $this->assertEquals('oldest search', $data[2]['query']);
    }

    /**
     * Test search history pagination with limit parameter
     */
    public function testSearchHistoryPagination(): void
    {
        $this->authenticateAs($this->testUser);

        // Create 25 history entries
        for ($i = 1; $i <= 25; $i++) {
            $this->createSearchHistory($this->testUser, "search $i", [], $i);
        }

        // Request with limit
        $this->client->request('GET', '/api/saved-searches/history?limit=10');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should only return 10 items
        $this->assertCount(10, $data);
    }

    /**
     * Test search history default limit
     */
    public function testSearchHistoryDefaultLimit(): void
    {
        $this->authenticateAs($this->testUser);

        // Create 30 history entries
        for ($i = 1; $i <= 30; $i++) {
            $this->createSearchHistory($this->testUser, "search $i", [], $i);
        }

        $this->client->request('GET', '/api/saved-searches/history');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should return default limit (20)
        $this->assertCount(20, $data);
    }

    /**
     * Test search history maximum limit enforcement
     */
    public function testSearchHistoryMaximumLimit(): void
    {
        $this->authenticateAs($this->testUser);

        // Try to request more than max (100)
        $this->client->request('GET', '/api/saved-searches/history?limit=500');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should cap at 100
        $this->assertLessThanOrEqual(100, count($data));
    }

    /**
     * Test search history includes filters
     */
    public function testSearchHistoryIncludesFilters(): void
    {
        $this->authenticateAs($this->testUser);

        $filters = [
            'category' => 'invoices',
            'dateFrom' => '2025-01-01',
            'dateTo' => '2025-12-31',
            'tags' => ['urgent', 'reviewed']
        ];

        $this->createSearchHistory($this->testUser, 'complex search', $filters, 42);

        $this->client->request('GET', '/api/saved-searches/history');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $complexSearch = array_values(array_filter($data, fn($h) => $h['query'] === 'complex search'))[0];

        $this->assertEquals($filters, $complexSearch['filters']);
        $this->assertEquals(42, $complexSearch['resultCount']);
    }

    /**
     * Test clearing search history
     */
    public function testClearSearchHistory(): void
    {
        $this->authenticateAs($this->testUser);

        // Create history entries
        $this->createSearchHistory($this->testUser, 'search 1', [], 10);
        $this->createSearchHistory($this->testUser, 'search 2', [], 20);
        $this->createSearchHistory($this->testUser, 'search 3', [], 30);

        // Create history for other user (should not be affected)
        $otherUserHistory = $this->createSearchHistory($this->otherUser, 'other user search', [], 5);

        $this->client->request('DELETE', '/api/saved-searches/history/clear');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);

        // Verify user's history is cleared
        $this->client->request('GET', '/api/saved-searches/history');
        $historyData = json_decode($this->client->getResponse()->getContent(), true);

        // Should have no history entries for this user
        $this->assertCount(0, $historyData);

        // Verify other user's history is intact
        $otherUserHistoryStillExists = $this->entityManager->find(
            SearchHistory::class,
            $otherUserHistory->getId()
        );
        $this->assertNotNull($otherUserHistoryStillExists);
    }

    /**
     * Test clearing search history requires authentication
     */
    public function testClearSearchHistoryRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/api/saved-searches/history/clear');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Test empty search history returns empty array
     */
    public function testEmptySearchHistory(): void
    {
        $this->authenticateAs($this->testUser);

        $this->client->request('GET', '/api/saved-searches/history');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test data
        $this->entityManager->clear();
    }
}
