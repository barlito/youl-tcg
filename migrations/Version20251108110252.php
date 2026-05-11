<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251108110252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booster_opening (id UUID NOT NULL, discord_user_id VARCHAR(255) NOT NULL, booster_id UUID NOT NULL, opened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seed INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_254A7FE4E3F3F7CE ON booster_opening (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_254A7FE4F85E4930 ON booster_opening (booster_id)');
        $this->addSql('COMMENT ON COLUMN booster_opening.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN booster_opening.booster_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN booster_opening.opened_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE booster_opening_card (booster_opening_id UUID NOT NULL, card_id UUID NOT NULL, quantity INT NOT NULL, PRIMARY KEY(booster_opening_id, card_id))');
        $this->addSql('CREATE INDEX IDX_30C7E1ECF60470DE ON booster_opening_card (booster_opening_id)');
        $this->addSql('CREATE INDEX IDX_30C7E1EC4ACC9A20 ON booster_opening_card (card_id)');
        $this->addSql('COMMENT ON COLUMN booster_opening_card.booster_opening_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN booster_opening_card.card_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE booster_opening ADD CONSTRAINT FK_254A7FE4E3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE booster_opening ADD CONSTRAINT FK_254A7FE4F85E4930 FOREIGN KEY (booster_id) REFERENCES booster (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE booster_opening_card ADD CONSTRAINT FK_30C7E1ECF60470DE FOREIGN KEY (booster_opening_id) REFERENCES booster_opening (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE booster_opening_card ADD CONSTRAINT FK_30C7E1EC4ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE booster_opening DROP CONSTRAINT FK_254A7FE4E3F3F7CE');
        $this->addSql('ALTER TABLE booster_opening DROP CONSTRAINT FK_254A7FE4F85E4930');
        $this->addSql('ALTER TABLE booster_opening_card DROP CONSTRAINT FK_30C7E1ECF60470DE');
        $this->addSql('ALTER TABLE booster_opening_card DROP CONSTRAINT FK_30C7E1EC4ACC9A20');
        $this->addSql('DROP TABLE booster_opening');
        $this->addSql('DROP TABLE booster_opening_card');
    }
}
