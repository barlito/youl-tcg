<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds card.claimed_by (FK to discord_user) to support one-of-one (unique)
 * cards: the column holds the owner once a unique card is first drawn. A
 * claimed unique is filtered out of the draw pool (CardRepository) so it can
 * never be drawn again. ON DELETE SET NULL frees the card if the owner is
 * removed.
 */
final class Version20260618130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add card.claimed_by (owner of a one-of-one unique card)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ADD claimed_by VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_CARD_CLAIMED_BY FOREIGN KEY (claimed_by) REFERENCES discord_user (discord_id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_CARD_CLAIMED_BY ON card (claimed_by)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card DROP CONSTRAINT FK_CARD_CLAIMED_BY');
        $this->addSql('DROP INDEX IDX_CARD_CLAIMED_BY');
        $this->addSql('ALTER TABLE card DROP claimed_by');
    }
}
