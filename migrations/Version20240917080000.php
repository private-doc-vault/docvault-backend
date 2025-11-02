<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial migration to create all entity tables for DocVault application
 */
final class Version20240917080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial database schema for DocVault entities: users, documents, categories, document_tags, user_groups, audit_logs';
    }

    public function up(Schema $schema): void
    {
        // Enable UUID extension for PostgreSQL
        $this->addSql('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // Create users table
        $this->addSql('CREATE TABLE users (
            id VARCHAR(36) NOT NULL,
            email VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) DEFAULT NULL,
            is_active BOOLEAN DEFAULT TRUE NOT NULL,
            is_verified BOOLEAN DEFAULT FALSE NOT NULL,
            roles JSON NOT NULL,
            last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            preferences JSON DEFAULT \'{}\' NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_users_email ON users (email)');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.last_login_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.email_verified_at IS \'(DC2Type:datetime_immutable)\'');

        // Create categories table
        $this->addSql('CREATE TABLE categories (
            id VARCHAR(36) NOT NULL,
            parent_id VARCHAR(36) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            color VARCHAR(7) DEFAULT \'#3B82F6\' NOT NULL,
            icon VARCHAR(100) DEFAULT \'folder\' NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_categories_parent_id ON categories (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_categories_slug ON categories (slug)');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_categories_parent_id FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN categories.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN categories.updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Create document_tags table
        $this->addSql('CREATE TABLE document_tags (
            id VARCHAR(36) NOT NULL,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            color VARCHAR(7) DEFAULT \'#6B7280\' NOT NULL,
            usage_count INT DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_document_tags_name ON document_tags (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_document_tags_slug ON document_tags (slug)');
        $this->addSql('COMMENT ON COLUMN document_tags.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN document_tags.updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Create user_groups table
        $this->addSql('CREATE TABLE user_groups (
            id VARCHAR(36) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            permissions JSON DEFAULT \'[]\' NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_user_groups_name ON user_groups (name)');
        $this->addSql('COMMENT ON COLUMN user_groups.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_groups.updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Create documents table
        $this->addSql('CREATE TABLE documents (
            id VARCHAR(36) NOT NULL,
            owner_id VARCHAR(36) NOT NULL,
            category_id VARCHAR(36) DEFAULT NULL,
            title VARCHAR(500) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(1000) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size BIGINT NOT NULL,
            checksum VARCHAR(64) NOT NULL,
            ocr_text TEXT DEFAULT NULL,
            ocr_confidence DOUBLE PRECISION DEFAULT NULL,
            processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            version INT DEFAULT 1 NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_documents_owner_id ON documents (owner_id)');
        $this->addSql('CREATE INDEX IDX_documents_category_id ON documents (category_id)');
        $this->addSql('CREATE INDEX IDX_documents_checksum ON documents (checksum)');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_documents_owner_id FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_documents_category_id FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN documents.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN documents.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN documents.processed_at IS \'(DC2Type:datetime_immutable)\'');

        // Create audit_logs table
        $this->addSql('CREATE TABLE audit_logs (
            id VARCHAR(36) NOT NULL,
            user_id VARCHAR(36) DEFAULT NULL,
            document_id VARCHAR(36) DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            description TEXT DEFAULT NULL,
            metadata JSON DEFAULT \'{}\' NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_audit_logs_user_id ON audit_logs (user_id)');
        $this->addSql('CREATE INDEX IDX_audit_logs_document_id ON audit_logs (document_id)');
        $this->addSql('CREATE INDEX IDX_audit_logs_action ON audit_logs (action)');
        $this->addSql('CREATE INDEX IDX_audit_logs_created_at ON audit_logs (created_at)');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_audit_logs_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_audit_logs_document_id FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN audit_logs.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Create many-to-many join tables
        $this->addSql('CREATE TABLE document_document_tag (
            document_id VARCHAR(36) NOT NULL,
            document_tag_id VARCHAR(36) NOT NULL,
            PRIMARY KEY(document_id, document_tag_id)
        )');
        $this->addSql('CREATE INDEX IDX_document_document_tag_document_id ON document_document_tag (document_id)');
        $this->addSql('CREATE INDEX IDX_document_document_tag_document_tag_id ON document_document_tag (document_tag_id)');
        $this->addSql('ALTER TABLE document_document_tag ADD CONSTRAINT FK_document_document_tag_document_id FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_document_tag ADD CONSTRAINT FK_document_document_tag_document_tag_id FOREIGN KEY (document_tag_id) REFERENCES document_tags (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE user_user_group (
            user_id VARCHAR(36) NOT NULL,
            user_group_id VARCHAR(36) NOT NULL,
            PRIMARY KEY(user_id, user_group_id)
        )');
        $this->addSql('CREATE INDEX IDX_user_user_group_user_id ON user_user_group (user_id)');
        $this->addSql('CREATE INDEX IDX_user_user_group_user_group_id ON user_user_group (user_group_id)');
        $this->addSql('ALTER TABLE user_user_group ADD CONSTRAINT FK_user_user_group_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_user_group ADD CONSTRAINT FK_user_user_group_user_group_id FOREIGN KEY (user_group_id) REFERENCES user_groups (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraints first
        $this->addSql('ALTER TABLE user_user_group DROP CONSTRAINT FK_user_user_group_user_group_id');
        $this->addSql('ALTER TABLE user_user_group DROP CONSTRAINT FK_user_user_group_user_id');
        $this->addSql('ALTER TABLE document_document_tag DROP CONSTRAINT FK_document_document_tag_document_tag_id');
        $this->addSql('ALTER TABLE document_document_tag DROP CONSTRAINT FK_document_document_tag_document_id');
        $this->addSql('ALTER TABLE audit_logs DROP CONSTRAINT FK_audit_logs_document_id');
        $this->addSql('ALTER TABLE audit_logs DROP CONSTRAINT FK_audit_logs_user_id');
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_documents_category_id');
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_documents_owner_id');
        $this->addSql('ALTER TABLE categories DROP CONSTRAINT FK_categories_parent_id');

        // Drop tables
        $this->addSql('DROP TABLE user_user_group');
        $this->addSql('DROP TABLE document_document_tag');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE documents');
        $this->addSql('DROP TABLE user_groups');
        $this->addSql('DROP TABLE document_tags');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE users');
    }
}