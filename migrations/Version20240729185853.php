<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240729185853 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster ADD image_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE booster ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE booster ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');

        $this->addSql('ALTER TABLE card ADD image_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE card ADD image_mask_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE card ADD image_foil_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE card ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE card ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');

        $this->addSql('ALTER TABLE discord_user ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE discord_user ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');

        $this->addSql('ALTER TABLE extension ADD image_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE extension ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE extension ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');

        $this->addSql('ALTER TABLE user_booster ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE user_booster ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');

        $this->addSql('ALTER TABLE user_card ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE user_card ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP image_name');
        $this->addSql('ALTER TABLE extension DROP created_at');
        $this->addSql('ALTER TABLE extension DROP updated_at');
        $this->addSql('ALTER TABLE discord_user DROP created_at');
        $this->addSql('ALTER TABLE discord_user DROP updated_at');
        $this->addSql('ALTER TABLE card DROP image_name');
        $this->addSql('ALTER TABLE card DROP image_mask_name');
        $this->addSql('ALTER TABLE card DROP image_foil_name');
        $this->addSql('ALTER TABLE card DROP created_at');
        $this->addSql('ALTER TABLE card DROP updated_at');
        $this->addSql('ALTER TABLE user_card DROP created_at');
        $this->addSql('ALTER TABLE user_card DROP updated_at');
        $this->addSql('ALTER TABLE user_booster DROP created_at');
        $this->addSql('ALTER TABLE user_booster DROP updated_at');
        $this->addSql('ALTER TABLE booster DROP image_name');
        $this->addSql('ALTER TABLE booster DROP created_at');
        $this->addSql('ALTER TABLE booster DROP updated_at');
    }
}
