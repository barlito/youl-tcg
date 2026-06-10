<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260610152808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booster rework: per-slot rarity_rates and holo_rate replace price/quantity (YoulCoin removal)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster ADD rarity_rates JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE booster ADD holo_rate INT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE booster DROP price');
        $this->addSql('ALTER TABLE booster DROP quantity');
        $this->addSql('ALTER TABLE booster ALTER image_name DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booster ADD price INT NOT NULL');
        $this->addSql('ALTER TABLE booster ADD quantity INT NOT NULL');
        $this->addSql('ALTER TABLE booster DROP rarity_rates');
        $this->addSql('ALTER TABLE booster DROP holo_rate');
        $this->addSql('ALTER TABLE booster ALTER image_name SET NOT NULL');
    }
}
