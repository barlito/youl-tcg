<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\RecycleOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecycleOperation>
 */
class RecycleOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecycleOperation::class);
    }

    public function existsSince(DiscordUser $discordUser, \DateTimeImmutable $since): bool
    {
        // Doctrine binds datetimes WITHOUT tz conversion: normalise to UTC (same instant) first
        $since = $since->setTimezone(new \DateTimeZone('UTC'));

        return (int) $this->createQueryBuilder('operation')
            ->select('COUNT(operation.id)')
            ->andWhere('operation.discordUser = :discordUser')
            ->andWhere('operation.recycledAt >= :since')
            ->setParameter('discordUser', $discordUser)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() > 0
        ;
    }
}
