<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\MarketListing;
use App\Entity\WishlistEntry;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WishlistEntry> */
class WishlistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistEntry::class);
    }

    /** @return list<WishlistEntry> newest first, published cards only */
    public function findForPlayer(DiscordUser $player): array
    {
        /** @var list<WishlistEntry> $entries */
        $entries = $this->createQueryBuilder('w')
            ->addSelect('c', 'e')
            ->join('w.card', 'c')
            ->join('c.extension', 'e')
            ->andWhere('w.player = :player')
            ->andWhere('c.status = :cardStatus')
            ->andWhere('e.status = :extensionStatus')
            ->setParameter('player', $player)
            ->setParameter('cardStatus', CardStatusEnum::PUBLISHED)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED)
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $entries;
    }

    public function countForPlayer(DiscordUser $player): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.player = :player')
            ->setParameter('player', $player)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function findOneFor(DiscordUser $player, Card $card): ?WishlistEntry
    {
        return $this->findOneBy(['player' => $player, 'card' => $card]);
    }

    /** @return array<string, true> card id => wished (direct wishes only) */
    public function findWishedCardIds(DiscordUser $player): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT card_id FROM wishlist_entry WHERE player_id = :player',
            ['player' => $player->getDiscordId()],
        );

        return array_fill_keys(array_map(strval(...), $ids), true);
    }

    /** @param list<string> $cardIds */
    public function deleteForCards(DiscordUser $player, array $cardIds): int
    {
        if ([] === $cardIds) {
            return 0;
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM wishlist_entry WHERE player_id = :player AND card_id IN (:cards)',
            ['player' => $player->getDiscordId(), 'cards' => $cardIds],
            ['cards' => ArrayParameterType::STRING],
        );
    }

    /**
     * Which of the given cards the player is looking for: a direct wish, or a
     * card they do not own in a watched universe.
     *
     * @param list<string> $cardIds
     *
     * @return array<string, true>
     */
    public function findWantedAmong(DiscordUser $wanter, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            \sprintf('SELECT c.id FROM card c JOIN extension e ON e.id = c.extension_id WHERE c.id IN (:cards) AND c.status = :cardStatus AND e.status = :extensionStatus AND (%s)', $this->wantsClause('c', ':wanter')),
            ['cards' => $cardIds, 'wanter' => $wanter->getDiscordId(), 'cardStatus' => CardStatusEnum::PUBLISHED->value, 'extensionStatus' => ExtensionStatusEnum::PUBLISHED->value],
            ['cards' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map(strval(...), $ids), true);
    }

    /**
     * Players a new listing is relevant to (never its seller), `direct` when
     * they wished that very card. Dedup against alerts already sent is done
     * by the caller's insert.
     *
     * @return list<array{id: string, direct: bool}>
     */
    public function findListingAudience(MarketListing $listing): array
    {
        /** @var list<array{discord_id: string, direct: bool}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT p.discord_id,
                    EXISTS (SELECT 1 FROM wishlist_entry w WHERE w.player_id = p.discord_id AND w.card_id = :card) AS direct
             FROM discord_user p
             JOIN card c ON c.id = :card
             WHERE p.discord_id <> :seller AND (' . $this->wantsClause('c', 'p.discord_id') . ')
             ORDER BY p.discord_id',
            ['card' => (string) $listing->getCard()->getId(), 'seller' => $listing->getSeller()->getDiscordId()],
        );

        return array_map(static fn (array $row): array => ['id' => (string) $row['discord_id'], 'direct' => (bool) $row['direct']], $rows);
    }

    private function wantsClause(string $card, string $player): string
    {
        return <<<SQL
            EXISTS (SELECT 1 FROM wishlist_entry w WHERE w.player_id = {$player} AND w.card_id = {$card}.id)
            OR (
                EXISTS (SELECT 1 FROM wishlist_universe u WHERE u.player_id = {$player} AND u.extension_id = {$card}.extension_id)
                AND NOT EXISTS (SELECT 1 FROM user_card uc WHERE uc.discord_user_id = {$player} AND uc.card_id = {$card}.id AND (uc.quantity > 0 OR uc.holo_quantity > 0))
            )
            SQL;
    }
}
