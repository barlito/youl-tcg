<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Asynchronous P2P trade offers: one offer row per proposal, one line per card
 * and side. The reservation ledger is derived from the pending lines — there is
 * no counter column to keep in sync.
 */
final class Version20260810000257 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trade_offer and trade_offer_line tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trade_offer (status VARCHAR(255) DEFAULT \'pending\' NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, proposer_id VARCHAR NOT NULL, receiver_id VARCHAR NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3DB57DDB13FA634 ON trade_offer (proposer_id)');
        $this->addSql('CREATE INDEX IDX_3DB57DDCD53EDB6 ON trade_offer (receiver_id)');
        $this->addSql('CREATE INDEX idx_trade_offer_proposer_status ON trade_offer (proposer_id, status)');
        $this->addSql('CREATE INDEX idx_trade_offer_receiver_status ON trade_offer (receiver_id, status)');
        $this->addSql('CREATE TABLE trade_offer_line (side VARCHAR(255) NOT NULL, normal_quantity INT DEFAULT 0 NOT NULL, holo_quantity INT DEFAULT 0 NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, trade_offer_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_17FC10C7EBA39F ON trade_offer_line (trade_offer_id)');
        $this->addSql('CREATE INDEX IDX_17FC10C4ACC9A20 ON trade_offer_line (card_id)');
        $this->addSql('ALTER TABLE trade_offer ADD CONSTRAINT FK_3DB57DDB13FA634 FOREIGN KEY (proposer_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trade_offer ADD CONSTRAINT FK_3DB57DDCD53EDB6 FOREIGN KEY (receiver_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trade_offer_line ADD CONSTRAINT FK_17FC10C7EBA39F FOREIGN KEY (trade_offer_id) REFERENCES trade_offer (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trade_offer_line ADD CONSTRAINT FK_17FC10C4ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_offer DROP CONSTRAINT FK_3DB57DDB13FA634');
        $this->addSql('ALTER TABLE trade_offer DROP CONSTRAINT FK_3DB57DDCD53EDB6');
        $this->addSql('ALTER TABLE trade_offer_line DROP CONSTRAINT FK_17FC10C7EBA39F');
        $this->addSql('ALTER TABLE trade_offer_line DROP CONSTRAINT FK_17FC10C4ACC9A20');
        $this->addSql('DROP TABLE trade_offer_line');
        $this->addSql('DROP TABLE trade_offer');
    }
}
