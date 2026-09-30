<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930143249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create market_listing and market_purchase, add coin_settings.market_fee_percent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE market_listing (status VARCHAR(255) NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, holo BOOLEAN NOT NULL, price INT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seller_id VARCHAR NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C296A0548DE820D97B00651C ON market_listing (seller_id, status)');
        $this->addSql('CREATE INDEX IDX_C296A0547B00651C8B8E8428 ON market_listing (status, created_at)');
        $this->addSql('CREATE INDEX IDX_C296A0548DE820D9 ON market_listing (seller_id)');
        $this->addSql('CREATE INDEX IDX_C296A0544ACC9A20 ON market_listing (card_id)');
        $this->addSql('CREATE TABLE market_purchase (status VARCHAR(255) NOT NULL, payment_transaction_id VARCHAR(255) DEFAULT NULL, payout_transaction_id VARCHAR(255) DEFAULT NULL, refund_transaction_id VARCHAR(255) DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, price INT NOT NULL, fee_minor BIGINT NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, listing_id UUID NOT NULL, buyer_id VARCHAR NOT NULL, seller_id VARCHAR NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8CA6C4F36C7557227B00651C ON market_purchase (buyer_id, status)');
        $this->addSql('CREATE INDEX IDX_8CA6C4F38DE820D97B00651C ON market_purchase (seller_id, status)');
        $this->addSql('CREATE INDEX IDX_8CA6C4F37B00651C4D58BEDB ON market_purchase (status, requested_at)');
        $this->addSql('CREATE INDEX IDX_8CA6C4F3D4619D1A ON market_purchase (listing_id)');
        $this->addSql('CREATE INDEX IDX_8CA6C4F36C755722 ON market_purchase (buyer_id)');
        $this->addSql('CREATE INDEX IDX_8CA6C4F38DE820D9 ON market_purchase (seller_id)');
        $this->addSql('ALTER TABLE market_listing ADD CONSTRAINT FK_C296A0548DE820D9 FOREIGN KEY (seller_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE market_listing ADD CONSTRAINT FK_C296A0544ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE market_purchase ADD CONSTRAINT FK_8CA6C4F3D4619D1A FOREIGN KEY (listing_id) REFERENCES market_listing (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE market_purchase ADD CONSTRAINT FK_8CA6C4F36C755722 FOREIGN KEY (buyer_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE market_purchase ADD CONSTRAINT FK_8CA6C4F38DE820D9 FOREIGN KEY (seller_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE coin_settings ADD market_fee_percent INT DEFAULT 5 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE market_listing DROP CONSTRAINT FK_C296A0548DE820D9');
        $this->addSql('ALTER TABLE market_listing DROP CONSTRAINT FK_C296A0544ACC9A20');
        $this->addSql('ALTER TABLE market_purchase DROP CONSTRAINT FK_8CA6C4F3D4619D1A');
        $this->addSql('ALTER TABLE market_purchase DROP CONSTRAINT FK_8CA6C4F36C755722');
        $this->addSql('ALTER TABLE market_purchase DROP CONSTRAINT FK_8CA6C4F38DE820D9');
        $this->addSql('DROP TABLE market_listing');
        $this->addSql('DROP TABLE market_purchase');
        $this->addSql('ALTER TABLE coin_settings DROP market_fee_percent');
    }
}
