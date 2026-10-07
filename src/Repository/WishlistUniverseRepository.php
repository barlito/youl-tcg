<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\WishlistUniverse;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WishlistUniverse> */
class WishlistUniverseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistUniverse::class);
    }

    /** @return list<WishlistUniverse> published universes only, by name */
    public function findForPlayer(DiscordUser $player): array
    {
        /** @var list<WishlistUniverse> $rows */
        $rows = $this->createQueryBuilder('wu')
            ->addSelect('e')
            ->join('wu.extension', 'e')
            ->andWhere('wu.player = :player')
            ->andWhere('e.status = :status')
            ->setParameter('player', $player)
            ->setParameter('status', ExtensionStatusEnum::PUBLISHED)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $rows;
    }

    public function findOneFor(DiscordUser $player, Extension $extension): ?WishlistUniverse
    {
        return $this->findOneBy(['player' => $player, 'extension' => $extension]);
    }
}
