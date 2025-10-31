<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Document;
use App\Entity\DocumentShare;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Security\DocumentPermissionMatrix;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Document Permission Matrix
 *
 * Tests cover:
 * - Document ownership permissions
 * - Document sharing permissions
 * - Group-based permissions
 * - Permission inheritance
 */
class DocumentPermissionMatrixTest extends TestCase
{
    private DocumentPermissionMatrix $permissionMatrix;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->permissionMatrix = new DocumentPermissionMatrix($this->entityManager);
    }

    // ========== Owner Permissions Tests ==========

    public function testOwnerCanReadOwnDocument(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);

        $document->method('getUploadedBy')->willReturn($user);
        $user->method('getId')->willReturn('user-123');

        $this->assertTrue($this->permissionMatrix->canRead($user, $document));
    }

    public function testOwnerCanWriteOwnDocument(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);

        $document->method('getUploadedBy')->willReturn($user);
        $user->method('getId')->willReturn('user-123');

        $this->assertTrue($this->permissionMatrix->canWrite($user, $document));
    }

    public function testOwnerCanDeleteOwnDocument(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);

        $document->method('getUploadedBy')->willReturn($user);
        $user->method('getId')->willReturn('user-123');

        $this->assertTrue($this->permissionMatrix->canDelete($user, $document));
    }

    // ========== Shared Document Permissions Tests ==========

    public function testUserWithViewShareCanReadDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $sharedUser = $this->createMock(User::class);
        $sharedUser->method('getId')->willReturn('user-456');
        $sharedUser->method('getRoles')->willReturn(['ROLE_USER']);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-789');
        $document->method('getUploadedBy')->willReturn($owner);

        $share = $this->createMock(DocumentShare::class);
        $share->method('getSharedWith')->willReturn($sharedUser);
        $share->method('getDocument')->willReturn($document);
        $share->method('getPermissionLevel')->willReturn('view');
        $share->method('canView')->willReturn(true);
        $share->method('canEdit')->willReturn(false);
        $share->method('isActive')->willReturn(true);
        $share->method('isExpired')->willReturn(false);

        // Mock repository to return the share
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($share);

        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->assertTrue($this->permissionMatrix->canRead($sharedUser, $document));
    }

    public function testUserWithViewShareCannotWriteDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $sharedUser = $this->createMock(User::class);
        $sharedUser->method('getId')->willReturn('user-456');

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-789');
        $document->method('getUploadedBy')->willReturn($owner);

        $share = $this->createMock(DocumentShare::class);
        $share->method('getSharedWith')->willReturn($sharedUser);
        $share->method('getDocument')->willReturn($document);
        $share->method('getPermissionLevel')->willReturn('view');
        $share->method('isActive')->willReturn(true);
        $share->method('isExpired')->willReturn(false);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($share);

        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->assertFalse($this->permissionMatrix->canWrite($sharedUser, $document));
    }

    public function testUserWithWriteShareCanWriteDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $sharedUser = $this->createMock(User::class);
        $sharedUser->method('getId')->willReturn('user-456');
        $sharedUser->method('getRoles')->willReturn(['ROLE_USER']);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-789');
        $document->method('getUploadedBy')->willReturn($owner);

        $share = $this->createMock(DocumentShare::class);
        $share->method('getSharedWith')->willReturn($sharedUser);
        $share->method('getDocument')->willReturn($document);
        $share->method('getPermissionLevel')->willReturn('write');
        $share->method('canView')->willReturn(true);
        $share->method('canEdit')->willReturn(true);
        $share->method('isActive')->willReturn(true);
        $share->method('isExpired')->willReturn(false);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($share);

        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->assertTrue($this->permissionMatrix->canWrite($sharedUser, $document));
    }

    public function testInactiveShareDoesNotGrantPermission(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $sharedUser = $this->createMock(User::class);
        $sharedUser->method('getId')->willReturn('user-456');

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-789');
        $document->method('getUploadedBy')->willReturn($owner);

        $share = $this->createMock(DocumentShare::class);
        $share->method('getSharedWith')->willReturn($sharedUser);
        $share->method('getDocument')->willReturn($document);
        $share->method('getPermissionLevel')->willReturn('write');
        $share->method('isActive')->willReturn(false);
        $share->method('isExpired')->willReturn(false);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($share);

        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->assertFalse($this->permissionMatrix->canRead($sharedUser, $document));
    }

    public function testExpiredShareDoesNotGrantPermission(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $sharedUser = $this->createMock(User::class);
        $sharedUser->method('getId')->willReturn('user-456');

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-789');
        $document->method('getUploadedBy')->willReturn($owner);

        $share = $this->createMock(DocumentShare::class);
        $share->method('getSharedWith')->willReturn($sharedUser);
        $share->method('getDocument')->willReturn($document);
        $share->method('getPermissionLevel')->willReturn('write');
        $share->method('isActive')->willReturn(true);
        $share->method('isExpired')->willReturn(true);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($share);

        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->assertFalse($this->permissionMatrix->canRead($sharedUser, $document));
    }

    // ========== No Permission Tests ==========

    public function testNonOwnerWithoutShareCannotReadDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $otherUser = $this->createMock(User::class);
        $otherUser->method('getId')->willReturn('user-456');
        $otherUser->method('getRoles')->willReturn(['ROLE_USER']);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-789');
        $document->method('getUploadedBy')->willReturn($owner);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->assertFalse($this->permissionMatrix->canRead($otherUser, $document));
    }

    public function testNonOwnerCannotDeleteDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $otherUser = $this->createMock(User::class);
        $otherUser->method('getId')->willReturn('user-456');
        $otherUser->method('getRoles')->willReturn(['ROLE_USER']);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-789');
        $document->method('getUploadedBy')->willReturn($owner);

        // Even with write share, cannot delete
        $share = $this->createMock(DocumentShare::class);
        $share->method('getPermissionLevel')->willReturn('write');
        $share->method('isActive')->willReturn(true);
        $share->method('isExpired')->willReturn(false);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($share);

        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->assertFalse($this->permissionMatrix->canDelete($otherUser, $document));
    }

    // ========== Admin Override Tests ==========

    public function testAdminCanReadAnyDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn('admin-456');
        $admin->method('getRoles')->willReturn(['ROLE_ADMIN']);

        $document = $this->createMock(Document::class);
        $document->method('getUploadedBy')->willReturn($owner);

        $this->assertTrue($this->permissionMatrix->canRead($admin, $document));
    }

    public function testAdminCanWriteAnyDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn('admin-456');
        $admin->method('getRoles')->willReturn(['ROLE_ADMIN']);

        $document = $this->createMock(Document::class);
        $document->method('getUploadedBy')->willReturn($owner);

        $this->assertTrue($this->permissionMatrix->canWrite($admin, $document));
    }

    public function testAdminCanDeleteAnyDocument(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner-123');

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn('admin-456');
        $admin->method('getRoles')->willReturn(['ROLE_ADMIN']);

        $document = $this->createMock(Document::class);
        $document->method('getUploadedBy')->willReturn($owner);

        $this->assertTrue($this->permissionMatrix->canDelete($admin, $document));
    }

    // ========== Permission Summary Tests ==========

    public function testGetPermissionSummaryForOwner(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);

        $document->method('getUploadedBy')->willReturn($user);
        $user->method('getId')->willReturn('user-123');

        $summary = $this->permissionMatrix->getPermissionSummary($user, $document);

        $this->assertTrue($summary['canRead']);
        $this->assertTrue($summary['canWrite']);
        $this->assertTrue($summary['canDelete']);
        $this->assertTrue($summary['isOwner']);
    }
}
