<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251016154050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add saved_searches, search_history, and webhook_configs tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE saved_searches (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, query TEXT NOT NULL, filters JSON NOT NULL, description TEXT DEFAULT NULL, is_public BOOLEAN NOT NULL, usage_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EF93F31A76ED395 ON saved_searches (user_id)');
        $this->addSql('CREATE TABLE search_history (id VARCHAR(36) NOT NULL, query TEXT NOT NULL, filters JSON NOT NULL, result_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AA6B9FD1A76ED395 ON search_history (user_id)');
        $this->addSql('CREATE INDEX idx_user_created ON search_history (user_id, created_at)');
        $this->addSql('CREATE TABLE webhook_configs (id VARCHAR(36) NOT NULL, url VARCHAR(255) NOT NULL, events JSON NOT NULL, active BOOLEAN NOT NULL, secret VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6CF426ABA76ED395 ON webhook_configs (user_id)');
        $this->addSql('ALTER TABLE saved_searches ADD CONSTRAINT FK_EF93F31A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE search_history ADD CONSTRAINT FK_AA6B9FD1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE webhook_configs ADD CONSTRAINT FK_6CF426ABA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE saved_searches DROP CONSTRAINT FK_EF93F31A76ED395');
        $this->addSql('ALTER TABLE search_history DROP CONSTRAINT FK_AA6B9FD1A76ED395');
        $this->addSql('ALTER TABLE webhook_configs DROP CONSTRAINT FK_6CF426ABA76ED395');
        $this->addSql('DROP TABLE saved_searches');
        $this->addSql('DROP TABLE search_history');
        $this->addSql('DROP TABLE webhook_configs');
    }
}
