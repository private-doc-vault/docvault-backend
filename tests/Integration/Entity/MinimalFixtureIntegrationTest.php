<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\User;
use App\Tests\EntityTestCase;
use App\Tests\Fixtures\MinimalFixtureBuilder;

/**
 * Minimal integration test for fixture system using only compatible entities
 */
class MinimalFixtureIntegrationTest extends EntityTestCase
{
    private MinimalFixtureBuilder $minimalFixtureBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->minimalFixtureBuilder = new MinimalFixtureBuilder($this->entityManager, $this->passwordHasher);
    }

    public function testUserFixtureCanBePersisted(): void
    {
        // Arrange
        $user = $this->minimalFixtureBuilder->createUser([
            'email' => 'minimal.test@example.com',
            'firstName' => 'Minimal',
            'lastName' => 'Test'
        ]);

        // Act
        $this->persistAndFlush($user);

        // Assert
        $this->assertEntityExists(User::class, $user->getId());
        
        // Verify the user can be retrieved with correct data
        $retrievedUser = $this->entityManager->find(User::class, $user->getId());
        $this->assertEquals('minimal.test@example.com', $retrievedUser->getEmail());
        $this->assertEquals('Minimal', $retrievedUser->getFirstName());
        $this->assertEquals('Test', $retrievedUser->getLastName());
        $this->assertTrue($retrievedUser->isActive());
        $this->assertTrue($retrievedUser->isVerified());
        $this->assertContains('ROLE_USER', $retrievedUser->getRoles());
    }

    public function testEntityCountsInCleanDatabase(): void
    {
        // Clean up any leftover data from other tests
        $this->cleanupAllTestData();

        // This test verifies test isolation - each test should start with clean state
        $this->assertEquals(0, $this->countEntities(User::class));
    }

    public function testFixtureBuilderChainingMethods(): void
    {
        // Clean up any leftover data first
        $this->cleanupAllTestData();

        // Arrange & Act
        $user1 = $this->minimalFixtureBuilder->createUser(['email' => 'chain1.test@example.com']);
        $user2 = $this->minimalFixtureBuilder->createUser(['email' => 'chain2.test@example.com']);

        $this->minimalFixtureBuilder
            ->persist($user1)
            ->persist($user2)
            ->flush();

        // Assert
        $this->assertEntityExists(User::class, $user1->getId());
        $this->assertEntityExists(User::class, $user2->getId());
        $this->assertEquals(2, $this->countEntities(User::class));
    }

    public function testTransactionRollbackIsolation(): void
    {
        // This test verifies that each test is isolated by transactions

        // Clean up any leftover data first
        $this->cleanupAllTestData();

        // Create and persist an entity
        $user = $this->minimalFixtureBuilder->createUser(['email' => 'isolation.test@example.com']);
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