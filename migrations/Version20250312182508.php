<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250312182508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make Extension Entity image_name field nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ALTER image_name DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ALTER image_name SET NOT NULL');
    }
}
