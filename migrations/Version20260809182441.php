<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recycle audit tables: one operation row (user, booster, points, date) plus
 * one card row per distinct debited card — same pattern as booster_opening.
 */
final class Version20260809182441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recycle operation audit tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recycle_operation (points INT NOT NULL, recycled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, discord_user_id VARCHAR NOT NULL, booster_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_25705E62E3F3F7CE ON recycle_operation (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_25705E62F85E4930 ON recycle_operation (booster_id)');
        $this->addSql('CREATE TABLE recycle_operation_card (quantity INT NOT NULL, holo_quantity INT DEFAULT 0 NOT NULL, recycle_operation_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (recycle_operation_id, card_id))');
        $this->addSql('CREATE INDEX IDX_3F130CC3592FDBBD ON recycle_operation_card (recycle_operation_id)');
        $this->addSql('CREATE INDEX IDX_3F130CC34ACC9A20 ON recycle_operation_card (card_id)');
        $this->addSql('ALTER TABLE recycle_operation ADD CONSTRAINT FK_25705E62E3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recycle_operation ADD CONSTRAINT FK_25705E62F85E4930 FOREIGN KEY (booster_id) REFERENCES booster (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recycle_operation_card ADD CONSTRAINT FK_3F130CC3592FDBBD FOREIGN KEY (recycle_operation_id) REFERENCES recycle_operation (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recycle_operation_card ADD CONSTRAINT FK_3F130CC34ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recycle_operation DROP CONSTRAINT FK_25705E62E3F3F7CE');
        $this->addSql('ALTER TABLE recycle_operation DROP CONSTRAINT FK_25705E62F85E4930');
        $this->addSql('ALTER TABLE recycle_operation_card DROP CONSTRAINT FK_3F130CC3592FDBBD');
        $this->addSql('ALTER TABLE recycle_operation_card DROP CONSTRAINT FK_3F130CC34ACC9A20');
        $this->addSql('DROP TABLE recycle_operation');
        $this->addSql('DROP TABLE recycle_operation_card');
    }
}
