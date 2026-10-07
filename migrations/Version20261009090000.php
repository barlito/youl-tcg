<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Duel integration: card tags and terrain flag, decks, behind the duel feature flag shipped OFF.
 */
final class Version20261009090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add card tags and terrain flag, duel decks and the duel feature flag, disabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deck (name VARCHAR(40) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, terrain_id UUID DEFAULT NULL, owner_id VARCHAR NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4FAC36378A2D8B41 ON deck (terrain_id)');
        $this->addSql('CREATE INDEX IDX_4FAC36377E3C61F9 ON deck (owner_id)');
        $this->addSql('CREATE TABLE deck_card (deck_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (deck_id, card_id))');
        $this->addSql('CREATE INDEX IDX_2AF3DCED111948DC ON deck_card (deck_id)');
        $this->addSql('CREATE INDEX IDX_2AF3DCED4ACC9A20 ON deck_card (card_id)');
        $this->addSql('ALTER TABLE deck ADD CONSTRAINT FK_4FAC36378A2D8B41 FOREIGN KEY (terrain_id) REFERENCES card (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE deck ADD CONSTRAINT FK_4FAC36377E3C61F9 FOREIGN KEY (owner_id) REFERENCES discord_user (discord_id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE deck_card ADD CONSTRAINT FK_2AF3DCED111948DC FOREIGN KEY (deck_id) REFERENCES deck (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE deck_card ADD CONSTRAINT FK_2AF3DCED4ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card ADD tags JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE card ADD terrain BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("INSERT INTO feature_flag (name, enabled, created_at, updated_at) VALUES ('duel', false, NOW(), NOW()) ON CONFLICT (name) DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'duel'");
        $this->addSql('ALTER TABLE deck DROP CONSTRAINT FK_4FAC36378A2D8B41');
        $this->addSql('ALTER TABLE deck DROP CONSTRAINT FK_4FAC36377E3C61F9');
        $this->addSql('ALTER TABLE deck_card DROP CONSTRAINT FK_2AF3DCED111948DC');
        $this->addSql('ALTER TABLE deck_card DROP CONSTRAINT FK_2AF3DCED4ACC9A20');
        $this->addSql('DROP TABLE deck');
        $this->addSql('DROP TABLE deck_card');
        $this->addSql('ALTER TABLE card DROP tags');
        $this->addSql('ALTER TABLE card DROP terrain');
    }
}
