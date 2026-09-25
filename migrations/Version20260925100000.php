<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification center: personal entries (recipient + read_at) and broadcasts
 * (null recipient), read against discord_user.notifications_seen_at.
 */
final class Version20260925100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification table and discord_user.notifications_seen_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, type VARCHAR(40) NOT NULL, payload JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, recipient_id VARCHAR DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_recipient_created ON notification (recipient_id, created_at)');
        $this->addSql('CREATE INDEX IDX_BF5476CAE92F8F78 ON notification (recipient_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES discord_user (discord_id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE discord_user ADD notifications_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discord_user DROP notifications_seen_at');
        $this->addSql('DROP TABLE notification');
    }
}
