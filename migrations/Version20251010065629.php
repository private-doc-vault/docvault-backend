<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251010065629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create document_shares table for document sharing functionality';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_shares (id VARCHAR(36) NOT NULL, permission_level VARCHAR(10) NOT NULL, is_active BOOLEAN NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, note TEXT DEFAULT NULL, access_count INT NOT NULL, accessed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, document_id VARCHAR(36) NOT NULL, shared_with_id VARCHAR(36) NOT NULL, shared_by_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2E3DC01AC33F7837 ON document_shares (document_id)');
        $this->addSql('CREATE INDEX IDX_2E3DC01AD14FE63F ON document_shares (shared_with_id)');
        $this->addSql('CREATE INDEX IDX_2E3DC01A5489CD19 ON document_shares (shared_by_id)');
        $this->addSql('CREATE INDEX idx_shared_with_active ON document_shares (shared_with_id, is_active)');
        $this->addSql('CREATE INDEX idx_document_active ON document_shares (document_id, is_active)');
        $this->addSql('ALTER TABLE document_shares ADD CONSTRAINT FK_2E3DC01AC33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_shares ADD CONSTRAINT FK_2E3DC01AD14FE63F FOREIGN KEY (shared_with_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_shares ADD CONSTRAINT FK_2E3DC01A5489CD19 FOREIGN KEY (shared_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_shares DROP CONSTRAINT FK_2E3DC01AC33F7837');
        $this->addSql('ALTER TABLE document_shares DROP CONSTRAINT FK_2E3DC01AD14FE63F');
        $this->addSql('ALTER TABLE document_shares DROP CONSTRAINT FK_2E3DC01A5489CD19');
        $this->addSql('DROP TABLE document_shares');
    }
}
