<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opening streak rewards: one row per milestone (7, 14, 21… consecutive
 * opening days) reached by a series, unique per (user, series start day,
 * milestone) so granting is idempotent. The composite booster_opening index
 * serves the distinct-opening-days streak query.
 */
final class Version20260809180828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add streak_reward table and the booster_opening (user, opened_at) index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE streak_reward (chosen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, series_started_on DATE NOT NULL, milestone INT NOT NULL, awarded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, chosen_booster_id UUID DEFAULT NULL, discord_user_id VARCHAR NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5291ABE115721E0 ON streak_reward (chosen_booster_id)');
        $this->addSql('CREATE INDEX IDX_5291ABE1E3F3F7CE ON streak_reward (discord_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_streak_reward_milestone ON streak_reward (discord_user_id, series_started_on, milestone)');
        $this->addSql('ALTER TABLE streak_reward ADD CONSTRAINT FK_5291ABE115721E0 FOREIGN KEY (chosen_booster_id) REFERENCES booster (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE streak_reward ADD CONSTRAINT FK_5291ABE1E3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_254A7FE4E3F3F7CE8B0BD804 ON booster_opening (discord_user_id, opened_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE streak_reward DROP CONSTRAINT FK_5291ABE115721E0');
        $this->addSql('ALTER TABLE streak_reward DROP CONSTRAINT FK_5291ABE1E3F3F7CE');
        $this->addSql('DROP TABLE streak_reward');
        $this->addSql('DROP INDEX IDX_254A7FE4E3F3F7CE8B0BD804');
    }
}
