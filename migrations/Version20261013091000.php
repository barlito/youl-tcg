<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Duel decks: notice of what the last reconciliation with the collection changed.
 */
final class Version20261013091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the changes notice to duel decks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD changes JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP changes');
    }
}
