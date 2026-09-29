<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Entity\NotificationBroadcastRead;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationBroadcastRead>
 */
class NotificationBroadcastReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationBroadcastRead::class);
    }

    /**
     * Raw ON CONFLICT: two tabs opening the same broadcast must not raise a
     * unique violation (it would close the EntityManager).
     */
    public function insertIgnore(Notification $notification, DiscordUser $discordUser, \DateTimeImmutable $readAt): void
    {
        $sql = <<<'SQL'
            INSERT INTO notification_broadcast_read (notification_id, discord_user_id, read_at)
            VALUES (:notificationId, :discordUserId, :readAt)
            ON CONFLICT (notification_id, discord_user_id) DO NOTHING
            SQL;

        $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'notificationId' => (string) $notification->getId(),
            'discordUserId' => $discordUser->getDiscordId(),
            'readAt' => $readAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Ids of the given broadcasts the player already opened.
     *
     * @param list<Notification> $notifications
     *
     * @return array<string, true>
     */
    public function findOpenedIds(DiscordUser $discordUser, array $notifications): array
    {
        $broadcastIds = [];
        foreach ($notifications as $notification) {
            if ($notification->isBroadcast()) {
                $broadcastIds[] = (string) $notification->getId();
            }
        }

        if ([] === $broadcastIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('opened')
            ->select('IDENTITY(opened.notification) AS notificationId')
            ->andWhere('opened.discordUser = :viewer')
            ->andWhere('opened.notification IN (:broadcasts)')
            ->setParameter('viewer', $discordUser)
            ->setParameter('broadcasts', $broadcastIds)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        return array_fill_keys(array_map(strval(...), $rows), true);
    }

    public function deleteFor(DiscordUser $discordUser): void
    {
        $this->createQueryBuilder('opened')
            ->delete()
            ->andWhere('opened.discordUser = :viewer')
            ->setParameter('viewer', $discordUser)
            ->getQuery()
            ->execute()
        ;
    }
}
