<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017001154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trade offer lines remember whether the taker owned the card before the acceptance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_offer_line ADD new_for_taker BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_offer_line DROP new_for_taker');
    }
}
