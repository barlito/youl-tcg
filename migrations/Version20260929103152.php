<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-player read state of the broadcasts opened one by one.
 */
final class Version20260929103152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification_broadcast_read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_broadcast_read (read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, notification_id UUID NOT NULL, discord_user_id VARCHAR NOT NULL, PRIMARY KEY (notification_id, discord_user_id))');
        $this->addSql('CREATE INDEX IDX_A012739DEF1A9D84 ON notification_broadcast_read (notification_id)');
        $this->addSql('CREATE INDEX IDX_A012739DE3F3F7CE ON notification_broadcast_read (discord_user_id)');
        $this->addSql('ALTER TABLE notification_broadcast_read ADD CONSTRAINT FK_A012739DEF1A9D84 FOREIGN KEY (notification_id) REFERENCES notification (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_broadcast_read ADD CONSTRAINT FK_A012739DE3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_broadcast_read DROP CONSTRAINT FK_A012739DEF1A9D84');
        $this->addSql('ALTER TABLE notification_broadcast_read DROP CONSTRAINT FK_A012739DE3F3F7CE');
        $this->addSql('DROP TABLE notification_broadcast_read');
    }
}
