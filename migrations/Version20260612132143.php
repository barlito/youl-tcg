<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612132143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'card.image_name nullable (Vich pattern: a card can exist before its upload)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ALTER image_name DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ALTER image_name SET NOT NULL');
    }
}
