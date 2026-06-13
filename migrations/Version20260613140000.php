<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Holo refonte + visual config cascade:
 *  - per-slot holo chance folded into booster.rarity_rates (drops the global
 *    booster.holo_rate), migrating existing data;
 *  - card.always_holo flag;
 *  - per-card visual override (card.visual_config_override) and per-extension
 *    default visual config (extension.visual_config) + default foil/mask.
 */
final class Version20260613140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-slot holo chance in rarity_rates, always_holo flag, and the extension->card visual config cascade';
    }

    public function up(Schema $schema): void
    {
        // Read the current boosters before the schema changes drop holo_rate.
        $boosters = $this->connection->fetchAllAssociative('SELECT id, rarity_rates, holo_rate FROM booster');

        $this->addSql("ALTER TABLE card ADD always_holo BOOLEAN DEFAULT false NOT NULL");
        $this->addSql("ALTER TABLE card ADD visual_config_override JSON DEFAULT '{}' NOT NULL");
        $this->addSql("ALTER TABLE extension ADD image_foil_name VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE extension ADD image_mask_name VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE extension ADD visual_config JSON DEFAULT '{}' NOT NULL");

        foreach ($boosters as $booster) {
            $oldSlots = json_decode((string) $booster['rarity_rates'], true, flags: \JSON_THROW_ON_ERROR);
            $holoChance = (int) $booster['holo_rate'];

            $newSlots = array_map(
                static fn (array $rarities): array => ['rarities' => $rarities, 'holoChance' => $holoChance],
                \is_array($oldSlots) ? $oldSlots : [],
            );

            $this->addSql(
                'UPDATE booster SET rarity_rates = ? WHERE id = ?',
                [json_encode($newSlots, \JSON_THROW_ON_ERROR), $booster['id']],
            );
        }

        $this->addSql('ALTER TABLE booster DROP holo_rate');
    }

    public function down(Schema $schema): void
    {
        $boosters = $this->connection->fetchAllAssociative('SELECT id, rarity_rates FROM booster');

        $this->addSql("ALTER TABLE booster ADD holo_rate INT DEFAULT 10 NOT NULL");

        foreach ($boosters as $booster) {
            $newSlots = json_decode((string) $booster['rarity_rates'], true, flags: \JSON_THROW_ON_ERROR);
            $newSlots = \is_array($newSlots) ? $newSlots : [];

            $holoChance = isset($newSlots[0]['holoChance']) ? (int) $newSlots[0]['holoChance'] : 10;
            $oldSlots = array_map(
                static fn (array $slot): array => $slot['rarities'] ?? [],
                $newSlots,
            );

            $this->addSql(
                'UPDATE booster SET rarity_rates = ?, holo_rate = ? WHERE id = ?',
                [json_encode($oldSlots, \JSON_THROW_ON_ERROR), $holoChance, $booster['id']],
            );
        }

        $this->addSql('ALTER TABLE card DROP always_holo');
        $this->addSql('ALTER TABLE card DROP visual_config_override');
        $this->addSql('ALTER TABLE extension DROP image_foil_name');
        $this->addSql('ALTER TABLE extension DROP image_mask_name');
        $this->addSql('ALTER TABLE extension DROP visual_config');
    }
}
