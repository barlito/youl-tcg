<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Booster shop: purchasable flag and price, plus the purchase audit table.
 */
final class Version20260930141321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create booster_purchase, add booster.purchasable and booster.purchase_price';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booster_purchase (status VARCHAR(255) NOT NULL, coin_transaction_id VARCHAR(255) DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, price INT NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, discord_user_id VARCHAR NOT NULL, booster_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C4361BFBE3F3F7CE4D58BEDB ON booster_purchase (discord_user_id, requested_at)');
        $this->addSql('CREATE INDEX IDX_C4361BFB7B00651C ON booster_purchase (status)');
        $this->addSql('CREATE INDEX IDX_C4361BFBE3F3F7CE ON booster_purchase (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_C4361BFBF85E4930 ON booster_purchase (booster_id)');
        $this->addSql('ALTER TABLE booster_purchase ADD CONSTRAINT FK_C4361BFBE3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster_purchase ADD CONSTRAINT FK_C4361BFBF85E4930 FOREIGN KEY (booster_id) REFERENCES booster (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE booster ADD purchasable BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE booster ADD purchase_price INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster_purchase DROP CONSTRAINT FK_C4361BFBE3F3F7CE');
        $this->addSql('ALTER TABLE booster_purchase DROP CONSTRAINT FK_C4361BFBF85E4930');
        $this->addSql('DROP TABLE booster_purchase');
        $this->addSql('ALTER TABLE booster DROP purchasable');
        $this->addSql('ALTER TABLE booster DROP purchase_price');
    }
}
