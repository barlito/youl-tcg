<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the "epic" rarity tier (the scale is now 4 tiers: common → legendary;
 * one-of-one cards are carried by Card.uniqueFlag, not a rarity). Pure data
 * migration — `rarity` is a plain varchar, so there is no DB enum to alter:
 *  - every Card with rarity "epic" becomes "rare";
 *  - in every Booster.rarityRates slot, the "epic" weight is folded into "rare".
 *
 * Irreversible: the merge loses which rows/weights were originally epic.
 */
final class Version20260618120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the "epic" rarity tier (merge into "rare")';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE card SET rarity = 'rare' WHERE rarity = 'epic'");

        $boosters = $this->connection->fetchAllAssociative('SELECT id, rarity_rates FROM booster');
        foreach ($boosters as $booster) {
            $slots = json_decode((string) $booster['rarity_rates'], true);
            if (!is_array($slots)) {
                continue;
            }

            $changed = false;
            foreach ($slots as &$slot) {
                if (!isset($slot['rarities']) || !is_array($slot['rarities']) || !isset($slot['rarities']['epic'])) {
                    continue;
                }
                $slot['rarities']['rare'] = ($slot['rarities']['rare'] ?? 0) + $slot['rarities']['epic'];
                unset($slot['rarities']['epic']);
                $changed = true;
            }
            unset($slot);

            if ($changed) {
                $this->addSql(
                    'UPDATE booster SET rarity_rates = ? WHERE id = ?',
                    [json_encode($slots, JSON_THROW_ON_ERROR), $booster['id']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigration('Merging the "epic" tier into "rare" cannot be reversed.');
    }
}
