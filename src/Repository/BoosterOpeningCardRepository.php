<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterOpeningCard;
use App\Entity\DiscordUser;
use App\Enum\Entity\CardRarityEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterOpeningCard>
 */
class BoosterOpeningCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterOpeningCard::class);
    }

    /**
     * Every card ever pulled from a booster, duplicates included. quantity
     * already counts holo copies (holoQuantity is a sub-count, not an extra).
     */
    public function countPulledCards(): int
    {
        return (int) $this->createQueryBuilder('oc')
            ->select('COALESCE(SUM(oc.quantity), 0)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Copies the user pulled overall (quantity already includes the holo ones).
     *
     * @return array{cards: int, holos: int}
     */
    public function sumPulledForUser(DiscordUser $user): array
    {
        /** @var array{cards: int|string, holos: int|string} $row */
        $row = $this->createQueryBuilder('oc')
            ->select('COALESCE(SUM(oc.quantity), 0) AS cards', 'COALESCE(SUM(oc.holoQuantity), 0) AS holos')
            ->join('oc.boosterOpening', 'o')
            ->where('o.discordUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult()
        ;

        return ['cards' => (int) $row['cards'], 'holos' => (int) $row['holos']];
    }

    /**
     * @return array<string, int> rarity value => copies pulled by this user
     */
    public function countPerRarityForUser(DiscordUser $user): array
    {
        /** @var list<array{rarity: CardRarityEnum|string, cards: int|string}> $rows */
        $rows = $this->createQueryBuilder('oc')
            ->select('c.rarity AS rarity', 'SUM(oc.quantity) AS cards')
            ->join('oc.boosterOpening', 'o')
            ->join('oc.card', 'c')
            ->where('o.discordUser = :user')
            ->setParameter('user', $user)
            ->groupBy('c.rarity')
            ->getQuery()
            ->getArrayResult()
        ;

        $counts = [];

        foreach ($rows as $row) {
            $rarity = $row['rarity'] instanceof CardRarityEnum ? $row['rarity']->value : $row['rarity'];
            $counts[$rarity] = (int) $row['cards'];
        }

        return $counts;
    }

    /**
     * Per-booster pull aggregate for the user, one row per (booster, rarity).
     *
     * @return array<string, array{cards: int, holos: int, rarities: array<string, int>}> keyed by booster id
     */
    public function aggregatePerBoosterForUser(DiscordUser $user): array
    {
        /** @var list<array{boosterId: string, rarity: CardRarityEnum|string, cards: int|string, holos: int|string}> $rows */
        $rows = $this->createQueryBuilder('oc')
            ->select(
                'IDENTITY(o.booster) AS boosterId',
                'c.rarity AS rarity',
                'SUM(oc.quantity) AS cards',
                'SUM(oc.holoQuantity) AS holos',
            )
            ->join('oc.boosterOpening', 'o')
            ->join('oc.card', 'c')
            ->where('o.discordUser = :user')
            ->setParameter('user', $user)
            ->groupBy('o.booster', 'c.rarity')
            ->getQuery()
            ->getArrayResult()
        ;

        $aggregated = [];

        foreach ($rows as $row) {
            $boosterId = $row['boosterId'];
            $rarity = $row['rarity'] instanceof CardRarityEnum ? $row['rarity']->value : $row['rarity'];

            $aggregated[$boosterId] ??= ['cards' => 0, 'holos' => 0, 'rarities' => []];
            $aggregated[$boosterId]['cards'] += (int) $row['cards'];
            $aggregated[$boosterId]['holos'] += (int) $row['holos'];
            $aggregated[$boosterId]['rarities'][$rarity] = (int) $row['cards'];
        }

        return $aggregated;
    }

    /**
     * The earliest pull of the rarest tier the user ever reached, with the
     * card, opening and booster ready to display.
     */
    public function findBestPullForUser(DiscordUser $user): ?BoosterOpeningCard
    {
        // rank the enum column in DQL so "rarest first" happens in SQL, not in PHP
        $rarityRank = 'CASE ' . implode(' ', array_map(
            static fn (CardRarityEnum $rarity): string => \sprintf("WHEN c.rarity = '%s' THEN %d", $rarity->value, $rarity->rank()),
            CardRarityEnum::ascending(),
        )) . ' ELSE -1 END';

        return $this->createQueryBuilder('oc')
            ->addSelect('o', 'c', 'b', $rarityRank . ' AS HIDDEN rarityRank')
            ->join('oc.boosterOpening', 'o')
            ->join('oc.card', 'c')
            ->join('o.booster', 'b')
            ->where('o.discordUser = :user')
            ->setParameter('user', $user)
            ->orderBy('rarityRank', 'DESC')
            ->addOrderBy('o.openedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
