<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Fixtures\EntityFixtureBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base test case for entity testing with fixture support
 * 
 * Provides database setup, transaction management, and fixture builder
 */
abstract class EntityTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;
    protected EntityFixtureBuilder $fixtureBuilder;
    protected UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->fixtureBuilder = new EntityFixtureBuilder($this->entityManager, $this->passwordHasher);

        // Start a transaction for test isolation
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        // Clear the entity manager
        $this->entityManager->clear();

        parent::tearDown();
    }

    /**
     * Get the fixture builder for creating test entities
     */
    protected function getFixtureBuilder(): EntityFixtureBuilder
    {
        return $this->fixtureBuilder;
    }

    /**
     * Persist and flush an entity for testing
     */
    protected function persistAndFlush($entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * Flush all pending changes
     */
    protected function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * Clear the entity manager cache
     */
    protected function clear(): void
    {
        $this->entityManager->clear();
    }

    /**
     * Refresh an entity from the database
     */
    protected function refresh($entity): void
    {
        $this->entityManager->refresh($entity);
    }

    /**
     * Assert that an entity exists in the database
     */
    protected function assertEntityExists(string $entityClass, $id): void
    {
        $entity = $this->entityManager->find($entityClass, $id);
        $this->assertNotNull($entity, "Entity {$entityClass} with ID {$id} should exist in database");
    }

    /**
     * Assert that an entity does not exist in the database
     */
    protected function assertEntityNotExists(string $entityClass, $id): void
    {
        $entity = $this->entityManager->find($entityClass, $id);
        $this->assertNull($entity, "Entity {$entityClass} with ID {$id} should not exist in database");
    }

    /**
     * Count entities of a given type
     */
    protected function countEntities(string $entityClass): int
    {
        return $this->entityManager->getRepository($entityClass)->count([]);
    }

    /**
     * Create a test scenario with related entities
     * Override this method in specific test classes for custom scenarios
     */
    protected function createTestScenario(): array
    {
        return $this->fixtureBuilder->createDocumentScenario();
    }
}