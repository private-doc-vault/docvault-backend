<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251009172134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_versions (id VARCHAR(36) NOT NULL, version_number INT NOT NULL, file_path TEXT NOT NULL, file_size BIGINT NOT NULL, mime_type VARCHAR(100) NOT NULL, change_description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, document_id VARCHAR(36) NOT NULL, uploaded_by_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_961DB18BC33F7837 ON document_versions (document_id)');
        $this->addSql('CREATE INDEX IDX_961DB18BA2B28FE8 ON document_versions (uploaded_by_id)');
        $this->addSql('ALTER TABLE document_versions ADD CONSTRAINT FK_961DB18BC33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_versions ADD CONSTRAINT FK_961DB18BA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_versions DROP CONSTRAINT FK_961DB18BC33F7837');
        $this->addSql('ALTER TABLE document_versions DROP CONSTRAINT FK_961DB18BA2B28FE8');
        $this->addSql('DROP TABLE document_versions');
    }
}
