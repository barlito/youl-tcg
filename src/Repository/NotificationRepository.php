<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * The player's own notifications plus every broadcast, newest first.
     *
     * @return list<Notification>
     */
    public function findLatestFor(DiscordUser $discordUser, int $limit): array
    {
        return $this->visibleTo($discordUser)
            ->orderBy('notification.createdAt', 'DESC')
            ->addOrderBy('notification.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    public function countUnreadFor(DiscordUser $discordUser): int
    {
        return (int) $this->createQueryBuilder('notification')
            ->select('COUNT(notification.id)')
            ->andWhere('(notification.recipient = :viewer AND notification.readAt IS NULL) OR (notification.recipient IS NULL AND notification.createdAt > :seenSince)')
            ->setParameter('viewer', $discordUser)
            ->setParameter('seenSince', $this->utc($discordUser->getNotificationsSeenSince()))
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Only the player's own unread entries: broadcasts are covered by
     * DiscordUser::$notificationsSeenAt.
     */
    public function markAllReadFor(DiscordUser $discordUser, \DateTimeImmutable $readAt): void
    {
        $this->createQueryBuilder('notification')
            ->update()
            ->set('notification.readAt', ':readAt')
            ->andWhere('notification.recipient = :viewer')
            ->andWhere('notification.readAt IS NULL')
            ->setParameter('readAt', $this->utc($readAt))
            ->setParameter('viewer', $discordUser)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Resolves an id the player may act on: their own entry or a broadcast.
     * Someone else's notification resolves to null, like an unknown id.
     */
    public function findOneVisibleTo(DiscordUser $discordUser, string $id): ?Notification
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->visibleTo($discordUser)
            ->andWhere('notification.id = :id')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    private function visibleTo(DiscordUser $discordUser): QueryBuilder
    {
        return $this->createQueryBuilder('notification')
            ->andWhere('notification.recipient = :viewer OR notification.recipient IS NULL')
            ->setParameter('viewer', $discordUser)
        ;
    }

    /**
     * Doctrine binds datetimes without converting their timezone: normalize
     * to UTC (the storage convention) at the repository boundary.
     */
    private function utc(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'));
    }
}
