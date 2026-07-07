<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Booster: optional display name (named after its drop rates, e.g. "Pack Full
 * Rare") and a `claimable` flag — a non-claimable booster is distributed via
 * event/code only: it cannot be claimed for free on the hub but stays openable
 * for users who already own copies.
 */
final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booster.name (display) + Booster.claimable (hub free-claim opt-out)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster ADD name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE booster ADD claimable BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster DROP name');
        $this->addSql('ALTER TABLE booster DROP claimable');
    }
}
