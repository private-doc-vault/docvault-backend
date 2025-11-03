<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\DocumentShare;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DocumentShare entity
 *
 * Tests cover:
 * - Basic property getters and setters
 * - Relationship with Document and User entities
 * - Permission level management
 * - Expiration date handling
 * - Share metadata
 */
class DocumentShareTest extends TestCase
{
    private DocumentShare $documentShare;

    protected function setUp(): void
    {
        $this->documentShare = new DocumentShare();
    }

    // ========== Basic Properties Tests ==========

    public function testSetAndGetId(): void
    {
        $id = '123e4567-e89b-12d3-a456-426614174000';

        $this->documentShare->setId($id);

        $this->assertSame($id, $this->documentShare->getId());
    }

    public function testIdIsNullByDefault(): void
    {
        // DocumentShare should auto-generate UUID in constructor
        $this->assertNotNull($this->documentShare->getId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->documentShare->getId());
    }

    // ========== Document Relationship Tests ==========

    public function testSetAndGetDocument(): void
    {
        $document = $this->createMock(Document::class);

        $this->documentShare->setDocument($document);

        $this->assertSame($document, $this->documentShare->getDocument());
    }

    public function testDocumentIsNullByDefault(): void
    {
        $this->assertNull($this->documentShare->getDocument());
    }

    // ========== User Relationships Tests ==========

    public function testSetAndGetSharedWith(): void
    {
        $user = $this->createMock(User::class);

        $this->documentShare->setSharedWith($user);

        $this->assertSame($user, $this->documentShare->getSharedWith());
    }

    public function testSharedWithIsNullByDefault(): void
    {
        $this->assertNull($this->documentShare->getSharedWith());
    }

    public function testSetAndGetSharedBy(): void
    {
        $user = $this->createMock(User::class);

        $this->documentShare->setSharedBy($user);

        $this->assertSame($user, $this->documentShare->getSharedBy());
    }

    public function testSharedByIsNullByDefault(): void
    {
        $this->assertNull($this->documentShare->getSharedBy());
    }

    // ========== Permission Level Tests ==========

    public function testSetAndGetPermissionLevel(): void
    {
        $this->documentShare->setPermissionLevel('write');

        $this->assertSame('write', $this->documentShare->getPermissionLevel());
    }

    public function testPermissionLevelDefaultsToView(): void
    {
        $this->assertSame('view', $this->documentShare->getPermissionLevel());
    }

    public function testCanViewWhenPermissionIsView(): void
    {
        $this->documentShare->setPermissionLevel('view');

        $this->assertTrue($this->documentShare->canView());
    }

    public function testCanViewWhenPermissionIsWrite(): void
    {
        $this->documentShare->setPermissionLevel('write');

        $this->assertTrue($this->documentShare->canView());
    }

    public function testCanEditWhenPermissionIsView(): void
    {
        $this->documentShare->setPermissionLevel('view');

        $this->assertFalse($this->documentShare->canEdit());
    }

    public function testCanEditWhenPermissionIsWrite(): void
    {
        $this->documentShare->setPermissionLevel('write');

        $this->assertTrue($this->documentShare->canEdit());
    }

    // ========== Expiration Tests ==========

    public function testSetAndGetExpiresAt(): void
    {
        $expiresAt = new \DateTimeImmutable('2025-12-31');

        $this->documentShare->setExpiresAt($expiresAt);

        $this->assertSame($expiresAt, $this->documentShare->getExpiresAt());
    }

    public function testExpiresAtIsNullByDefault(): void
    {
        $this->assertNull($this->documentShare->getExpiresAt());
    }

    public function testIsExpiredWhenNoExpirationSet(): void
    {
        $this->assertFalse($this->documentShare->isExpired());
    }

    public function testIsExpiredWhenExpirationInFuture(): void
    {
        $futureDate = new \DateTimeImmutable('+1 day');
        $this->documentShare->setExpiresAt($futureDate);

        $this->assertFalse($this->documentShare->isExpired());
    }

    public function testIsExpiredWhenExpirationInPast(): void
    {
        $pastDate = new \DateTimeImmutable('-1 day');
        $this->documentShare->setExpiresAt($pastDate);

        $this->assertTrue($this->documentShare->isExpired());
    }

    // ========== Status Tests ==========

    public function testSetAndGetIsActive(): void
    {
        $this->documentShare->setIsActive(false);

        $this->assertFalse($this->documentShare->isActive());
    }

    public function testIsActiveDefaultsToTrue(): void
    {
        $this->assertTrue($this->documentShare->isActive());
    }

    // ========== Metadata Tests ==========

    public function testSetAndGetNote(): void
    {
        $note = 'Please review this document';

        $this->documentShare->setNote($note);

        $this->assertSame($note, $this->documentShare->getNote());
    }

    public function testNoteIsNullByDefault(): void
    {
        $this->assertNull($this->documentShare->getNote());
    }

    // ========== Timestamp Tests ==========

    public function testSetAndGetCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01');

        $this->documentShare->setCreatedAt($createdAt);

        $this->assertSame($createdAt, $this->documentShare->getCreatedAt());
    }

    public function testCreatedAtIsNullByDefault(): void
    {
        $this->assertNull($this->documentShare->getCreatedAt());
    }

    public function testSetAndGetAccessedAt(): void
    {
        $accessedAt = new \DateTimeImmutable('2024-06-01');

        $this->documentShare->setAccessedAt($accessedAt);

        $this->assertSame($accessedAt, $this->documentShare->getAccessedAt());
    }

    public function testAccessedAtIsNullByDefault(): void
    {
        $this->assertNull($this->documentShare->getAccessedAt());
    }

    public function testMarkAsAccessed(): void
    {
        $beforeTime = new \DateTimeImmutable();

        $this->documentShare->markAsAccessed();

        $afterTime = new \DateTimeImmutable();
        $accessedAt = $this->documentShare->getAccessedAt();

        $this->assertNotNull($accessedAt);
        $this->assertGreaterThanOrEqual($beforeTime->getTimestamp(), $accessedAt->getTimestamp());
        $this->assertLessThanOrEqual($afterTime->getTimestamp(), $accessedAt->getTimestamp());
    }

    // ========== Access Count Tests ==========

    public function testSetAndGetAccessCount(): void
    {
        $this->documentShare->setAccessCount(5);

        $this->assertSame(5, $this->documentShare->getAccessCount());
    }

    public function testAccessCountDefaultsToZero(): void
    {
        $this->assertSame(0, $this->documentShare->getAccessCount());
    }

    public function testIncrementAccessCount(): void
    {
        $this->documentShare->setAccessCount(3);

        $this->documentShare->incrementAccessCount();

        $this->assertSame(4, $this->documentShare->getAccessCount());
    }

    public function testIncrementAccessCountFromZero(): void
    {
        $this->documentShare->incrementAccessCount();

        $this->assertSame(1, $this->documentShare->getAccessCount());
    }

    // ========== Integration Tests ==========

    public function testCompleteShareScenario(): void
    {
        $document = $this->createMock(Document::class);
        $sharedBy = $this->createMock(User::class);
        $sharedWith = $this->createMock(User::class);

        $share = new DocumentShare();
        $share->setId('share-123');
        $share->setDocument($document);
        $share->setSharedBy($sharedBy);
        $share->setSharedWith($sharedWith);
        $share->setPermissionLevel('write');
        $share->setNote('Collaborate on this document');
        $share->setExpiresAt(new \DateTimeImmutable('+30 days'));
        $share->setCreatedAt(new \DateTimeImmutable());

        $this->assertSame('share-123', $share->getId());
        $this->assertSame($document, $share->getDocument());
        $this->assertSame($sharedBy, $share->getSharedBy());
        $this->assertSame($sharedWith, $share->getSharedWith());
        $this->assertSame('write', $share->getPermissionLevel());
        $this->assertSame('Collaborate on this document', $share->getNote());
        $this->assertTrue($share->canEdit());
        $this->assertTrue($share->canView());
        $this->assertFalse($share->isExpired());
        $this->assertTrue($share->isActive());
    }
}
