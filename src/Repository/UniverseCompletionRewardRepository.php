<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UniverseCompletionReward;
use App\Enum\Coin\UniverseRewardStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UniverseCompletionReward>
 */
class UniverseCompletionRewardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UniverseCompletionReward::class);
    }

    public function insertIgnore(DiscordUser $discordUser, Extension $extension, int $amount, \DateTimeImmutable $completedAt): ?UniverseCompletionReward
    {
        $id = Uuid::v7();
        $now = $completedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $status = 0 === $amount ? UniverseRewardStatusEnum::PAID : UniverseRewardStatusEnum::PENDING;

        // raw ON CONFLICT: a caught unique violation would close the EntityManager
        $inserted = 1 === (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO universe_completion_reward (id, discord_user_id, extension_id, amount, status, completed_at, paid_at, coin_transaction_id, created_at, updated_at)
                VALUES (:id, :discordUserId, :extensionId, :amount, :status, :now, :paidAt, NULL, :now, :now)
                ON CONFLICT (discord_user_id, extension_id) DO NOTHING
                SQL,
            [
                'id' => $id->toRfc4122(),
                'discordUserId' => $discordUser->getDiscordId(),
                'extensionId' => (string) $extension->getId(),
                'amount' => $amount,
                'status' => $status->value,
                'now' => $now,
                // nothing to pay: settled on the spot
                'paidAt' => 0 === $amount ? $now : null,
            ],
        );

        return $inserted ? $this->find($id) : null;
    }

    /**
     * @return array<string, true> "discordId|extensionId" keys
     */
    public function findRewardedPairs(): array
    {
        /** @var list<array{discordId: string, extensionId: string}> $rows */
        $rows = $this->createQueryBuilder('reward')
            ->select('IDENTITY(reward.discordUser) AS discordId', 'IDENTITY(reward.extension) AS extensionId')
            ->getQuery()
            ->getResult()
        ;

        $pairs = [];
        foreach ($rows as $row) {
            $pairs[$row['discordId'] . '|' . $row['extensionId']] = true;
        }

        return $pairs;
    }

    /**
     * @return list<UniverseCompletionReward>
     */
    public function findToPay(?DiscordUser $discordUser = null, ?\DateTimeImmutable $completedBefore = null, bool $includeFailed = false): array
    {
        $statuses = $includeFailed ? [UniverseRewardStatusEnum::PENDING, UniverseRewardStatusEnum::FAILED] : [UniverseRewardStatusEnum::PENDING];
        $queryBuilder = $this->createQueryBuilder('reward')
            ->andWhere('reward.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->orderBy('reward.completedAt', 'ASC')
        ;

        if ($discordUser instanceof DiscordUser) {
            $queryBuilder->andWhere('reward.discordUser = :discordUser')->setParameter('discordUser', $discordUser);
        }

        if ($completedBefore instanceof \DateTimeImmutable) {
            $queryBuilder->andWhere('reward.completedAt <= :before')->setParameter('before', $completedBefore->setTimezone(new \DateTimeZone('UTC')));
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
