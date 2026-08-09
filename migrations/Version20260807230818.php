<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Redeemable booster codes: a code grants copies of one booster outside the
 * daily claim flow, capped by max_uses globally and by the unique
 * (booster_code_id, discord_user_id) pair per player.
 */
final class Version20260807230818 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add booster_code and booster_code_redemption tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booster_code (code VARCHAR(32) NOT NULL, quantity INT DEFAULT 1 NOT NULL, max_uses INT DEFAULT NULL, uses INT DEFAULT 0 NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, disabled BOOLEAN DEFAULT false NOT NULL, batch_label VARCHAR(100) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, booster_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CE3E1197F85E4930 ON booster_code (booster_id)');
        $this->addSql('CREATE INDEX IDX_CE3E11972335DB8F ON booster_code (batch_label)');
        $this->addSql('CREATE UNIQUE INDEX uniq_booster_code_code ON booster_code (code)');
        $this->addSql('CREATE TABLE booster_code_redemption (redeemed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, quantity INT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, booster_code_id UUID NOT NULL, discord_user_id VARCHAR NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FEB5841FCA55530 ON booster_code_redemption (booster_code_id)');
        $this->addSql('CREATE INDEX IDX_FEB5841FE3F3F7CE ON booster_code_redemption (discord_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_booster_code_redemption ON booster_code_redemption (booster_code_id, discord_user_id)');
        $this->addSql('ALTER TABLE booster_code ADD CONSTRAINT FK_CE3E1197F85E4930 FOREIGN KEY (booster_id) REFERENCES booster (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_code_redemption ADD CONSTRAINT FK_FEB5841FCA55530 FOREIGN KEY (booster_code_id) REFERENCES booster_code (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_code_redemption ADD CONSTRAINT FK_FEB5841FE3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster_code DROP CONSTRAINT FK_CE3E1197F85E4930');
        $this->addSql('ALTER TABLE booster_code_redemption DROP CONSTRAINT FK_FEB5841FCA55530');
        $this->addSql('ALTER TABLE booster_code_redemption DROP CONSTRAINT FK_FEB5841FE3F3F7CE');
        $this->addSql('DROP TABLE booster_code');
        $this->addSql('DROP TABLE booster_code_redemption');
    }
}
