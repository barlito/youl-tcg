<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ExtensionBanner: hero banners of the universe pages. An extension can have
 * several, the page shows them as a carousel ordered by position.
 */
final class Version20260707150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'extension_banner table (universe page hero carousel)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE extension_banner (image_name VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, extension_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_40418044812D5EB ON extension_banner (extension_id)');
        $this->addSql('ALTER TABLE extension_banner ADD CONSTRAINT FK_40418044812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE extension_banner');
    }
}
