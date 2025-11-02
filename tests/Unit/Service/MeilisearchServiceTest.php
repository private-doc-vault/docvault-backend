<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MeilisearchService;
use Meilisearch\Client;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MeilisearchService
 *
 * Tests Meilisearch client configuration, index management, and connectivity
 */
class MeilisearchServiceTest extends TestCase
{
    private MockObject&Client $client;
    private MockObject&LoggerInterface $logger;
    private MeilisearchService $service;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MeilisearchService(
            $this->client,
            $this->logger
        );
    }

    public function testServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(MeilisearchService::class, $this->service);
    }

    public function testGetClientReturnsClientInstance(): void
    {
        $client = $this->service->getClient();

        $this->assertInstanceOf(Client::class, $client);
    }

    public function testHealthCheckReturnsTrueWhenHealthy(): void
    {
        $this->client->expects($this->once())
            ->method('isHealthy')
            ->willReturn(true);

        $result = $this->service->healthCheck();

        $this->assertTrue($result);
    }

    public function testHealthCheckReturnsFalseWhenUnhealthy(): void
    {
        $this->client->expects($this->once())
            ->method('isHealthy')
            ->willReturn(false);

        $result = $this->service->healthCheck();

        $this->assertFalse($result);
    }

    public function testCreateIndexCreatesNewIndex(): void
    {
        $indexName = 'test_index';
        $primaryKey = 'id';

        $expectedResult = [
            'taskUid' => 1,
            'indexUid' => $indexName,
            'status' => 'enqueued',
        ];

        $this->client->expects($this->once())
            ->method('createIndex')
            ->with($indexName, ['primaryKey' => $primaryKey])
            ->willReturn($expectedResult);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Meilisearch index created', $this->anything());

        $result = $this->service->createIndex($indexName, $primaryKey);

        $this->assertIsArray($result);
        $this->assertEquals($indexName, $result['indexUid']);
    }

    public function testCreateIndexLogsErrorOnFailure(): void
    {
        $indexName = 'test_index';

        $this->client->expects($this->once())
            ->method('createIndex')
            ->willThrowException(new \Exception('Index already exists'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to create Meilisearch index', $this->anything());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Index already exists');

        $this->service->createIndex($indexName);
    }

    public function testGetIndexReturnsExistingIndex(): void
    {
        $indexName = 'test_index';

        $mockIndex = $this->createMock(Indexes::class);

        $this->client->expects($this->once())
            ->method('index')
            ->with($indexName)
            ->willReturn($mockIndex);

        $result = $this->service->getIndex($indexName);

        $this->assertInstanceOf(Indexes::class, $result);
    }

    public function testDeleteIndexRemovesIndex(): void
    {
        $indexName = 'test_index';

        $this->client->expects($this->once())
            ->method('deleteIndex')
            ->with($indexName);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Meilisearch index deleted', ['index' => $indexName]);

        $this->service->deleteIndex($indexName);
    }

    public function testDeleteIndexLogsErrorOnFailure(): void
    {
        $indexName = 'test_index';

        $this->client->expects($this->once())
            ->method('deleteIndex')
            ->willThrowException(new \Exception('Index not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to delete Meilisearch index', $this->anything());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Index not found');

        $this->service->deleteIndex($indexName);
    }

    public function testGetAllIndexesReturnsIndexList(): void
    {
        $mockIndexes = [
            ['uid' => 'index1', 'primaryKey' => 'id'],
            ['uid' => 'index2', 'primaryKey' => 'id'],
        ];

        $mockResult = $this->createMock(IndexesResults::class);
        $mockResult->expects($this->once())
            ->method('getResults')
            ->willReturn($mockIndexes);

        $this->client->expects($this->once())
            ->method('getIndexes')
            ->willReturn($mockResult);

        $result = $this->service->getAllIndexes();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('index1', $result[0]['uid']);
    }

    public function testUpdateIndexSettingsUpdatesConfiguration(): void
    {
        $indexName = 'test_index';
        $settings = [
            'searchableAttributes' => ['title', 'content'],
            'filterableAttributes' => ['category', 'date'],
        ];

        $mockIndex = $this->createMock(Indexes::class);
        $mockIndex->expects($this->once())
            ->method('updateSettings')
            ->with($settings);

        $this->client->expects($this->once())
            ->method('index')
            ->with($indexName)
            ->willReturn($mockIndex);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Meilisearch index settings updated', $this->anything());

        $this->service->updateIndexSettings($indexName, $settings);
    }

    public function testGetIndexStatsReturnsStatistics(): void
    {
        $indexName = 'test_index';
        $stats = [
            'numberOfDocuments' => 100,
            'isIndexing' => false,
        ];

        $mockIndex = $this->createMock(Indexes::class);
        $mockIndex->expects($this->once())
            ->method('stats')
            ->willReturn($stats);

        $this->client->expects($this->once())
            ->method('index')
            ->with($indexName)
            ->willReturn($mockIndex);

        $result = $this->service->getIndexStats($indexName);

        $this->assertIsArray($result);
        $this->assertEquals(100, $result['numberOfDocuments']);
        $this->assertFalse($result['isIndexing']);
    }

    public function testWaitForTaskCompletesSuccessfully(): void
    {
        $taskId = 123;

        $this->client->expects($this->once())
            ->method('waitForTask')
            ->with($taskId, 5000, 100);

        $this->service->waitForTask($taskId);
    }

    public function testWaitForTaskLogsErrorOnTimeout(): void
    {
        $taskId = 123;

        $this->client->expects($this->once())
            ->method('waitForTask')
            ->willThrowException(new \Exception('Task timeout'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to wait for Meilisearch task', $this->anything());

        $this->expectException(\Exception::class);

        $this->service->waitForTask($taskId);
    }
}
