<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Category;
use App\Entity\Document;
use App\Entity\User;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for Meilisearch-powered search API
 *
 * Tests full-text search functionality using Meilisearch
 */
class MeilisearchSearchApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private SearchService $searchService;
    private User $testUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->searchService = $container->get(SearchService::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('search-test@example.com');
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();

        // Authenticate
        $this->client->loginUser($this->testUser);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if (isset($this->testUser)) {
            $this->entityManager->remove($this->testUser);
            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    private function createTestDocument(string $name, string $content, ?Category $category = null): Document
    {
        $document = new Document();
        $document->setOriginalName($name);
        $document->setFilename('test-' . uniqid() . '.pdf');
        $document->setFilePath('/tmp/test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setOcrText($content);
        $document->setSearchableContent($name . ' ' . $content);
        $document->setProcessingStatus('completed');
        $document->setUploadedBy($this->testUser);

        if ($category) {
            $document->setCategory($category);
        }

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    public function testSearchEndpointRequiresAuthentication(): void
    {
        // Restart client to clear authentication
        $this->client->restart();

        $this->client->request('GET', '/api/search/meilisearch', [
            'q' => 'test'
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSearchWithoutQueryReturnsError(): void
    {
        $this->client->request('GET', '/api/search/meilisearch');

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSearchReturnsMatchingDocuments(): void
    {
        // Create test documents
        $doc1 = $this->createTestDocument('Invoice 2024', 'Payment for services rendered');
        $doc2 = $this->createTestDocument('Receipt', 'Purchase receipt for equipment');

        // Index documents
        $this->searchService->indexDocument($doc1);
        $this->searchService->indexDocument($doc2);

        // Wait a moment for indexing
        usleep(500000); // 0.5 seconds

        $this->client->request('GET', '/api/search/meilisearch', [
            'q' => 'invoice'
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('hits', $data);
        $this->assertArrayHasKey('query', $data);
        $this->assertArrayHasKey('estimatedTotalHits', $data);
        $this->assertEquals('invoice', $data['query']);
        $this->assertGreaterThan(0, $data['estimatedTotalHits']);

        // Clean up
        $this->searchService->deleteDocument($doc1->getId());
        $this->searchService->deleteDocument($doc2->getId());
        $this->entityManager->remove($doc1);
        $this->entityManager->remove($doc2);
        $this->entityManager->flush();
    }

    public function testSearchWithCategoryFilter(): void
    {
        $category = new Category();
        $category->setName('Invoices');
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $doc1 = $this->createTestDocument('Invoice 001', 'Payment document', $category);
        $doc2 = $this->createTestDocument('Report', 'Annual report');

        $this->searchService->indexDocument($doc1);
        $this->searchService->indexDocument($doc2);

        usleep(500000);

        $this->client->request('GET', '/api/search/meilisearch', [
            'q' => 'document',
            'category' => 'Invoices'
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should only return documents in the Invoices category
        $this->assertGreaterThanOrEqual(1, $data['estimatedTotalHits']);

        // Clean up
        $this->searchService->deleteDocument($doc1->getId());
        $this->searchService->deleteDocument($doc2->getId());
        $this->entityManager->remove($doc1);
        $this->entityManager->remove($doc2);
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    public function testSearchWithPagination(): void
    {
        $documents = [];
        for ($i = 1; $i <= 5; $i++) {
            $doc = $this->createTestDocument("Document $i", "Content $i");
            $documents[] = $doc;
        }

        $this->searchService->indexMultipleDocuments($documents);

        usleep(500000);

        $this->client->request('GET', '/api/search/meilisearch', [
            'q' => 'document',
            'limit' => 2,
            'offset' => 0
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('hits', $data);
        $this->assertLessThanOrEqual(2, count($data['hits']));

        // Clean up
        foreach ($documents as $doc) {
            $this->searchService->deleteDocument($doc->getId());
            $this->entityManager->remove($doc);
        }
        $this->entityManager->flush();
    }

    public function testSearchWithSorting(): void
    {
        $doc1 = $this->createTestDocument('Alpha', 'First document');
        $doc2 = $this->createTestDocument('Beta', 'Second document');

        $this->searchService->indexDocument($doc1);
        $this->searchService->indexDocument($doc2);

        usleep(500000);

        $this->client->request('GET', '/api/search/meilisearch', [
            'q' => 'document',
            'sort' => 'originalName:asc'
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('hits', $data);

        // Clean up
        $this->searchService->deleteDocument($doc1->getId());
        $this->searchService->deleteDocument($doc2->getId());
        $this->entityManager->remove($doc1);
        $this->entityManager->remove($doc2);
        $this->entityManager->flush();
    }

    public function testEmptySearchReturnsAllDocuments(): void
    {
        $doc = $this->createTestDocument('Test', 'Content');
        $this->searchService->indexDocument($doc);

        usleep(500000);

        $this->client->request('GET', '/api/search/meilisearch', [
            'q' => ''
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('hits', $data);

        // Clean up
        $this->searchService->deleteDocument($doc->getId());
        $this->entityManager->remove($doc);
        $this->entityManager->flush();
    }
}
