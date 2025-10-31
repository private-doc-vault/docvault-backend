<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SavedSearch;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SavedSearch entity
 */
class SavedSearchTest extends TestCase
{
    private SavedSearch $savedSearch;
    private User $user;

    protected function setUp(): void
    {
        $this->savedSearch = new SavedSearch();
        $this->user = new User();
        $this->user->setEmail('test@example.com');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(SavedSearch::class, $this->savedSearch);
    }

    public function testIdCanBeSetAndRetrieved(): void
    {
        $this->savedSearch->setId('search-123');
        $this->assertEquals('search-123', $this->savedSearch->getId());
    }

    public function testNameCanBeSetAndRetrieved(): void
    {
        $this->savedSearch->setName('Invoice Search');
        $this->assertEquals('Invoice Search', $this->savedSearch->getName());
    }

    public function testQueryCanBeSetAndRetrieved(): void
    {
        $this->savedSearch->setQuery('invoice payment');
        $this->assertEquals('invoice payment', $this->savedSearch->getQuery());
    }

    public function testFiltersCanBeSetAndRetrieved(): void
    {
        $filters = [
            'category' => 'Invoices',
            'dateFrom' => '2024-01-01',
            'minConfidence' => 0.8
        ];

        $this->savedSearch->setFilters($filters);
        $this->assertEquals($filters, $this->savedSearch->getFilters());
    }

    public function testFiltersDefaultsToEmptyArray(): void
    {
        $this->assertEquals([], $this->savedSearch->getFilters());
    }

    public function testUserCanBeSetAndRetrieved(): void
    {
        $this->savedSearch->setUser($this->user);
        $this->assertSame($this->user, $this->savedSearch->getUser());
    }

    public function testIsPublicCanBeSetAndRetrieved(): void
    {
        $this->savedSearch->setIsPublic(true);
        $this->assertTrue($this->savedSearch->isPublic());

        $this->savedSearch->setIsPublic(false);
        $this->assertFalse($this->savedSearch->isPublic());
    }

    public function testIsPublicDefaultsToFalse(): void
    {
        $this->assertFalse($this->savedSearch->isPublic());
    }

    public function testDescriptionCanBeSetAndRetrieved(): void
    {
        $description = 'Search for all unpaid invoices';
        $this->savedSearch->setDescription($description);
        $this->assertEquals($description, $this->savedSearch->getDescription());
    }

    public function testDescriptionCanBeNull(): void
    {
        $this->assertNull($this->savedSearch->getDescription());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $search = new SavedSearch();
        $this->assertInstanceOf(\DateTimeImmutable::class, $search->getCreatedAt());
    }

    public function testCreatedAtCanBeSet(): void
    {
        $date = new \DateTimeImmutable('2024-01-15 10:00:00');
        $this->savedSearch->setCreatedAt($date);
        $this->assertEquals($date, $this->savedSearch->getCreatedAt());
    }

    public function testLastUsedAtCanBeSetAndRetrieved(): void
    {
        $date = new \DateTimeImmutable('2024-01-20 15:30:00');
        $this->savedSearch->setLastUsedAt($date);
        $this->assertEquals($date, $this->savedSearch->getLastUsedAt());
    }

    public function testLastUsedAtCanBeNull(): void
    {
        $this->assertNull($this->savedSearch->getLastUsedAt());
    }

    public function testUsageCountCanBeIncrementedAndRetrieved(): void
    {
        $this->assertEquals(0, $this->savedSearch->getUsageCount());

        $this->savedSearch->incrementUsageCount();
        $this->assertEquals(1, $this->savedSearch->getUsageCount());

        $this->savedSearch->incrementUsageCount();
        $this->assertEquals(2, $this->savedSearch->getUsageCount());
    }

    public function testRecordUsageUpdatesCountAndTimestamp(): void
    {
        $this->assertEquals(0, $this->savedSearch->getUsageCount());
        $this->assertNull($this->savedSearch->getLastUsedAt());

        $this->savedSearch->recordUsage();

        $this->assertEquals(1, $this->savedSearch->getUsageCount());
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->savedSearch->getLastUsedAt());
    }
}
