<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Last piece of the card.claimed_by drift: the column was created as
 * VARCHAR(255) by the hand-written unique-claim migration, while its mapping
 * (ManyToOne on DiscordUser::$discordId, a length-less string column) resolves
 * to an unbounded VARCHAR. Version20260805120000 realigned the index and FK
 * names but left the type, so doctrine:schema:validate — run by the CI since
 * the quality-gaps workflow — still reported the schema out of sync.
 */
final class Version20260807090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the card.claimed_by column type with its mapping (VARCHAR)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ALTER claimed_by TYPE VARCHAR');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ALTER claimed_by TYPE VARCHAR(255)');
    }
}
