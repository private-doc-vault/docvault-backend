<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\User;
use App\Entity\Category;
use App\Entity\Document;
use App\Entity\DocumentTag;
use App\Entity\UserGroup;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fixture builder for creating consistent test data
 * 
 * Provides factory methods for creating test entities with realistic data
 */
class EntityFixtureBuilder
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    /**
     * Create a test user with default or custom properties
     */
    public function createUser(array $overrides = []): User
    {
        $defaults = [
            'id' => Uuid::uuid4()->toString(),
            'email' => 'test.user@example.com',
            'password' => 'password123',
            'firstName' => 'Test',
            'lastName' => 'User',
            'isActive' => true,
            'isVerified' => true,
            'roles' => ['ROLE_USER'],
            'preferences' => [
                'theme' => 'light',
                'language' => 'en',
                'notifications' => true
            ],
            'createdAt' => new \DateTimeImmutable(),
            'updatedAt' => new \DateTimeImmutable()
        ];

        $data = array_merge($defaults, $overrides);
        
        $user = new User();
        $user->setId($data['id']);
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setRoles($data['roles']);
        $user->setIsActive($data['isActive']);
        $user->setIsVerified($data['isVerified']);
        $user->setPreferences($data['preferences']);
        $user->setCreatedAt($data['createdAt']);
        $user->setUpdatedAt($data['updatedAt']);

        // Hash the password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        return $user;
    }

    /**
     * Create a test category with default or custom properties
     */
    public function createCategory(array $overrides = []): Category
    {
        $defaults = [
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Test Category',
            'slug' => 'test-category',
            'description' => 'A test category for unit testing',
            'color' => '#3B82F6',
            'icon' => 'folder',
            'parent' => null,
            'createdAt' => new \DateTimeImmutable(),
            'updatedAt' => new \DateTimeImmutable()
        ];

        $data = array_merge($defaults, $overrides);
        
        $category = new Category();
        $category->setId($data['id']);
        $category->setName($data['name']);
        $category->setSlug($data['slug']);
        $category->setDescription($data['description']);
        $category->setColor($data['color']);
        $category->setIcon($data['icon']);
        $category->setParent($data['parent']);
        $category->setCreatedAt($data['createdAt']);
        $category->setUpdatedAt($data['updatedAt']);

        return $category;
    }

    /**
     * Create a test document with default or custom properties
     */
    public function createDocument(array $overrides = []): Document
    {
        // If uploadedBy is not provided, create a default user
        if (!isset($overrides['uploadedBy'])) {
            $overrides['uploadedBy'] = $this->createUser([
                'email' => 'document.uploader@example.com',
            ]);
        }

        $defaults = [
            'id' => Uuid::uuid4()->toString(),
            'filename' => 'test-document.pdf',
            'originalName' => 'Test Document.pdf',
            'filePath' => '/storage/documents/test-document.pdf',
            'mimeType' => 'application/pdf',
            'fileSize' => 1024000, // 1MB
            'ocrText' => 'This is test OCR content extracted from the document.',
            'confidenceScore' => 0.95,
            'processingStatus' => 'completed',
            'versionNumber' => 1,
            'archived' => false,
            'searchableContent' => 'test document content searchable',
            'language' => 'en',
            'metadata' => ['type' => 'test', 'source' => 'unit-test'],
            'createdAt' => new \DateTimeImmutable(),
            'updatedAt' => new \DateTimeImmutable()
        ];

        $data = array_merge($defaults, $overrides);

        $document = new Document();
        $document->setId($data['id']);
        $document->setFilename($data['filename']);
        $document->setOriginalName($data['originalName']);
        $document->setFilePath($data['filePath']);
        $document->setMimeType($data['mimeType']);
        $document->setFileSize($data['fileSize']);
        $document->setOcrText($data['ocrText']);
        $document->setConfidenceScore($data['confidenceScore']);
        $document->setProcessingStatus($data['processingStatus']);
        $document->setVersionNumber($data['versionNumber']);
        $document->setArchived($data['archived']);
        $document->setSearchableContent($data['searchableContent']);
        $document->setLanguage($data['language']);
        $document->setMetadata($data['metadata']);
        $document->setCreatedAt($data['createdAt']);
        $document->setUpdatedAt($data['updatedAt']);
        $document->setUploadedBy($data['uploadedBy']);

        return $document;
    }

    /**
     * Create a test document tag with default or custom properties
     */
    public function createDocumentTag(array $overrides = []): DocumentTag
    {
        $defaults = [
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Test Tag',
            'slug' => 'test-tag',
            'color' => '#EF4444',
            'usageCount' => 0,
            'createdAt' => new \DateTimeImmutable(),
            'updatedAt' => new \DateTimeImmutable()
        ];

        $data = array_merge($defaults, $overrides);
        
        $tag = new DocumentTag();
        $tag->setId($data['id']);
        $tag->setName($data['name']);
        $tag->setSlug($data['slug']);
        $tag->setColor($data['color']);
        $tag->setUsageCount($data['usageCount']);
        $tag->setCreatedAt($data['createdAt']);
        $tag->setUpdatedAt($data['updatedAt']);

        return $tag;
    }

    /**
     * Create a test user group with default or custom properties
     */
    public function createUserGroup(array $overrides = []): UserGroup
    {
        $defaults = [
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Test Group',
            'slug' => 'test-group',
            'description' => 'A test user group for unit testing',
            'permissions' => ['view.documents', 'create.documents'],
            'isActive' => true,
            'isSystem' => false,
            'createdAt' => new \DateTimeImmutable(),
            'updatedAt' => new \DateTimeImmutable()
        ];

        $data = array_merge($defaults, $overrides);
        
        $group = new UserGroup();
        $group->setId($data['id']);
        $group->setName($data['name']);
        $group->setSlug($data['slug']);
        $group->setDescription($data['description']);
        $group->setPermissions($data['permissions']);
        $group->setIsActive($data['isActive']);
        $group->setIsSystem($data['isSystem']);
        $group->setCreatedAt($data['createdAt']);
        $group->setUpdatedAt($data['updatedAt']);

        return $group;
    }

    /**
     * Create a test audit log with default or custom properties
     */
    public function createAuditLog(array $overrides = []): AuditLog
    {
        $defaults = [
            'id' => Uuid::uuid4()->toString(),
            'action' => 'document.view',
            'resource' => 'Document',
            'description' => 'User viewed a test document',
            'metadata' => ['test' => true, 'source' => 'unit-test'],
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'PHPUnit Test Suite',
            'createdAt' => new \DateTimeImmutable()
        ];

        $data = array_merge($defaults, $overrides);
        
        $auditLog = new AuditLog();
        $auditLog->setId($data['id']);
        $auditLog->setAction($data['action']);
        $auditLog->setResource($data['resource']);
        $auditLog->setDescription($data['description']);
        $auditLog->setMetadata($data['metadata']);
        $auditLog->setIpAddress($data['ipAddress']);
        $auditLog->setUserAgent($data['userAgent']);
        $auditLog->setCreatedAt($data['createdAt']);

        return $auditLog;
    }

    /**
     * Create a complete test scenario with related entities
     */
    public function createDocumentScenario(): array
    {
        // Create a user
        $user = $this->createUser([
            'email' => 'scenario.user@example.com',
            'firstName' => 'Scenario',
            'lastName' => 'User'
        ]);

        // Create a category
        $category = $this->createCategory([
            'name' => 'Scenario Category',
            'slug' => 'scenario-category'
        ]);

        // Create tags
        $tag1 = $this->createDocumentTag([
            'name' => 'Important',
            'slug' => 'important',
            'color' => '#EF4444'
        ]);

        $tag2 = $this->createDocumentTag([
            'name' => 'Reviewed',
            'slug' => 'reviewed',
            'color' => '#10B981'
        ]);

        // Create a document with the scenario user
        $document = $this->createDocument([
            'filename' => 'scenario-document.pdf',
            'originalName' => 'Scenario Document.pdf',
            'uploadedBy' => $user
        ]);

        // Create user group
        $group = $this->createUserGroup([
            'name' => 'Scenario Group',
            'slug' => 'scenario-group',
            'permissions' => ['view.documents', 'edit.documents', 'create.documents']
        ]);

        // Create audit log
        $auditLog = $this->createAuditLog([
            'action' => 'document.create',
            'resource' => 'Document',
            'description' => 'User created scenario document'
        ]);

        return [
            'user' => $user,
            'category' => $category,
            'document' => $document,
            'tags' => [$tag1, $tag2],
            'group' => $group,
            'auditLog' => $auditLog
        ];
    }

    /**
     * Persist an entity to the test database
     */
    public function persist($entity): self
    {
        $this->entityManager->persist($entity);
        return $this;
    }

    /**
     * Flush all persisted entities
     */
    public function flush(): self
    {
        $this->entityManager->flush();
        return $this;
    }

    /**
     * Clear the entity manager
     */
    public function clear(): self
    {
        $this->entityManager->clear();
        return $this;
    }
}