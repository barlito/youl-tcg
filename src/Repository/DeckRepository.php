<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Deck;
use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Deck> */
class DeckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deck::class);
    }

    /** @return list<Deck> oldest first, cards and terrain hydrated */
    public function findForOwner(DiscordUser $owner): array
    {
        /** @var list<Deck> $decks */
        $decks = $this->createQueryBuilder('d')
            ->addSelect('c', 't')
            ->leftJoin('d.cards', 'c')
            ->leftJoin('d.terrain', 't')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('d.createdAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $decks;
    }

    /**
     * Someone else's deck answers null exactly like an unknown id: existence never leaks.
     */
    public function findOneOwnedBy(string $id, DiscordUser $owner): ?Deck
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $deck = $this->find($id);

        return $deck instanceof Deck && $deck->isOwnedBy($owner) ? $deck : null;
    }

    public function countForOwner(DiscordUser $owner): int
    {
        return $this->count(['owner' => $owner]);
    }
}
