<?php

declare(strict_types=1);

namespace App\Service\Collection;

use App\Dto\CollectionQuery;
use App\Dto\CollectionSection;
use App\Dto\CollectionView;
use App\Dto\ProfileCardComparison;
use App\Dto\ProfileComparison;
use App\Entity\UserCard;
use App\Enum\Collection\CollectionFinishEnum;
use App\Enum\Collection\CollectionPossessionEnum;
use App\Enum\Collection\CollectionSortEnum;
use App\Enum\Entity\CardRarityEnum;

/**
 * Applies the collection url filters to the player's own comparison, in PHP:
 * the catalogue is already loaded, no query here.
 *
 * Masking: a card the player does not own is rendered face down, so the name
 * search never matches it (otherwise typing a name would confirm a hidden
 * card) and the name sort never orders it by name — missing cards always
 * follow the owned ones in catalogue order.
 */
final readonly class CollectionBrowser
{
    public function browse(ProfileComparison $comparison, CollectionQuery $query): CollectionView
    {
        $sections = [];
        $flat = [];
        $count = 0;

        foreach ($comparison->universes as $universe) {
            $items = array_values(array_filter(
                $universe->cards,
                fn (ProfileCardComparison $item): bool => $this->matches($item, $query),
            ));

            if ([] === $items) {
                continue;
            }

            $count += \count($items);

            if (CollectionSortEnum::UNIVERSE === $query->sort) {
                $sections[] = new CollectionSection($universe, $items);
            } else {
                array_push($flat, ...$items);
            }
        }

        if (CollectionSortEnum::UNIVERSE !== $query->sort && [] !== $flat) {
            $sections[] = new CollectionSection(null, $this->sort($flat, $query->sort));
        }

        return new CollectionView($query, $sections, $count);
    }

    private function matches(ProfileCardComparison $item, CollectionQuery $query): bool
    {
        $owned = $this->ownedRow($item);

        if (CollectionPossessionEnum::OWNED === $query->possession && !$owned instanceof UserCard) {
            return false;
        }

        if (CollectionPossessionEnum::MISSING === $query->possession && $owned instanceof UserCard) {
            return false;
        }

        if ($query->rarity instanceof CardRarityEnum && $item->card->getRarity() !== $query->rarity) {
            return false;
        }

        if ($query->finish instanceof CollectionFinishEnum) {
            if (!$owned instanceof UserCard) {
                return false;
            }

            $hasFinish = CollectionFinishEnum::HOLO === $query->finish
                ? $owned->getHoloQuantity() > 0
                : $owned->getQuantity() > $owned->getHoloQuantity();

            if (!$hasFinish) {
                return false;
            }
        }

        if ('' !== $query->search) {
            // a face-down card never matches: no oracle on hidden names
            return $owned instanceof UserCard && false !== mb_stripos($item->card->getName(), $query->search);
        }

        return true;
    }

    /**
     * @param list<ProfileCardComparison> $items catalogue order (universe, rarest first, name)
     *
     * @return list<ProfileCardComparison>
     */
    private function sort(array $items, CollectionSortEnum $sort): array
    {
        $position = array_flip(array_map(spl_object_id(...), $items));

        usort($items, function (ProfileCardComparison $a, ProfileCardComparison $b) use ($sort, $position): int {
            $ownedA = $this->ownedRow($a);
            $ownedB = $this->ownedRow($b);
            $catalogue = $position[spl_object_id($a)] <=> $position[spl_object_id($b)];

            if (CollectionSortEnum::RARITY === $sort) {
                return CardRarityEnum::compareRarestFirst($a->card->getRarity(), $b->card->getRarity())
                    ?: ($ownedB instanceof UserCard) <=> ($ownedA instanceof UserCard)
                    ?: $catalogue;
            }

            // name / quantity: owned cards first, the face-down ones keep the catalogue order
            if (!$ownedA instanceof UserCard || !$ownedB instanceof UserCard) {
                return ($ownedB instanceof UserCard) <=> ($ownedA instanceof UserCard) ?: $catalogue;
            }

            $byName = strnatcasecmp($a->card->getName(), $b->card->getName());

            return CollectionSortEnum::QUANTITY === $sort
                ? ($ownedB->getQuantity() <=> $ownedA->getQuantity() ?: $byName ?: $catalogue)
                : ($byName ?: $catalogue);
        });

        return $items;
    }

    private function ownedRow(ProfileCardComparison $item): ?UserCard
    {
        $row = $item->profileCard;

        return $row instanceof UserCard && ($row->getQuantity() > 0 || $row->getHoloQuantity() > 0) ? $row : null;
    }
}
