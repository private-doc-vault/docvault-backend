<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add progress tracking fields to documents table
 * Task 5.3: Add progress field to Document entity
 */
final class Version20250122000000AddProgressToDocuments extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add progress and current_operation fields to documents table for progress tracking';
    }

    public function up(Schema $schema): void
    {
        // Add progress field (0-100)
        $this->addSql('ALTER TABLE documents ADD COLUMN progress INTEGER NULL');

        // Add current_operation field
        $this->addSql('ALTER TABLE documents ADD COLUMN current_operation VARCHAR(255) NULL');

        // Add comment for documentation
        $this->addSql('COMMENT ON COLUMN documents.progress IS \'Processing progress percentage (0-100)\'');
        $this->addSql('COMMENT ON COLUMN documents.current_operation IS \'Current processing operation description\'');
    }

    public function down(Schema $schema): void
    {
        // Remove the columns
        $this->addSql('ALTER TABLE documents DROP COLUMN current_operation');
        $this->addSql('ALTER TABLE documents DROP COLUMN progress');
    }
}
