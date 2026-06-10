<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260610153422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booster claim/opening audit tables and user_card.holo_quantity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booster_claim (claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, discord_user_id VARCHAR NOT NULL, booster_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_376FE897E3F3F7CE ON booster_claim (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_376FE897F85E4930 ON booster_claim (booster_id)');
        $this->addSql('CREATE TABLE booster_opening (opened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seed BIGINT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, discord_user_id VARCHAR NOT NULL, booster_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_254A7FE4E3F3F7CE ON booster_opening (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_254A7FE4F85E4930 ON booster_opening (booster_id)');
        $this->addSql('CREATE TABLE booster_opening_card (quantity INT NOT NULL, holo_quantity INT DEFAULT 0 NOT NULL, booster_opening_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (booster_opening_id, card_id))');
        $this->addSql('CREATE INDEX IDX_30C7E1ECF60470DE ON booster_opening_card (booster_opening_id)');
        $this->addSql('CREATE INDEX IDX_30C7E1EC4ACC9A20 ON booster_opening_card (card_id)');
        $this->addSql('ALTER TABLE booster_claim ADD CONSTRAINT FK_376FE897E3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_claim ADD CONSTRAINT FK_376FE897F85E4930 FOREIGN KEY (booster_id) REFERENCES booster (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_opening ADD CONSTRAINT FK_254A7FE4E3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_opening ADD CONSTRAINT FK_254A7FE4F85E4930 FOREIGN KEY (booster_id) REFERENCES booster (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_opening_card ADD CONSTRAINT FK_30C7E1ECF60470DE FOREIGN KEY (booster_opening_id) REFERENCES booster_opening (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_opening_card ADD CONSTRAINT FK_30C7E1EC4ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_card ADD holo_quantity INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster_claim DROP CONSTRAINT FK_376FE897E3F3F7CE');
        $this->addSql('ALTER TABLE booster_claim DROP CONSTRAINT FK_376FE897F85E4930');
        $this->addSql('ALTER TABLE booster_opening DROP CONSTRAINT FK_254A7FE4E3F3F7CE');
        $this->addSql('ALTER TABLE booster_opening DROP CONSTRAINT FK_254A7FE4F85E4930');
        $this->addSql('ALTER TABLE booster_opening_card DROP CONSTRAINT FK_30C7E1ECF60470DE');
        $this->addSql('ALTER TABLE booster_opening_card DROP CONSTRAINT FK_30C7E1EC4ACC9A20');
        $this->addSql('DROP TABLE booster_claim');
        $this->addSql('DROP TABLE booster_opening');
        $this->addSql('DROP TABLE booster_opening_card');
        $this->addSql('ALTER TABLE user_card DROP holo_quantity');
    }
}
