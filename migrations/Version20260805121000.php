<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes card(extension_id, status) and booster_claim(discord_user_id, claimed_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_161498D3812D5EB7B00651C ON card (extension_id, status)');
        $this->addSql('CREATE INDEX IDX_376FE897E3F3F7CE7CF00E05 ON booster_claim (discord_user_id, claimed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_161498D3812D5EB7B00651C');
        $this->addSql('DROP INDEX IDX_376FE897E3F3F7CE7CF00E05');
    }
}
