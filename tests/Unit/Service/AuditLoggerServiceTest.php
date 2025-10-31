<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\User;
use App\Service\AuditLoggerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for AuditLoggerService
 *
 * Tests cover:
 * - Logging document actions
 * - Logging user actions
 * - Logging authentication events
 * - Logging system events
 * - Request context extraction
 */
class AuditLoggerServiceTest extends TestCase
{
    private AuditLoggerService $auditLogger;
    private EntityManagerInterface $entityManager;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->auditLogger = new AuditLoggerService($this->entityManager, $this->requestStack);
    }

    // ========== Document Action Logging Tests ==========

    public function testLogDocumentView(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-123');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'document.view'
                    && $log->getResource() === 'Document'
                    && $log->getLevel() === 'info';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logDocumentView($user, $document);
    }

    public function testLogDocumentUpload(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-123');
        $document->method('getOriginalName')->willReturn('test.pdf');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'document.upload';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logDocumentUpload($user, $document);
    }

    public function testLogDocumentDelete(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-123');
        $document->method('getOriginalName')->willReturn('test.pdf');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'document.delete'
                    && $log->getLevel() === 'warning';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logDocumentDelete($user, $document);
    }

    public function testLogDocumentShare(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);
        $sharedWith = $this->createMock(User::class);
        $sharedWith->method('getEmail')->willReturn('recipient@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) use ($sharedWith) {
                $metadata = $log->getMetadata();
                return $log->getAction() === 'document.share'
                    && isset($metadata['shared_with_email']);
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logDocumentShare($user, $document, $sharedWith, 'view');
    }

    // ========== User Action Logging Tests ==========

    public function testLogUserLogin(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'user.login'
                    && $log->getLevel() === 'info';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logUserLogin($user);
    }

    public function testLogUserLogout(): void
    {
        $user = $this->createMock(User::class);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'user.logout';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logUserLogout($user);
    }

    public function testLogFailedLogin(): void
    {
        $email = 'test@example.com';

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) use ($email) {
                $metadata = $log->getMetadata();
                return $log->getAction() === 'user.login_failed'
                    && $log->getLevel() === 'warning'
                    && $metadata['email'] === $email;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logFailedLogin($email);
    }

    public function testLogUserCreated(): void
    {
        $admin = $this->createMock(User::class);
        $newUser = $this->createMock(User::class);
        $newUser->method('getEmail')->willReturn('newuser@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'user.created';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logUserCreated($admin, $newUser);
    }

    // ========== Group Action Logging Tests ==========

    public function testLogGroupCreated(): void
    {
        $user = $this->createMock(User::class);
        $groupName = 'Editors';

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) use ($groupName) {
                $metadata = $log->getMetadata();
                return $log->getAction() === 'group.created'
                    && $metadata['group_name'] === $groupName;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logGroupCreated($user, $groupName);
    }

    public function testLogUserAddedToGroup(): void
    {
        $admin = $this->createMock(User::class);
        $targetUser = $this->createMock(User::class);
        $targetUser->method('getEmail')->willReturn('user@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'group.user_added';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logUserAddedToGroup($admin, $targetUser, 'Editors');
    }

    // ========== Request Context Tests ==========

    public function testRequestContextIsAddedWhenAvailable(): void
    {
        $user = $this->createMock(User::class);
        $document = $this->createMock(Document::class);

        // Create a real Request object with headers
        $request = Request::create('/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0'
        ]);

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getIpAddress() === '192.168.1.1'
                    && $log->getUserAgent() === 'Mozilla/5.0';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logDocumentView($user, $document);
    }

    // ========== Generic Logging Tests ==========

    public function testLogCustomAction(): void
    {
        $user = $this->createMock(User::class);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'custom.action'
                    && $log->getDescription() === 'Custom description';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->log('custom.action', $user, 'Custom', null, 'Custom description');
    }

    public function testLogSystemEvent(): void
    {
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'system.startup'
                    && $log->getUser() === null;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->logSystemEvent('startup', 'Application started');
    }
}
