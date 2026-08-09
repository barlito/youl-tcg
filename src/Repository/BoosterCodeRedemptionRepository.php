<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterCode;
use App\Entity\BoosterCodeRedemption;
use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterCodeRedemption>
 */
class BoosterCodeRedemptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterCodeRedemption::class);
    }

    public function existsFor(BoosterCode $boosterCode, DiscordUser $discordUser): bool
    {
        return (int) $this->createQueryBuilder('redemption')
            ->select('COUNT(redemption.id)')
            ->andWhere('redemption.boosterCode = :boosterCode')
            ->andWhere('redemption.discordUser = :discordUser')
            ->setParameter('boosterCode', $boosterCode)
            ->setParameter('discordUser', $discordUser)
            ->getQuery()
            ->getSingleScalarResult() > 0
        ;
    }
}
