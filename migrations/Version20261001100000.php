<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Universe completion rewards behind a feature flag, shipped OFF: rewards stop
 * as soon as this is deployed.
 */
final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the universe_rewards feature flag, disabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO feature_flag (name, enabled, created_at, updated_at) VALUES ('universe_rewards', false, NOW(), NOW()) ON CONFLICT (name) DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'universe_rewards'");
    }
}
