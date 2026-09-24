<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A recycle operation now credits one booster per full 10-point tranche: the
 * audit keeps how many copies of the chosen booster were granted.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recycle_operation.booster_count (copies of the chosen booster granted)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recycle_operation ADD booster_count INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recycle_operation DROP booster_count');
    }
}
