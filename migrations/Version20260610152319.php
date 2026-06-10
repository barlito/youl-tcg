<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260610152319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add card.rarity and card.type; absorb DBAL 4 schema drift (uuid comments, varchar lengths)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN booster.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN booster.extension_id IS \'\'');
        $this->addSql('ALTER TABLE card ADD rarity VARCHAR(255) DEFAULT \'common\' NOT NULL');
        $this->addSql('ALTER TABLE card ADD type VARCHAR(255) DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN card.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN card.extension_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN extension.id IS \'\'');
        $this->addSql('ALTER TABLE user_booster ALTER discord_user_id TYPE VARCHAR');
        $this->addSql('COMMENT ON COLUMN user_booster.booster_id IS \'\'');
        $this->addSql('ALTER TABLE user_card ALTER discord_user_id TYPE VARCHAR');
        $this->addSql('COMMENT ON COLUMN user_card.card_id IS \'\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN booster.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN booster.extension_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE card DROP rarity');
        $this->addSql('ALTER TABLE card DROP type');
        $this->addSql('COMMENT ON COLUMN card.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN card.extension_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN extension.id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE user_booster ALTER discord_user_id TYPE VARCHAR(255)');
        $this->addSql('COMMENT ON COLUMN user_booster.booster_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE user_card ALTER discord_user_id TYPE VARCHAR(255)');
        $this->addSql('COMMENT ON COLUMN user_card.card_id IS \'(DC2Type:uuid)\'');
    }
}
