<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Duel decks: the cards the player chose to play in their holo copy.
 */
final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the holo choice per duel deck card';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deck_holo_card (deck_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (deck_id, card_id))');
        $this->addSql('CREATE INDEX IDX_E5E77799111948DC ON deck_holo_card (deck_id)');
        $this->addSql('CREATE INDEX IDX_E5E777994ACC9A20 ON deck_holo_card (card_id)');
        $this->addSql('ALTER TABLE deck_holo_card ADD CONSTRAINT FK_E5E77799111948DC FOREIGN KEY (deck_id) REFERENCES deck (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE deck_holo_card ADD CONSTRAINT FK_E5E777994ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_holo_card DROP CONSTRAINT FK_E5E77799111948DC');
        $this->addSql('ALTER TABLE deck_holo_card DROP CONSTRAINT FK_E5E777994ACC9A20');
        $this->addSql('DROP TABLE deck_holo_card');
    }
}
