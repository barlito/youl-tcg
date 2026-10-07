<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Duplicate fusion: audit table and the fusion feature flag, shipped OFF.
 */
final class Version20261007112718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fusion_operation and the fusion feature flag, disabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fusion_operation (fusion_count INT NOT NULL, copies_consumed INT NOT NULL, holos_created INT NOT NULL, fused_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, discord_user_id VARCHAR NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_432BAC78E3F3F7CE ON fusion_operation (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_432BAC784ACC9A20 ON fusion_operation (card_id)');
        $this->addSql('ALTER TABLE fusion_operation ADD CONSTRAINT FK_432BAC78E3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE fusion_operation ADD CONSTRAINT FK_432BAC784ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) NOT DEFERRABLE');
        $this->addSql("INSERT INTO feature_flag (name, enabled, created_at, updated_at) VALUES ('fusion', false, NOW(), NOW()) ON CONFLICT (name) DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'fusion'");
        $this->addSql('ALTER TABLE fusion_operation DROP CONSTRAINT FK_432BAC78E3F3F7CE');
        $this->addSql('ALTER TABLE fusion_operation DROP CONSTRAINT FK_432BAC784ACC9A20');
        $this->addSql('DROP TABLE fusion_operation');
    }
}
