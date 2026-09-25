<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin send log (announcements, booster code notifications) and the player
 * a single-use code was sent to.
 */
final class Version20260926100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create announcement + announcement_recipient, add booster_code.assigned_to_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE announcement (type VARCHAR(40) NOT NULL, title VARCHAR(150) NOT NULL, message TEXT DEFAULT NULL, link VARCHAR(255) DEFAULT NULL, sent_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, context VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, author_id VARCHAR DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_announcement_created ON announcement (created_at)');
        $this->addSql('CREATE INDEX IDX_4DB9D91CF675F31B ON announcement (author_id)');
        $this->addSql('CREATE TABLE announcement_recipient (announcement_id UUID NOT NULL, discord_user_discord_id VARCHAR NOT NULL, PRIMARY KEY (announcement_id, discord_user_discord_id))');
        $this->addSql('CREATE INDEX IDX_4CCB1565913AEA17 ON announcement_recipient (announcement_id)');
        $this->addSql('CREATE INDEX IDX_4CCB15659C618639 ON announcement_recipient (discord_user_discord_id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CF675F31B FOREIGN KEY (author_id) REFERENCES discord_user (discord_id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE announcement_recipient ADD CONSTRAINT FK_4CCB1565913AEA17 FOREIGN KEY (announcement_id) REFERENCES announcement (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE announcement_recipient ADD CONSTRAINT FK_4CCB15659C618639 FOREIGN KEY (discord_user_discord_id) REFERENCES discord_user (discord_id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_code ADD assigned_to_id VARCHAR DEFAULT NULL');
        $this->addSql('ALTER TABLE booster_code ADD CONSTRAINT FK_CE3E1197F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES discord_user (discord_id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CE3E1197F4BD7827 ON booster_code (assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster_code DROP CONSTRAINT FK_CE3E1197F4BD7827');
        $this->addSql('DROP INDEX IDX_CE3E1197F4BD7827');
        $this->addSql('ALTER TABLE booster_code DROP assigned_to_id');
        $this->addSql('DROP TABLE announcement_recipient');
        $this->addSql('DROP TABLE announcement');
    }
}
