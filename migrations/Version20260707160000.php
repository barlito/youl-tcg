<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extension.code: cosmetic set code shown on the universe tiles (e.g.
 * "CYB-01"), free text chosen by the admin — replaces the misleading
 * position-based "EX-004" chips.
 */
final class Version20260707160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extension.code (cosmetic set code on universe tiles)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ADD code VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP code');
    }
}
