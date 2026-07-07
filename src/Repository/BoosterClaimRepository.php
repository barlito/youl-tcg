<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterClaim;
use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterClaim>
 */
class BoosterClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterClaim::class);
    }

    public function countSince(DiscordUser $discordUser, \DateTimeImmutable $since): int
    {
        // Doctrine binds datetimes WITHOUT timezone conversion (the value is
        // formatted in its own tz): a "midnight Europe/Paris" boundary would
        // reach the database 1-2h ahead of the UTC-stored claimed_at
        // timestamps — making the daily quota bypassable between 00:00 and
        // 02:00 Paris. Normalise to UTC (same instant) before binding.
        $since = $since->setTimezone(new \DateTimeZone('UTC'));

        return (int) $this->createQueryBuilder('claim')
            ->select('COUNT(claim.id)')
            ->andWhere('claim.discordUser = :discordUser')
            ->andWhere('claim.claimedAt >= :since')
            ->setParameter('discordUser', $discordUser)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }
}
