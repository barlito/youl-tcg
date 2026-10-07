<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wishlist and market alerts, behind the wishlist feature flag shipped OFF.
 */
final class Version20261008090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wishlist entries, universe watches, alert ledger and the wishlist feature flag, disabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wishlist_alert (created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id VARCHAR NOT NULL, listing_id UUID NOT NULL, PRIMARY KEY (player_id, listing_id))');
        $this->addSql('CREATE INDEX IDX_43582E3999E6F5DF ON wishlist_alert (player_id)');
        $this->addSql('CREATE INDEX IDX_43582E39D4619D1A ON wishlist_alert (listing_id)');
        $this->addSql('CREATE TABLE wishlist_entry (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id VARCHAR NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7F84F5884ACC9A20 ON wishlist_entry (card_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7F84F58899E6F5DF4ACC9A20 ON wishlist_entry (player_id, card_id)');
        $this->addSql('CREATE INDEX IDX_7F84F58899E6F5DF ON wishlist_entry (player_id)');
        $this->addSql('CREATE TABLE wishlist_universe (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id VARCHAR NOT NULL, extension_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D17AACC1812D5EB ON wishlist_universe (extension_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D17AACC199E6F5DF812D5EB ON wishlist_universe (player_id, extension_id)');
        $this->addSql('CREATE INDEX IDX_D17AACC199E6F5DF ON wishlist_universe (player_id)');
        $this->addSql('ALTER TABLE wishlist_alert ADD CONSTRAINT FK_43582E3999E6F5DF FOREIGN KEY (player_id) REFERENCES discord_user (discord_id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE wishlist_alert ADD CONSTRAINT FK_43582E39D4619D1A FOREIGN KEY (listing_id) REFERENCES market_listing (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE wishlist_entry ADD CONSTRAINT FK_7F84F58899E6F5DF FOREIGN KEY (player_id) REFERENCES discord_user (discord_id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE wishlist_entry ADD CONSTRAINT FK_7F84F5884ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE wishlist_universe ADD CONSTRAINT FK_D17AACC199E6F5DF FOREIGN KEY (player_id) REFERENCES discord_user (discord_id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE wishlist_universe ADD CONSTRAINT FK_D17AACC1812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("INSERT INTO feature_flag (name, enabled, created_at, updated_at) VALUES ('wishlist', false, NOW(), NOW()) ON CONFLICT (name) DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'wishlist'");
        $this->addSql('ALTER TABLE wishlist_alert DROP CONSTRAINT FK_43582E3999E6F5DF');
        $this->addSql('ALTER TABLE wishlist_alert DROP CONSTRAINT FK_43582E39D4619D1A');
        $this->addSql('ALTER TABLE wishlist_entry DROP CONSTRAINT FK_7F84F58899E6F5DF');
        $this->addSql('ALTER TABLE wishlist_entry DROP CONSTRAINT FK_7F84F5884ACC9A20');
        $this->addSql('ALTER TABLE wishlist_universe DROP CONSTRAINT FK_D17AACC199E6F5DF');
        $this->addSql('ALTER TABLE wishlist_universe DROP CONSTRAINT FK_D17AACC1812D5EB');
        $this->addSql('DROP TABLE wishlist_alert');
        $this->addSql('DROP TABLE wishlist_entry');
        $this->addSql('DROP TABLE wishlist_universe');
    }
}
