<?php

declare(strict_types=1);

namespace App\Service;

use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;

/**
 * Meilisearch Service
 *
 * Provides high-level interface for Meilisearch operations:
 * - Health checks and connectivity
 * - Index management (create, delete, list)
 * - Index configuration and settings
 * - Task monitoring
 */
class MeilisearchService
{
    public function __construct(
        private readonly Client $client,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get the Meilisearch client instance
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Check if Meilisearch is healthy and available
     *
     * @return bool
     */
    public function healthCheck(): bool
    {
        return $this->client->isHealthy();
    }

    /**
     * Create a new index
     *
     * @param string $indexName
     * @param string|null $primaryKey
     * @return array
     * @throws \Exception
     */
    public function createIndex(string $indexName, ?string $primaryKey = null): array
    {
        try {
            $options = $primaryKey ? ['primaryKey' => $primaryKey] : [];
            $result = $this->client->createIndex($indexName, $options);

            $this->logger->info('Meilisearch index created', [
                'index' => $indexName,
                'primaryKey' => $primaryKey
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Meilisearch index', [
                'index' => $indexName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get an existing index
     *
     * @param string $indexName
     * @return Indexes
     */
    public function getIndex(string $indexName): Indexes
    {
        return $this->client->index($indexName);
    }

    /**
     * Delete an index
     *
     * @param string $indexName
     * @return void
     * @throws \Exception
     */
    public function deleteIndex(string $indexName): void
    {
        try {
            $this->client->deleteIndex($indexName);

            $this->logger->info('Meilisearch index deleted', [
                'index' => $indexName
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete Meilisearch index', [
                'index' => $indexName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get all indexes
     *
     * @return array
     */
    public function getAllIndexes(): array
    {
        $result = $this->client->getIndexes();
        return $result->getResults();
    }

    /**
     * Update index settings
     *
     * @param string $indexName
     * @param array $settings
     * @return void
     */
    public function updateIndexSettings(string $indexName, array $settings): void
    {
        $index = $this->getIndex($indexName);
        $index->updateSettings($settings);

        $this->logger->info('Meilisearch index settings updated', [
            'index' => $indexName,
            'settings' => array_keys($settings)
        ]);
    }

    /**
     * Get index statistics
     *
     * @param string $indexName
     * @return array
     */
    public function getIndexStats(string $indexName): array
    {
        $index = $this->getIndex($indexName);
        return $index->stats();
    }

    /**
     * Wait for a task to complete
     *
     * @param int $taskId
     * @param int $timeoutMs
     * @param int $intervalMs
     * @return void
     * @throws \Exception
     */
    public function waitForTask(int $taskId, int $timeoutMs = 5000, int $intervalMs = 100): void
    {
        try {
            $this->client->waitForTask($taskId, $timeoutMs, $intervalMs);
        } catch (\Exception $e) {
            $this->logger->error('Failed to wait for Meilisearch task', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
