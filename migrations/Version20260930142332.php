<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Universe completion rewards: per-universe amount, coin settings singleton, reward table.
 */
final class Version20260930142332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create universe_completion_reward and coin_settings (seeded), add extension.completion_reward_coins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE coin_settings (id INT NOT NULL, default_universe_reward_coins INT DEFAULT 500 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO coin_settings (id, default_universe_reward_coins) VALUES (1, 500)');
        $this->addSql('CREATE TABLE universe_completion_reward (status VARCHAR(255) NOT NULL, coin_transaction_id VARCHAR(255) DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, amount INT NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, discord_user_id VARCHAR NOT NULL, extension_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D53C82CC7B00651C ON universe_completion_reward (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_universe_reward_player_extension ON universe_completion_reward (discord_user_id, extension_id)');
        $this->addSql('CREATE INDEX IDX_D53C82CCE3F3F7CE ON universe_completion_reward (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_D53C82CC812D5EB ON universe_completion_reward (extension_id)');
        $this->addSql('ALTER TABLE universe_completion_reward ADD CONSTRAINT FK_D53C82CCE3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE universe_completion_reward ADD CONSTRAINT FK_D53C82CC812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE extension ADD completion_reward_coins INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE universe_completion_reward DROP CONSTRAINT FK_D53C82CCE3F3F7CE');
        $this->addSql('ALTER TABLE universe_completion_reward DROP CONSTRAINT FK_D53C82CC812D5EB');
        $this->addSql('DROP TABLE coin_settings');
        $this->addSql('DROP TABLE universe_completion_reward');
        $this->addSql('ALTER TABLE extension DROP completion_reward_coins');
    }
}
