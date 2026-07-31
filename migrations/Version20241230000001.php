<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add Document and Notification entities for testing tenant-aware storage, mailer, and messenger functionality.
 */
final class Version20241230000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Document and Notification entities for tenant-aware testing';
    }

    public function up(Schema $schema): void
    {
        // Create documents table
        $this->addSql('CREATE TABLE documents (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INTEGER NOT NULL,
            description TEXT DEFAULT NULL,
            active BOOLEAN NOT NULL DEFAULT true,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            tenant_id INTEGER NOT NULL,
            uploaded_by_id INTEGER DEFAULT NULL
        )');

        $this->addSql('CREATE INDEX IDX_A2B07288B03A8386 ON documents (tenant_id)');
        $this->addSql('CREATE INDEX IDX_A2B07288A2B28FE8 ON documents (uploaded_by_id)');
        $this->addSql('COMMENT ON COLUMN documents.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN documents.updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Create notifications table
        $this->addSql('CREATE TABLE notifications (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT \'info\',
            status VARCHAR(50) NOT NULL DEFAULT \'pending\',
            recipient_email VARCHAR(255) DEFAULT NULL,
            send_email BOOLEAN NOT NULL DEFAULT false,
            send_in_app BOOLEAN NOT NULL DEFAULT true,
            is_read BOOLEAN NOT NULL DEFAULT false,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            tenant_id INTEGER NOT NULL,
            recipient_id INTEGER DEFAULT NULL,
            created_by_id INTEGER DEFAULT NULL
        )');

        $this->addSql('CREATE INDEX IDX_6000B0D3B03A8386 ON notifications (tenant_id)');
        $this->addSql('CREATE INDEX IDX_6000B0D3E92F8F78 ON notifications (recipient_id)');
        $this->addSql('CREATE INDEX IDX_6000B0D3B03A8386_2 ON notifications (created_by_id)');
        $this->addSql('COMMENT ON COLUMN notifications.sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN notifications.read_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN notifications.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN notifications.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE documents');
        $this->addSql('DROP TABLE notifications');
    }
}