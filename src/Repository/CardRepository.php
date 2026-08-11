<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * Extension ids that have at least one published card, i.e. extensions a
     * booster can actually draw from.
     *
     * @return list<string>
     */
    public function findExtensionIdsWithPublishedCards(): array
    {
        // Same drawability rule as findDrawablePool (a claimed 1/1 unique is not
        // drawable): the hub's "ouvrable" state and the opening page must agree
        // with what CardDrawer can actually draw, or an extension left with only
        // claimed uniques would show "Ouvrir" and then fail the draw.
        return array_map(static fn (mixed $id): string => (string) $id, array_values($this->createQueryBuilder('c')
            ->select('IDENTITY(c.extension) AS extensionId')
            ->andWhere('c.status = :status')
            ->andWhere('c.uniqueFlag = false OR c.claimedBy IS NULL')
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->distinct()
            ->getQuery()
            ->getSingleColumnResult()));
    }

    /**
     * Published cards of an extension — the "set contents" shown in the opening
     * aside. Ordering by rarity is handled in the component (CardRarityEnum rank).
     *
     * @return list<Card>
     */
    public function findPublishedByExtension(Extension $extension): array
    {
        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.extension = :extension')
            ->andWhere('c.status = :status')
            ->setParameter('extension', $extension)
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The whole published catalogue (published cards of published extensions),
     * grouped by extension — the reference set the compared profile grid is
     * built on. One query, extensions hydrated by the join.
     *
     * @return list<array{extension: Extension, cards: list<Card>}>
     */
    public function findPublishedGroupedByExtension(): array
    {
        /** @var list<Card> $cards */
        $cards = $this->createQueryBuilder('c')
            ->join('c.extension', 'e')
            ->addSelect('e')
            ->andWhere('c.status = :cardStatus')
            ->andWhere('e.status = :extensionStatus')
            ->setParameter('cardStatus', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->orderBy('e.name', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        $groups = [];
        foreach ($cards as $card) {
            $extension = $card->getExtension();
            if (!$extension instanceof Extension) {
                continue;
            }

            $extensionId = (string) $extension->getId();
            $groups[$extensionId] ??= ['extension' => $extension, 'cards' => []];
            $groups[$extensionId]['cards'][] = $card;
        }

        // rarity is a string-backed enum: rank it in PHP, name already sorted by SQL
        foreach ($groups as $extensionId => $group) {
            $groupCards = $group['cards'];
            usort(
                $groupCards,
                static fn (Card $a, Card $b): int => CardRarityEnum::compareRarestFirst($a->getRarity(), $b->getRarity()),
            );
            $groups[$extensionId]['cards'] = $groupCards;
        }

        return array_values($groups);
    }

    /**
     * The draw pool of an extension: published cards, EXCLUDING one-of-one
     * unique cards that are already claimed (so a claimed unique can never be
     * drawn again). Available (unclaimed) uniques stay in the pool.
     *
     * @return list<Card>
     */
    public function findDrawablePool(Extension $extension): array
    {
        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.extension = :extension')
            ->andWhere('c.status = :status')
            ->andWhere('c.uniqueFlag = false OR c.claimedBy IS NULL')
            ->setParameter('extension', $extension)
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->getQuery()
            ->getResult());
    }

    /**
     * Atomically claims a one-of-one unique card for a user: a single
     * conditional UPDATE that only succeeds while the card is still unclaimed.
     * Two concurrent openings serialise on the row, so exactly one wins.
     *
     * @return bool true if this call claimed the card, false if it was already taken
     */
    public function claimUnique(Card $card, DiscordUser $discordUser): bool
    {
        $affected = $this->createQueryBuilder('c')
            ->update()
            ->set('c.claimedBy', ':owner')
            ->where('c = :card')
            ->andWhere('c.uniqueFlag = true')
            ->andWhere('c.claimedBy IS NULL')
            ->setParameter('owner', $discordUser)
            ->setParameter('card', $card)
            ->getQuery()
            ->execute()
        ;

        return 1 === $affected;
    }

    /**
     * How many one-of-one uniques each player holds (leaderboard stat): the
     * count only — WHICH uniques someone holds stays a mystery on purpose.
     *
     * @return array<string, int> discord id => claimed uniques
     */
    public function countClaimedUniquesByUser(): array
    {
        /** @var list<array{userId: string, uniqueCount: string|int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.claimedBy) AS userId', 'COUNT(c.id) AS uniqueCount')
            ->andWhere('c.claimedBy IS NOT NULL')
            ->groupBy('c.claimedBy')
            ->getQuery()
            ->getResult()
        ;

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['userId']] = (int) $row['uniqueCount'];
        }

        return $counts;
    }

    /**
     * Cover artwork of the "next universe" teaser: the extension's rarest
     * card, DRAFTS INCLUDED — a teased universe is unpublished, so its cards
     * usually are too (the tile blurs the artwork anyway).
     */
    public function findTeaserCoverImageName(Extension $extension): ?string
    {
        /** @var list<array{rarity: CardRarityEnum|string, imageName: string, name: string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.rarity AS rarity', 'c.imageName AS imageName', 'c.name AS name')
            ->andWhere('c.extension = :extension')
            ->andWhere('c.imageName IS NOT NULL')
            ->setParameter('extension', $extension)
            ->getQuery()
            ->getArrayResult()
        ;

        $cover = null;
        $bestKey = null;
        foreach ($rows as $row) {
            $rarity = $row['rarity'] instanceof CardRarityEnum ? $row['rarity'] : CardRarityEnum::tryFrom($row['rarity']);
            $key = [-($rarity?->rank() ?? -1), $row['name']];

            if (null === $bestKey || $key < $bestKey) {
                $bestKey = $key;
                $cover = $row['imageName'];
            }
        }

        return $cover;
    }

    /**
     * Cover artwork per extension: the image of each extension's rarest
     * published card (name as tiebreak, so the pick is deterministic). One
     * portable query, the "rarest" pick happens in PHP — no DISTINCT ON,
     * the test database is SQLite.
     *
     * @return array<string, string> extension id => card image name
     */
    public function findCoverImageNamesByExtension(): array
    {
        /** @var list<array{extensionId: mixed, rarity: CardRarityEnum|string, imageName: string, name: string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.extension) AS extensionId', 'c.rarity AS rarity', 'c.imageName AS imageName', 'c.name AS name')
            ->andWhere('c.status = :status')
            ->andWhere('c.imageName IS NOT NULL')
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult()
        ;

        $covers = [];
        $bestKeys = [];
        foreach ($rows as $row) {
            $extensionId = (string) $row['extensionId'];
            $rarity = $row['rarity'] instanceof CardRarityEnum ? $row['rarity'] : CardRarityEnum::tryFrom($row['rarity']);
            // rarest first, then name ascending: comparable sort keys
            $key = [-($rarity?->rank() ?? -1), $row['name']];

            if (!isset($bestKeys[$extensionId]) || $key < $bestKeys[$extensionId]) {
                $bestKeys[$extensionId] = $key;
                $covers[$extensionId] = $row['imageName'];
            }
        }

        return $covers;
    }

    /**
     * @return list<mixed> published card ids
     */
    public function findRandomCardId(int $maxResult): array
    {
        return array_values($this->createQueryBuilder('c')
            ->select('c.id')
            ->join('c.extension', 'e')
            ->andWhere('c.status = :status')
            ->andWhere('e.status = :extensionStatus')
            ->andWhere('c.uniqueFlag = false')
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->orderBy('RANDOM()')
            ->setMaxResults($maxResult)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
