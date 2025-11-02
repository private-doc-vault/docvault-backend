<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SearchHistory;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchHistory entity
 */
class SearchHistoryTest extends TestCase
{
    private SearchHistory $searchHistory;
    private User $user;

    protected function setUp(): void
    {
        $this->searchHistory = new SearchHistory();
        $this->user = new User();
        $this->user->setEmail('test@example.com');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(SearchHistory::class, $this->searchHistory);
    }

    public function testIdCanBeSetAndRetrieved(): void
    {
        $this->searchHistory->setId('history-123');
        $this->assertEquals('history-123', $this->searchHistory->getId());
    }

    public function testQueryCanBeSetAndRetrieved(): void
    {
        $this->searchHistory->setQuery('invoice payment');
        $this->assertEquals('invoice payment', $this->searchHistory->getQuery());
    }

    public function testFiltersCanBeSetAndRetrieved(): void
    {
        $filters = [
            'category' => 'Invoices',
            'dateFrom' => '2024-01-01'
        ];

        $this->searchHistory->setFilters($filters);
        $this->assertEquals($filters, $this->searchHistory->getFilters());
    }

    public function testFiltersDefaultsToEmptyArray(): void
    {
        $this->assertEquals([], $this->searchHistory->getFilters());
    }

    public function testUserCanBeSetAndRetrieved(): void
    {
        $this->searchHistory->setUser($this->user);
        $this->assertSame($this->user, $this->searchHistory->getUser());
    }

    public function testResultCountCanBeSetAndRetrieved(): void
    {
        $this->searchHistory->setResultCount(42);
        $this->assertEquals(42, $this->searchHistory->getResultCount());
    }

    public function testResultCountDefaultsToZero(): void
    {
        $this->assertEquals(0, $this->searchHistory->getResultCount());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $history = new SearchHistory();
        $this->assertInstanceOf(\DateTimeImmutable::class, $history->getCreatedAt());
    }

    public function testCreatedAtCanBeSet(): void
    {
        $date = new \DateTimeImmutable('2024-01-15 10:00:00');
        $this->searchHistory->setCreatedAt($date);
        $this->assertEquals($date, $this->searchHistory->getCreatedAt());
    }
}
