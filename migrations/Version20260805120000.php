<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

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
