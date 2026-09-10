<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordUser>
 */
class DiscordUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordUser::class);
    }

    /**
     * Everyone but the given player, for the trade counterpart picker.
     *
     * @return list<DiscordUser>
     */
    public function findOthersOrderedByUsername(DiscordUser $user): array
    {
        return array_values($this->createQueryBuilder('discordUser')
            ->andWhere('discordUser.discordId != :self')
            ->setParameter('self', $user->getDiscordId())
            ->orderBy('LOWER(discordUser.username)', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
