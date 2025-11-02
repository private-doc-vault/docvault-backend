<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Entity\Document;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    private AuditLog $auditLog;

    protected function setUp(): void
    {
        $this->auditLog = new AuditLog();
    }

    public function testAuditLogCanBeInstantiated(): void
    {
        $this->assertInstanceOf(AuditLog::class, $this->auditLog);
    }

    public function testAuditLogHasUuidId(): void
    {
        $this->assertNull($this->auditLog->getId());
        
        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $this->auditLog->setId($uuid);
        $this->assertEquals($uuid, $this->auditLog->getId());
    }

    public function testAuditLogAction(): void
    {
        $action = 'document.view';
        $this->auditLog->setAction($action);
        
        $this->assertEquals($action, $this->auditLog->getAction());
    }

    public function testAuditLogResource(): void
    {
        $resource = 'Document';
        $this->auditLog->setResource($resource);
        
        $this->assertEquals($resource, $this->auditLog->getResource());
    }

    public function testAuditLogResourceId(): void
    {
        $resourceId = 'doc-123-456';
        $this->auditLog->setResourceId($resourceId);
        
        $this->assertEquals($resourceId, $this->auditLog->getResourceId());
    }

    public function testAuditLogDescription(): void
    {
        $this->assertNull($this->auditLog->getDescription());
        
        $description = 'User viewed the financial report document';
        $this->auditLog->setDescription($description);
        
        $this->assertEquals($description, $this->auditLog->getDescription());
    }

    public function testAuditLogLevel(): void
    {
        // Default should be 'info'
        $this->assertEquals('info', $this->auditLog->getLevel());
        
        $this->auditLog->setLevel('warning');
        $this->assertEquals('warning', $this->auditLog->getLevel());
        
        $this->auditLog->setLevel('error');
        $this->assertEquals('error', $this->auditLog->getLevel());
    }

    public function testAuditLogUser(): void
    {
        $this->assertNull($this->auditLog->getUser());
        
        $user = new User();
        $user->setEmail('user@example.com');
        $this->auditLog->setUser($user);
        
        $this->assertEquals($user, $this->auditLog->getUser());
    }

    public function testAuditLogDocument(): void
    {
        $this->assertNull($this->auditLog->getDocument());
        
        $document = new Document();
        $document->setFilename('test.pdf');
        $this->auditLog->setDocument($document);
        
        $this->assertEquals($document, $this->auditLog->getDocument());
    }

    public function testAuditLogIpAddress(): void
    {
        $this->assertNull($this->auditLog->getIpAddress());
        
        $ipAddress = '192.168.1.100';
        $this->auditLog->setIpAddress($ipAddress);
        
        $this->assertEquals($ipAddress, $this->auditLog->getIpAddress());
    }

    public function testAuditLogUserAgent(): void
    {
        $this->assertNull($this->auditLog->getUserAgent());
        
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->auditLog->setUserAgent($userAgent);
        
        $this->assertEquals($userAgent, $this->auditLog->getUserAgent());
    }

    public function testAuditLogMetadata(): void
    {
        // Default should be empty array
        $this->assertEquals([], $this->auditLog->getMetadata());
        
        $metadata = [
            'file_size' => 2048576,
            'processing_time' => 1.25,
            'previous_status' => Document::STATUS_QUEUED
        ];
        
        $this->auditLog->setMetadata($metadata);
        $this->assertEquals($metadata, $this->auditLog->getMetadata());
    }

    public function testAuditLogAddMetadata(): void
    {
        $this->auditLog->addMetadata('key1', 'value1');
        $this->auditLog->addMetadata('key2', 123);
        
        $metadata = $this->auditLog->getMetadata();
        $this->assertEquals('value1', $metadata['key1']);
        $this->assertEquals(123, $metadata['key2']);
    }

    public function testAuditLogGetMetadata(): void
    {
        $metadata = [
            'key1' => 'value1',
            'key2' => 'value2'
        ];
        
        $this->auditLog->setMetadata($metadata);
        
        $this->assertEquals('value1', $this->auditLog->getMetadataValue('key1'));
        $this->assertEquals('value2', $this->auditLog->getMetadataValue('key2'));
        $this->assertNull($this->auditLog->getMetadataValue('nonexistent'));
    }

    public function testAuditLogCreatedAt(): void
    {
        $this->assertNull($this->auditLog->getCreatedAt());
        
        $createdAt = new \DateTimeImmutable();
        $this->auditLog->setCreatedAt($createdAt);
        
        $this->assertEquals($createdAt, $this->auditLog->getCreatedAt());
    }

    public function testAuditLogGetActionType(): void
    {
        $this->auditLog->setAction('document.view');
        $this->assertEquals('document', $this->auditLog->getActionType());
        
        $this->auditLog->setAction('user.create');
        $this->assertEquals('user', $this->auditLog->getActionType());
        
        $this->auditLog->setAction('system.backup');
        $this->assertEquals('system', $this->auditLog->getActionType());
    }

    public function testAuditLogGetActionName(): void
    {
        $this->auditLog->setAction('document.view');
        $this->assertEquals('view', $this->auditLog->getActionName());
        
        $this->auditLog->setAction('user.create');
        $this->assertEquals('create', $this->auditLog->getActionName());
        
        $this->auditLog->setAction('system.backup');
        $this->assertEquals('backup', $this->auditLog->getActionName());
    }

    public function testAuditLogIsLevel(): void
    {
        $this->auditLog->setLevel('info');
        $this->assertTrue($this->auditLog->isLevel('info'));
        $this->assertFalse($this->auditLog->isLevel('warning'));
        
        $this->auditLog->setLevel('error');
        $this->assertTrue($this->auditLog->isLevel('error'));
        $this->assertFalse($this->auditLog->isLevel('info'));
    }

    public function testAuditLogIsInfo(): void
    {
        $this->auditLog->setLevel('info');
        $this->assertTrue($this->auditLog->isInfo());
        
        $this->auditLog->setLevel('warning');
        $this->assertFalse($this->auditLog->isInfo());
    }

    public function testAuditLogIsWarning(): void
    {
        $this->auditLog->setLevel('warning');
        $this->assertTrue($this->auditLog->isWarning());
        
        $this->auditLog->setLevel('info');
        $this->assertFalse($this->auditLog->isWarning());
    }

    public function testAuditLogIsError(): void
    {
        $this->auditLog->setLevel('error');
        $this->assertTrue($this->auditLog->isError());
        
        $this->auditLog->setLevel('info');
        $this->assertFalse($this->auditLog->isError());
    }

    public function testAuditLogValidateAction(): void
    {
        $this->assertTrue(AuditLog::validateAction('document.view'));
        $this->assertTrue(AuditLog::validateAction('user.create'));
        $this->assertTrue(AuditLog::validateAction('system.backup'));
        
        $this->assertFalse(AuditLog::validateAction(''));
        $this->assertFalse(AuditLog::validateAction('invalid'));
        $this->assertFalse(AuditLog::validateAction('document.'));
        $this->assertFalse(AuditLog::validateAction('.view'));
        $this->assertFalse(AuditLog::validateAction('document..view'));
    }

    public function testAuditLogValidateLevel(): void
    {
        $this->assertTrue(AuditLog::validateLevel('info'));
        $this->assertTrue(AuditLog::validateLevel('warning'));
        $this->assertTrue(AuditLog::validateLevel('error'));
        $this->assertTrue(AuditLog::validateLevel('debug'));
        
        $this->assertFalse(AuditLog::validateLevel(''));
        $this->assertFalse(AuditLog::validateLevel('invalid'));
        $this->assertFalse(AuditLog::validateLevel('INFO'));
        $this->assertFalse(AuditLog::validateLevel('critical'));
    }

    public function testAuditLogCreateForDocument(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');
        
        $document = new Document();
        $document->setFilename('test.pdf');
        $document->setId('doc-123');
        
        $auditLog = AuditLog::createForDocument('view', $user, $document, 'User viewed document');
        
        $this->assertEquals('document.view', $auditLog->getAction());
        $this->assertEquals('Document', $auditLog->getResource());
        $this->assertEquals('doc-123', $auditLog->getResourceId());
        $this->assertEquals($user, $auditLog->getUser());
        $this->assertEquals($document, $auditLog->getDocument());
        $this->assertEquals('User viewed document', $auditLog->getDescription());
        $this->assertEquals('info', $auditLog->getLevel());
    }

    public function testAuditLogCreateForUser(): void
    {
        $admin = new User();
        $admin->setEmail('admin@example.com');
        
        $targetUser = new User();
        $targetUser->setEmail('user@example.com');
        $targetUser->setId('user-456');
        
        $auditLog = AuditLog::createForUser('create', $admin, $targetUser, 'Admin created new user');
        
        $this->assertEquals('user.create', $auditLog->getAction());
        $this->assertEquals('User', $auditLog->getResource());
        $this->assertEquals('user-456', $auditLog->getResourceId());
        $this->assertEquals($admin, $auditLog->getUser());
        $this->assertEquals('Admin created new user', $auditLog->getDescription());
        $this->assertEquals('info', $auditLog->getLevel());
    }

    public function testAuditLogCreateSystemLog(): void
    {
        $auditLog = AuditLog::createSystemLog('backup', 'System backup completed successfully');
        
        $this->assertEquals('system.backup', $auditLog->getAction());
        $this->assertEquals('System', $auditLog->getResource());
        $this->assertNull($auditLog->getResourceId());
        $this->assertNull($auditLog->getUser());
        $this->assertEquals('System backup completed successfully', $auditLog->getDescription());
        $this->assertEquals('info', $auditLog->getLevel());
    }

    public function testAuditLogToString(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');
        
        $this->auditLog->setAction('document.view');
        $this->auditLog->setUser($user);
        $this->auditLog->setDescription('User viewed document');
        
        $expected = 'document.view by user@example.com: User viewed document';
        $this->assertEquals($expected, (string) $this->auditLog);
    }

    public function testAuditLogToStringWithoutUser(): void
    {
        $this->auditLog->setAction('system.backup');
        $this->auditLog->setDescription('System backup completed');
        
        $expected = 'system.backup: System backup completed';
        $this->assertEquals($expected, (string) $this->auditLog);
    }

    public function testAuditLogToStringWithoutDescription(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');
        
        $this->auditLog->setAction('document.view');
        $this->auditLog->setUser($user);
        
        $expected = 'document.view by user@example.com';
        $this->assertEquals($expected, (string) $this->auditLog);
    }
}