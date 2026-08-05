<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Version20260618130000 hand-named the card.claimed_by index and foreign key
 * (idx_card_claimed_by / fk_card_claimed_by) while the entity mapping expects
 * the Doctrine-generated identifiers: the index name mismatch keeps
 * doctrine:schema:validate permanently red and would make the next db.diff
 * try to recreate it. Rename both to the names Doctrine derives from
 * (table, columns) — IDX_/FK_161498D3296C217B — so the database matches the
 * mapping exactly. Definitions are untouched (ON DELETE SET NULL stays).
 */
final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename card.claimed_by index/FK to the Doctrine default names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_CARD_CLAIMED_BY RENAME TO IDX_161498D3296C217B');
        $this->addSql('ALTER TABLE card RENAME CONSTRAINT FK_CARD_CLAIMED_BY TO FK_161498D3296C217B');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_161498D3296C217B RENAME TO IDX_CARD_CLAIMED_BY');
        $this->addSql('ALTER TABLE card RENAME CONSTRAINT FK_161498D3296C217B TO FK_CARD_CLAIMED_BY');
    }
}
