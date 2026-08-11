<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Next universe" teaser: at most one extension is flagged upcoming and shows
 * up as a blurred tile on the homepage / universe index.
 */
final class Version20260809120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add extension.upcoming flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ADD upcoming BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP upcoming');
    }
}
