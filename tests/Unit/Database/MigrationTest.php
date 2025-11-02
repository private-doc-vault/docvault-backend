<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test database migrations functionality
 * 
 * Tests migration creation, execution, rollback, and schema validation
 * following Test-Driven Development approach.
 */
class MigrationTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->connection = $this->entityManager->getConnection();
        
        // Note: We don't clean up the database in setUp for migration tests
        // as we want to test the migrated schema
    }

    protected function tearDown(): void
    {
        // Note: We don't clean up after migration tests
        // as this would interfere with testing the schema
        parent::tearDown();
    }

    /**
     * Clean up test database by dropping all tables except migration metadata
     */
    private function cleanupTestDatabase(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $tables = $schemaManager->listTableNames();
        
        foreach ($tables as $table) {
            if ($table !== 'doctrine_migration_versions') {
                try {
                    $this->connection->executeStatement("DROP TABLE IF EXISTS $table CASCADE");
                } catch (DBALException $e) {
                    // Ignore if table doesn't exist
                }
            }
        }
    }

    /**
     * Test that database connection is working
     */
    public function testDatabaseConnection(): void
    {
        $this->assertInstanceOf(Connection::class, $this->connection);
        // Test connection by executing a simple query
        $result = $this->connection->executeQuery('SELECT 1 as test')->fetchAssociative();
        $this->assertEquals(['test' => 1], $result);
    }

    /**
     * Test that all required entity tables can be created (will initially fail)
     */
    public function testRequiredTablesExistAfterMigration(): void
    {
        // This test will fail initially (RED) until we create the migration
        $expectedTables = [
            'users',
            'documents', 
            'categories',
            'document_tags',
            'user_groups',
            'audit_logs',
            'document_document_tags', // Many-to-many join table (actual name)
            'user_group_users' // Many-to-many join table (actual name)
        ];

        // Check if migrations have been run (tables exist)
        $schemaManager = $this->connection->createSchemaManager();
        $actualTables = $schemaManager->listTableNames();

        // Verify all expected tables exist after migration

        foreach ($expectedTables as $expectedTable) {
            $this->assertContains(
                $expectedTable, 
                $actualTables, 
                "Table '$expectedTable' should exist after migration. Run migrations first: bin/console doctrine:migrations:migrate"
            );
        }
    }

    /**
     * Test that User table has correct structure
     */
    public function testUserTableStructure(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        
        if (!$schemaManager->tablesExist(['users'])) {
            $this->markTestSkipped('User table does not exist yet - migration needed');
        }

        $userTable = $schemaManager->introspectTable('users');
        $columns = $userTable->getColumns();

        // Required columns
        $requiredColumns = [
            'id',
            'email',
            'password',
            'roles',
            'is_verified',
            'preferences',
            'created_at',
            'updated_at'
        ];

        // Get column names from Column objects
        $actualColumnNames = [];
        foreach ($columns as $column) {
            $actualColumnNames[] = $column->getName();
        }
        
        foreach ($requiredColumns as $columnName) {
            $this->assertContains(
                $columnName, 
                $actualColumnNames, 
                "Column '$columnName' should exist in users table. Available: " . implode(', ', $actualColumnNames)
            );
        }

        // Check for unique constraints
        $indexes = $userTable->getIndexes();
        $uniqueEmailExists = false;
        foreach ($indexes as $index) {
            $columnNames = array_map(fn($col) => $col->getColumnName()->toString(), $index->getIndexedColumns());

            if ($index->getType() === \Doctrine\DBAL\Schema\Index\IndexType::UNIQUE && in_array('email', $columnNames)) {
                $uniqueEmailExists = true;
                break;
            }
        }
        $this->assertTrue($uniqueEmailExists, 'Email should have unique constraint');
    }

    /**
     * Test that Document table has correct structure
     */
    public function testDocumentTableStructure(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        
        if (!$schemaManager->tablesExist(['documents'])) {
            $this->markTestSkipped('Document table does not exist yet - migration needed');
        }

        $documentTable = $schemaManager->introspectTable('documents');
        $columns = $documentTable->getColumns();

        $requiredColumns = [
            'id',
            'filename',
            'original_name',
            'file_path',
            'mime_type',
            'file_size',
            'ocr_text',
            'confidence_score',
            'processing_status',
            'version_number',
            'created_at',
            'updated_at',
            'category_id',
            'searchable_content',
            'metadata'
        ];

        // Get column names from Column objects
        $actualColumnNames = [];
        foreach ($columns as $column) {
            $actualColumnNames[] = $column->getName();
        }
        
        foreach ($requiredColumns as $columnName) {
            $this->assertContains(
                $columnName, 
                $actualColumnNames, 
                "Column '$columnName' should exist in documents table. Available: " . implode(', ', $actualColumnNames)
            );
        }

        // Check foreign keys
        $foreignKeys = $documentTable->getForeignKeys();
        $this->assertGreaterThan(0, count($foreignKeys), 'Document table should have foreign keys');
    }

    /**
     * Test that Category table supports hierarchical structure
     */
    public function testCategoryTableHierarchicalStructure(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        
        if (!$schemaManager->tablesExist(['categories'])) {
            $this->markTestSkipped('Category table does not exist yet - migration needed');
        }

        $categoryTable = $schemaManager->introspectTable('categories');
        $columns = $categoryTable->getColumns();

        $requiredColumns = [
            'id',
            'name',
            'slug',
            'description',
            'color',
            'icon',
            'parent_id', // Self-referencing for hierarchy
            'created_at',
            'updated_at'
        ];

        // Get column names from Column objects
        $actualColumnNames = [];
        $columnsByName = [];
        foreach ($columns as $column) {
            $actualColumnNames[] = $column->getName();
            $columnsByName[$column->getName()] = $column;
        }
        
        foreach ($requiredColumns as $columnName) {
            $this->assertContains(
                $columnName, 
                $actualColumnNames, 
                "Column '$columnName' should exist in categories table. Available: " . implode(', ', $actualColumnNames)
            );
        }

        // Check that parent_id can be null (for root categories)
        $parentIdColumn = $columnsByName['parent_id'];
        $this->assertFalse($parentIdColumn->getNotnull(), 'parent_id should allow NULL values');
    }
}