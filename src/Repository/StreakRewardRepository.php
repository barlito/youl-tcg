<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\StreakReward;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<StreakReward>
 */
class StreakRewardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StreakReward::class);
    }

    /**
     * Rewards whose bonus booster has not been picked yet, oldest first.
     *
     * @return list<StreakReward>
     */
    public function findPending(DiscordUser $discordUser): array
    {
        return $this->createQueryBuilder('streakReward')
            ->andWhere('streakReward.discordUser = :discordUser')
            ->andWhere('streakReward.chosenBooster IS NULL')
            ->setParameter('discordUser', $discordUser)
            ->orderBy('streakReward.awardedAt', 'ASC')
            ->addOrderBy('streakReward.milestone', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Pessimistic write lock: picking the bonus booster is a check-then-credit
     * on the reward row, so two concurrent picks of the same reward must
     * serialize or both would credit a booster. Requires an active transaction.
     */
    public function findOneForUpdate(string $id, DiscordUser $discordUser): ?StreakReward
    {
        return $this->createQueryBuilder('streakReward')
            ->andWhere('streakReward.id = :id')
            ->andWhere('streakReward.discordUser = :discordUser')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('discordUser', $discordUser)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;
    }

    /**
     * Idempotent grant: ON CONFLICT on the (user, series, milestone) unique
     * index swallows duplicates — concurrent openings both reaching the same
     * milestone insert once, and a thrown unique violation (which would close
     * the EntityManager mid-request) never happens.
     */
    public function insertIgnore(DiscordUser $discordUser, string $seriesStartedOn, int $milestone, \DateTimeImmutable $awardedAt): void
    {
        $sql = <<<'SQL'
            INSERT INTO streak_reward (id, discord_user_id, series_started_on, milestone, awarded_at, chosen_booster_id, chosen_at, created_at, updated_at)
            VALUES (:id, :discordUserId, :seriesStartedOn, :milestone, :awardedAt, NULL, NULL, :awardedAt, :awardedAt)
            ON CONFLICT (discord_user_id, series_started_on, milestone) DO NOTHING
            SQL;

        $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'id' => Uuid::v7()->toRfc4122(),
            'discordUserId' => $discordUser->getDiscordId(),
            'seriesStartedOn' => $seriesStartedOn,
            'milestone' => $milestone,
            'awardedAt' => $awardedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }
}
