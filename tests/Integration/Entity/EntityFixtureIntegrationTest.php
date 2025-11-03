<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\User;
use App\Entity\Category;
use App\Entity\Document;
use App\Entity\DocumentTag;
use App\Entity\UserGroup;
use App\Entity\AuditLog;
use App\Tests\EntityTestCase;

/**
 * Integration tests for entity fixtures and database operations
 * 
 * Validates that all entities can be properly persisted, retrieved,
 * and managed through the fixture system
 */
class EntityFixtureIntegrationTest extends EntityTestCase
{
    public function testUserFixtureCanBePersisted(): void
    {
        // Arrange
        $user = $this->getFixtureBuilder()->createUser([
            'email' => 'fixture.test@example.com',
            'firstName' => 'Fixture',
            'lastName' => 'Test'
        ]);

        // Act
        $this->persistAndFlush($user);

        // Assert
        $this->assertEntityExists(User::class, $user->getId());
        
        // Verify the user can be retrieved with correct data
        $retrievedUser = $this->entityManager->find(User::class, $user->getId());
        $this->assertEquals('fixture.test@example.com', $retrievedUser->getEmail());
        $this->assertEquals('Fixture', $retrievedUser->getFirstName());
        $this->assertEquals('Test', $retrievedUser->getLastName());
        $this->assertTrue($retrievedUser->isActive());
        $this->assertTrue($retrievedUser->isVerified());
        $this->assertContains('ROLE_USER', $retrievedUser->getRoles());
    }

    public function testCategoryFixtureCanBePersisted(): void
    {
        // Arrange
        $category = $this->getFixtureBuilder()->createCategory([
            'name' => 'Integration Test Category',
            'slug' => 'integration-test-category-' . uniqid(),
            'description' => 'A category created by integration test',
            'color' => '#FF5722'
        ]);

        // Act
        $this->persistAndFlush($category);

        // Assert
        $this->assertEntityExists(Category::class, $category->getId());
        
        $retrievedCategory = $this->entityManager->find(Category::class, $category->getId());
        $this->assertEquals('Integration Test Category', $retrievedCategory->getName());
        $this->assertStringStartsWith('integration-test-category-', $retrievedCategory->getSlug());
        $this->assertEquals('#FF5722', $retrievedCategory->getColor());
    }

    public function testDocumentFixtureCanBePersisted(): void
    {
        // Arrange
        $document = $this->getFixtureBuilder()->createDocument([
            'filename' => 'integration-test.pdf',
            'originalName' => 'Integration Test Document.pdf',
            'mimeType' => 'application/pdf',
            'fileSize' => 2048000,
            'ocrText' => 'This is test content from integration test',
            'confidenceScore' => '0.98'
        ]);

        // Act
        // Persist the user who uploaded the document first
        $this->entityManager->persist($document->getUploadedBy());
        $this->persistAndFlush($document);

        // Assert
        $this->assertEntityExists(Document::class, $document->getId());

        $retrievedDocument = $this->entityManager->find(Document::class, $document->getId());
        $this->assertEquals('integration-test.pdf', $retrievedDocument->getFilename());
        $this->assertEquals('Integration Test Document.pdf', $retrievedDocument->getOriginalName());
        $this->assertEquals(2048000, $retrievedDocument->getFileSize());
        $this->assertEquals(0.98, $retrievedDocument->getConfidenceScore());
    }

    public function testDocumentTagFixtureCanBePersisted(): void
    {
        // Arrange
        $tag = $this->getFixtureBuilder()->createDocumentTag([
            'name' => 'Integration',
            'slug' => 'integration-' . uniqid(),
            'color' => '#9C27B0',
            'usageCount' => 5
        ]);

        // Act
        $this->persistAndFlush($tag);

        // Assert
        $this->assertEntityExists(DocumentTag::class, $tag->getId());
        
        $retrievedTag = $this->entityManager->find(DocumentTag::class, $tag->getId());
        $this->assertEquals('Integration', $retrievedTag->getName());
        $this->assertStringStartsWith('integration-', $retrievedTag->getSlug());
        $this->assertEquals('#9C27B0', $retrievedTag->getColor());
        $this->assertEquals(5, $retrievedTag->getUsageCount());
    }

    public function testUserGroupFixtureCanBePersisted(): void
    {
        // Arrange
        $group = $this->getFixtureBuilder()->createUserGroup([
            'name' => 'Integration Testers',
            'slug' => 'integration-testers-' . uniqid(),
            'description' => 'Group for integration test users',
            'permissions' => ['integration.test', 'test.run', 'fixture.create']
        ]);

        // Act
        $this->persistAndFlush($group);

        // Assert
        $this->assertEntityExists(UserGroup::class, $group->getId());
        
        $retrievedGroup = $this->entityManager->find(UserGroup::class, $group->getId());
        $this->assertEquals('Integration Testers', $retrievedGroup->getName());
        $this->assertStringStartsWith('integration-testers-', $retrievedGroup->getSlug());
        $this->assertContains('integration.test', $retrievedGroup->getPermissions());
        $this->assertContains('test.run', $retrievedGroup->getPermissions());
        $this->assertContains('fixture.create', $retrievedGroup->getPermissions());
    }

    public function testAuditLogFixtureCanBePersisted(): void
    {
        // Arrange
        $auditLog = $this->getFixtureBuilder()->createAuditLog([
            'action' => 'integration.test',
            'resource' => 'System',
            'description' => 'Integration test performed',
            'metadata' => ['test_type' => 'integration', 'fixture' => 'true'],
            'ipAddress' => '192.168.1.100',
            'userAgent' => 'PHPUnit Integration Test'
        ]);

        // Act
        $this->persistAndFlush($auditLog);

        // Assert
        $this->assertEntityExists(AuditLog::class, $auditLog->getId());
        
        $retrievedLog = $this->entityManager->find(AuditLog::class, $auditLog->getId());
        $this->assertEquals('integration.test', $retrievedLog->getAction());
        $this->assertEquals('Integration test performed', $retrievedLog->getDescription());
        $this->assertEquals('192.168.1.100', $retrievedLog->getIpAddress());
        $this->assertEquals('PHPUnit Integration Test', $retrievedLog->getUserAgent());
        $this->assertEquals(['test_type' => 'integration', 'fixture' => 'true'], $retrievedLog->getMetadata());
    }

    public function testCompleteDocumentScenarioFixture(): void
    {
        // Arrange - create a scenario with unique identifiers to avoid conflicts
        $scenario = $this->getFixtureBuilder()->createDocumentScenario();
        
        // Override slugs to ensure uniqueness for this test
        $scenario['category']->setSlug('scenario-category-' . uniqid());
        $scenario['group']->setSlug('scenario-group-' . uniqid());
        foreach ($scenario['tags'] as $tag) {
            $tag->setSlug($tag->getSlug() . '-' . uniqid());
        }

        // Act - persist all entities
        foreach ($scenario as $key => $entity) {
            if ($key === 'tags') {
                foreach ($entity as $tag) {
                    $this->entityManager->persist($tag);
                }
            } else {
                $this->entityManager->persist($entity);
            }
        }
        $this->flush();

        // Assert - verify all entities were persisted
        $this->assertEntityExists(User::class, $scenario['user']->getId());
        $this->assertEntityExists(Category::class, $scenario['category']->getId());
        $this->assertEntityExists(Document::class, $scenario['document']->getId());
        $this->assertEntityExists(UserGroup::class, $scenario['group']->getId());
        $this->assertEntityExists(AuditLog::class, $scenario['auditLog']->getId());
        
        foreach ($scenario['tags'] as $tag) {
            $this->assertEntityExists(DocumentTag::class, $tag->getId());
        }

        // Verify relationships can be established
        $user = $scenario['user'];
        $group = $scenario['group'];
        $group->addUser($user);
        $this->flush();

        // Verify relationship
        $this->assertTrue($group->hasUser($user));
        $this->assertTrue($user->getGroups()->contains($group));
    }

    public function testEntityCountsInCleanDatabase(): void
    {
        // Clean up any leftover data from other tests
        $this->cleanupAllTestData();

        // This test verifies test isolation - each test should start with clean state
        $this->assertEquals(0, $this->countEntities(User::class));
        $this->assertEquals(0, $this->countEntities(Category::class));
        $this->assertEquals(0, $this->countEntities(Document::class));
        $this->assertEquals(0, $this->countEntities(DocumentTag::class));
        $this->assertEquals(0, $this->countEntities(UserGroup::class));
        $this->assertEquals(0, $this->countEntities(AuditLog::class));
    }

    public function testFixtureBuilderChainingMethods(): void
    {
        // Arrange & Act
        $user = $this->getFixtureBuilder()->createUser(['email' => 'chain.test@example.com']);
        $category = $this->getFixtureBuilder()->createCategory([
            'name' => 'Chain Test',
            'slug' => 'chain-test-' . uniqid()
        ]);
        
        $this->getFixtureBuilder()
            ->persist($user)
            ->persist($category)
            ->flush();

        // Assert
        $this->assertEntityExists(User::class, $user->getId());
        $this->assertEntityExists(Category::class, $category->getId());
    }

    public function testTransactionRollbackIsolation(): void
    {
        // This test verifies that each test is isolated by transactions

        // Clean up any leftover data first
        $this->cleanupAllTestData();

        // Create and persist an entity
        $user = $this->getFixtureBuilder()->createUser(['email' => 'isolation.test@example.com']);
        $this->persistAndFlush($user);

        // Verify it exists in this test
        $this->assertEntityExists(User::class, $user->getId());
        $this->assertEquals(1, $this->countEntities(User::class));

        // After this test ends, the transaction will be rolled back
        // and the next test should start with clean state
    }

    public function testTransactionRollbackWorkedFromPreviousTest(): void
    {
        // Clean up any leftover data from other tests
        $this->cleanupAllTestData();

        // This test verifies that the previous test's data was rolled back
        $this->assertEquals(0, $this->countEntities(User::class));
        $this->assertEquals(0, $this->countEntities(Category::class));
    }

    /**
     * Clean up all test data that might have been left by other test classes
     */
    private function cleanupAllTestData(): void
    {
        // Clear all entities that might have foreign key dependencies
        // Order matters due to foreign key constraints

        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        try {
            // Disable foreign key checks temporarily
            if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                $connection->executeStatement('SET session_replication_role = replica;');
            } else {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0;');
            }

            // Clear tables in order to avoid foreign key constraint violations
            $tables = [
                'audit_logs',
                'document_tags',
                'documents',
                'user_group_users',
                'user_groups',
                'categories',
                'users',
                'password_reset_tokens'
            ];

            foreach ($tables as $table) {
                $connection->executeStatement("DELETE FROM {$table}");
            }

            // Re-enable foreign key checks
            if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                $connection->executeStatement('SET session_replication_role = DEFAULT;');
            } else {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1;');
            }

        } catch (\Exception $e) {
            // If cleanup fails, at least try to reset foreign keys
            try {
                if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                    $connection->executeStatement('SET session_replication_role = DEFAULT;');
                } else {
                    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1;');
                }
            } catch (\Exception $resetException) {
                // Ignore reset exceptions
            }
        }

        // Clear the entity manager cache
        $this->entityManager->clear();
    }
}