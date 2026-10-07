<?php

declare(strict_types=1);

namespace App\Service\Collection;

use App\Dto\CollectionTagGroup;
use App\Dto\ProfileComparison;
use App\Entity\UserCard;
use App\Service\Card\CardTags;

/**
 * Tag filter chips of « Ma collection », built from the OWNED cards only (a masked card shows no tag).
 */
final class CollectionTagFilter
{
    /**
     * @return list<CollectionTagGroup>
     */
    public function groups(ProfileComparison $comparison): array
    {
        $counts = [];
        foreach ($comparison->universes as $universe) {
            foreach ($universe->cards as $item) {
                if (!$item->profileCard instanceof UserCard) {
                    continue;
                }
                foreach ($item->card->getTags() as $tag) {
                    $counts[CardTags::family($tag)][$tag] = ($counts[CardTags::family($tag)][$tag] ?? 0) + 1;
                }
            }
        }

        uksort($counts, CardTags::compareFamilies(...));

        $groups = [];
        foreach ($counts as $family => $tags) {
            ksort($tags);
            $groups[] = new CollectionTagGroup(
                family: $family,
                label: CardTags::familyLabel($family),
                tags: array_map(
                    static fn (string $tag, int $count): array => ['tag' => $tag, 'label' => CardTags::valueLabel($tag), 'count' => $count],
                    array_map(strval(...), array_keys($tags)),
                    array_values($tags),
                ),
            );
        }

        return $groups;
    }

    /**
     * The ?tag= query value when it is a well-formed tag, null otherwise (ignored).
     */
    public function parse(string $value): ?string
    {
        $tag = strtolower(trim($value));

        return CardTags::isValid($tag) ? $tag : null;
    }
}
