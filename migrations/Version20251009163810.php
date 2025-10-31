<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251009163810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE documents ADD uploaded_by_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B07288A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A2B07288A2B28FE8 ON documents (uploaded_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_A2B07288A2B28FE8');
        $this->addSql('DROP INDEX IDX_A2B07288A2B28FE8');
        $this->addSql('ALTER TABLE documents DROP uploaded_by_id');
    }
}
