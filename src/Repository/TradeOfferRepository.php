<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeOffer>
 */
class TradeOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeOffer::class);
    }

    /**
     * Pessimistic write lock on the offer row, so two concurrent resolutions
     * (accept vs cancel, double accept…) serialise. Requires an active
     * transaction; the caller must refresh() an already-hydrated entity.
     */
    public function findOneForUpdate(string $id): ?TradeOffer
    {
        return $this->createQueryBuilder('tradeOffer')
            ->andWhere('tradeOffer.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;
    }

    /**
     * Copies a proposer has engaged in their PENDING offers, per card: the
     * reservation ledger of the trade system. There is no mutable counter
     * anywhere — reservations are always recomputed from the pending lines.
     *
     * @return array<string, array{normal: int, holo: int}> card id => reserved copies
     */
    public function sumReservedQuantities(DiscordUser $proposer, ?TradeOffer $excluded = null): array
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(line.card) AS cardId', 'SUM(line.normalQuantity) AS normal', 'SUM(line.holoQuantity) AS holo')
            ->from(TradeOfferLine::class, 'line')
            ->join('line.tradeOffer', 'offer')
            ->andWhere('offer.proposer = :proposer')
            ->andWhere('offer.status = :status')
            ->andWhere('line.side = :side')
            ->setParameter('proposer', $proposer)
            ->setParameter('status', TradeOfferStatusEnum::PENDING->value)
            ->setParameter('side', TradeOfferSideEnum::OFFERED->value)
            ->groupBy('line.card')
        ;

        if ($excluded instanceof TradeOffer) {
            $queryBuilder
                ->andWhere('offer != :excluded')
                ->setParameter('excluded', $excluded)
            ;
        }

        /** @var list<array{cardId: mixed, normal: string|int, holo: string|int}> $rows */
        $rows = $queryBuilder->getQuery()->getResult();

        $reserved = [];
        foreach ($rows as $row) {
            $reserved[(string) $row['cardId']] = ['normal' => (int) $row['normal'], 'holo' => (int) $row['holo']];
        }

        return $reserved;
    }

    /**
     * Pending offers waiting for $receiver's answer, newest first.
     *
     * @return list<TradeOffer>
     */
    public function findPendingForReceiver(DiscordUser $receiver): array
    {
        return $this->findPending('receiver', $receiver);
    }

    /**
     * Pending offers $proposer is waiting an answer on, newest first.
     *
     * @return list<TradeOffer>
     */
    public function findPendingForProposer(DiscordUser $proposer): array
    {
        return $this->findPending('proposer', $proposer);
    }

    public function countPendingForReceiver(DiscordUser $receiver): int
    {
        return (int) $this->createQueryBuilder('tradeOffer')
            ->select('COUNT(tradeOffer.id)')
            ->andWhere('tradeOffer.receiver = :user')
            ->andWhere('tradeOffer.status = :pending')
            ->setParameter('user', $receiver)
            ->setParameter('pending', TradeOfferStatusEnum::PENDING->value)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Resolved offers the user took part in, whatever the side, latest first.
     *
     * @return list<TradeOffer>
     */
    public function findHistoryFor(DiscordUser $user, int $limit = 20): array
    {
        return array_values($this->withLines()
            ->andWhere('tradeOffer.proposer = :user OR tradeOffer.receiver = :user')
            ->andWhere('tradeOffer.status != :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', TradeOfferStatusEnum::PENDING->value)
            ->orderBy('tradeOffer.resolvedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /**
     * Atomically flips a PENDING offer to INVALIDATED: the status guard makes
     * the lock-free display-time cleanup safe against a concurrent resolution
     * (an offer accepted in-flight is never overwritten).
     *
     * @return bool true if this call invalidated the offer
     */
    public function markInvalidatedIfPending(TradeOffer $offer, \DateTimeImmutable $resolvedAt): bool
    {
        $affected = $this->createQueryBuilder('tradeOffer')
            ->update()
            ->set('tradeOffer.status', ':invalidated')
            ->set('tradeOffer.resolvedAt', ':resolvedAt')
            ->where('tradeOffer = :offer')
            ->andWhere('tradeOffer.status = :pending')
            ->setParameter('invalidated', TradeOfferStatusEnum::INVALIDATED->value)
            ->setParameter('resolvedAt', $resolvedAt)
            ->setParameter('offer', $offer)
            ->setParameter('pending', TradeOfferStatusEnum::PENDING->value)
            ->getQuery()
            ->execute()
        ;

        return 1 === $affected;
    }

    /**
     * @return list<TradeOffer>
     */
    private function findPending(string $field, DiscordUser $user): array
    {
        return array_values($this->withLines()
            ->andWhere(\sprintf('tradeOffer.%s = :user', $field))
            ->andWhere('tradeOffer.status = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', TradeOfferStatusEnum::PENDING->value)
            ->orderBy('tradeOffer.createdAt', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Offers with their lines, cards and both players hydrated: a trade list
     * renders every card of every offer, so the N+1 is not optional.
     */
    private function withLines(): QueryBuilder
    {
        return $this->createQueryBuilder('tradeOffer')
            ->leftJoin('tradeOffer.lines', 'line')
            ->addSelect('line')
            ->leftJoin('line.card', 'card')
            ->addSelect('card')
            ->leftJoin('card.extension', 'extension')
            ->addSelect('extension')
            ->join('tradeOffer.proposer', 'proposer')
            ->addSelect('proposer')
            ->join('tradeOffer.receiver', 'receiver')
            ->addSelect('receiver')
        ;
    }
}
