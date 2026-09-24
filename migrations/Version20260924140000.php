<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Feature flags: one row per FeatureEnum case, shipped OFF — the admin turns
 * each feature on explicitly.
 */
final class Version20260924140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create feature_flag with the trades and recycling flags disabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feature_flag (name VARCHAR(64) NOT NULL, enabled BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (name))');
        $this->addSql("INSERT INTO feature_flag (name, enabled, created_at, updated_at) VALUES ('trades', false, NOW(), NOW()), ('recycling', false, NOW(), NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE feature_flag');
    }
}
