<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240729170255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booster (id UUID NOT NULL, extension_id UUID NOT NULL, price INT NOT NULL, quantity INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EF769FAD812D5EB ON booster (extension_id)');
        $this->addSql('COMMENT ON COLUMN booster.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN booster.extension_id IS \'(DC2Type:uuid)\'');

        $this->addSql('CREATE TABLE card (id UUID NOT NULL, extension_id UUID DEFAULT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) NOT NULL, unique_flag BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_161498D3812D5EB ON card (extension_id)');
        $this->addSql('COMMENT ON COLUMN card.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN card.extension_id IS \'(DC2Type:uuid)\'');

        $this->addSql('CREATE TABLE discord_user (discord_id VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, PRIMARY KEY(discord_id))');

        $this->addSql('CREATE TABLE extension (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN extension.id IS \'(DC2Type:uuid)\'');

        $this->addSql('CREATE TABLE user_booster (discord_user_id VARCHAR(255) NOT NULL, booster_id UUID NOT NULL, quantity INT NOT NULL, PRIMARY KEY(discord_user_id, booster_id))');
        $this->addSql('CREATE INDEX IDX_B77B81A7E3F3F7CE ON user_booster (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_B77B81A7F85E4930 ON user_booster (booster_id)');
        $this->addSql('COMMENT ON COLUMN user_booster.booster_id IS \'(DC2Type:uuid)\'');

        $this->addSql('CREATE TABLE user_card (discord_user_id VARCHAR(255) NOT NULL, card_id UUID NOT NULL, quantity INT NOT NULL, PRIMARY KEY(discord_user_id, card_id))');
        $this->addSql('CREATE INDEX IDX_6C95D41AE3F3F7CE ON user_card (discord_user_id)');
        $this->addSql('CREATE INDEX IDX_6C95D41A4ACC9A20 ON user_card (card_id)');
        $this->addSql('COMMENT ON COLUMN user_card.card_id IS \'(DC2Type:uuid)\'');

        $this->addSql('ALTER TABLE booster ADD CONSTRAINT FK_EF769FAD812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_161498D3812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_booster ADD CONSTRAINT FK_B77B81A7E3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_booster ADD CONSTRAINT FK_B77B81A7F85E4930 FOREIGN KEY (booster_id) REFERENCES booster (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_card ADD CONSTRAINT FK_6C95D41AE3F3F7CE FOREIGN KEY (discord_user_id) REFERENCES discord_user (discord_id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_card ADD CONSTRAINT FK_6C95D41A4ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster DROP CONSTRAINT FK_EF769FAD812D5EB');
        $this->addSql('ALTER TABLE card DROP CONSTRAINT FK_161498D3812D5EB');
        $this->addSql('ALTER TABLE user_booster DROP CONSTRAINT FK_B77B81A7E3F3F7CE');
        $this->addSql('ALTER TABLE user_booster DROP CONSTRAINT FK_B77B81A7F85E4930');
        $this->addSql('ALTER TABLE user_card DROP CONSTRAINT FK_6C95D41AE3F3F7CE');
        $this->addSql('ALTER TABLE user_card DROP CONSTRAINT FK_6C95D41A4ACC9A20');
        $this->addSql('DROP TABLE booster');
        $this->addSql('DROP TABLE card');
        $this->addSql('DROP TABLE discord_user');
        $this->addSql('DROP TABLE extension');
        $this->addSql('DROP TABLE user_booster');
        $this->addSql('DROP TABLE user_card');
    }
}
