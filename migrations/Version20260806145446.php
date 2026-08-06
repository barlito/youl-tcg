<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806145446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extension logo upload (wordmark shown on the CSS card frame)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ADD logo_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP logo_name');
    }
}
