<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250926110450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE password_reset_tokens (id VARCHAR(36) NOT NULL, token VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, is_used BOOLEAN DEFAULT false NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3967A2165F37A13B ON password_reset_tokens (token)');
        $this->addSql('CREATE INDEX idx_password_reset_token ON password_reset_tokens (token)');
        $this->addSql('CREATE INDEX idx_password_reset_user ON password_reset_tokens (user_id)');
        $this->addSql('CREATE INDEX idx_password_reset_expires ON password_reset_tokens (expires_at)');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE password_reset_tokens DROP CONSTRAINT FK_3967A216A76ED395');
        $this->addSql('DROP TABLE password_reset_tokens');
    }
}
