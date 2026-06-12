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
