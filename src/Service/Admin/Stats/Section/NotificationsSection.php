<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;

final readonly class NotificationsSection extends AbstractStatsSection
{
    private const int LIST_LIMIT = 100;

    private const string BROADCASTS_CTE = <<<'SQL'
        WITH broadcast AS (
            SELECT n.id, n.type, n.payload, n.created_at,
                   (SELECT COUNT(*) FROM discord_user u WHERE u.created_at <= n.created_at) AS eligible,
                   (SELECT COUNT(*) FROM discord_user u
                    WHERE u.created_at <= n.created_at
                      AND (u.notifications_seen_at >= n.created_at
                           OR EXISTS (SELECT 1 FROM notification_broadcast_read r WHERE r.notification_id = n.id AND r.discord_user_id = u.discord_id))) AS readers
            FROM notification n
            WHERE n.recipient_id IS NULL
        )
        SQL;

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::NOTIFICATIONS;
    }

    public function build(StatsContext $context): array
    {
        $broadcasts = $this->db->all(self::BROADCASTS_CTE . ' SELECT id, type, payload, created_at, eligible, readers FROM broadcast ORDER BY created_at DESC, id');

        return [
            'byType' => $this->byType($context, $broadcasts),
            'broadcasts' => \array_slice(array_map($this->broadcast(...), $broadcasts), 0, self::LIST_LIMIT),
            'announcements' => $this->announcements(),
            'note' => 'broadcast read = notificationsSeenAt after it, or an individual read row; only players registered before it are eligible',
        ];
    }

    /**
     * @param list<array<string, mixed>> $broadcasts
     *
     * @return array<string, array<string, mixed>>
     */
    private function byType(StatsContext $context, array $broadcasts): array
    {
        $rows = $this->db->keyed(<<<'SQL'
            SELECT type,
                   COUNT(*) FILTER (WHERE recipient_id IS NOT NULL) AS personal,
                   COUNT(*) FILTER (WHERE recipient_id IS NOT NULL AND read_at IS NOT NULL) AS personal_read,
                   COUNT(*) FILTER (WHERE recipient_id IS NOT NULL AND created_at >= :since) AS period_personal,
                   COUNT(*) FILTER (WHERE recipient_id IS NULL) AS broadcasts,
                   COUNT(*) FILTER (WHERE recipient_id IS NULL AND created_at >= :since) AS period_broadcasts
            FROM notification
            GROUP BY type
            SQL, 'type', ['since' => $context->since]);

        $reads = [];
        foreach ($broadcasts as $row) {
            $type = StatsFormat::string($row, 'type');
            $reads[$type]['eligible'] = ($reads[$type]['eligible'] ?? 0) + StatsFormat::int($row, 'eligible');
            $reads[$type]['readers'] = ($reads[$type]['readers'] ?? 0) + StatsFormat::int($row, 'readers');
        }

        $result = [];

        foreach (NotificationTypeEnum::cases() as $type) {
            $row = $rows[$type->value] ?? [];
            $personal = StatsFormat::int($row, 'personal');
            $read = StatsFormat::int($row, 'personal_read');
            $result[$type->value] = [
                'total' => $personal + StatsFormat::int($row, 'broadcasts'),
                'periodTotal' => StatsFormat::int($row, 'period_personal') + StatsFormat::int($row, 'period_broadcasts'),
                'personal' => ['total' => $personal, 'read' => $read, 'unread' => $personal - $read, 'readRatePercent' => StatsFormat::share($read, $personal)],
                'broadcasts' => [
                    'total' => StatsFormat::int($row, 'broadcasts'),
                    'period' => StatsFormat::int($row, 'period_broadcasts'),
                    'readRatePercent' => StatsFormat::share($reads[$type->value]['readers'] ?? 0, $reads[$type->value]['eligible'] ?? 0),
                ],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function broadcast(array $row): array
    {
        return [
            'id' => StatsFormat::string($row, 'id'),
            'type' => StatsFormat::string($row, 'type'),
            'createdAt' => StatsFormat::iso($row, 'created_at'),
            'payload' => StatsFormat::jsonObject($row, 'payload'),
            'readers' => StatsFormat::int($row, 'readers'),
            'eligiblePlayers' => StatsFormat::int($row, 'eligible'),
            'readRatePercent' => StatsFormat::share(StatsFormat::int($row, 'readers'), StatsFormat::int($row, 'eligible')),
        ];
    }

    /**
     * Matched to its notification rows by type, title (announcements) and a 60 s window: approximate.
     *
     * @return list<array<string, mixed>>
     */
    private function announcements(): array
    {
        $rows = $this->db->all(self::BROADCASTS_CTE . <<<'SQL'

            SELECT a.id, a.type, a.title, a.created_at, a.sent_count, a.context,
                   author.discord_id AS author_id, author.username AS author_name,
                   (SELECT COUNT(*) FROM announcement_recipient ar WHERE ar.announcement_id = a.id) AS recipients,
                   b.readers AS broadcast_readers, b.eligible AS broadcast_eligible,
                   personal.total AS personal_total, personal.read AS personal_read
            FROM announcement a
            LEFT JOIN discord_user author ON author.discord_id = a.author_id
            LEFT JOIN LATERAL (
                SELECT bc.readers, bc.eligible
                FROM broadcast bc
                WHERE bc.type = a.type
                  AND ABS(EXTRACT(EPOCH FROM (bc.created_at - a.created_at))) <= 60
                  AND (a.type <> 'announcement' OR bc.payload->>'title' = a.title)
                ORDER BY ABS(EXTRACT(EPOCH FROM (bc.created_at - a.created_at))), bc.id
                LIMIT 1
            ) b ON TRUE
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE n.read_at IS NOT NULL) AS read
                FROM notification n
                WHERE n.recipient_id IS NOT NULL
                  AND n.type = a.type
                  AND ABS(EXTRACT(EPOCH FROM (n.created_at - a.created_at))) <= 60
                  AND (a.type <> 'announcement' OR n.payload->>'title' = a.title)
            ) personal ON TRUE
            ORDER BY a.created_at DESC, a.id
            LIMIT 100
            SQL);

        return array_map(function (array $row): array {
            $broadcast = 0 === StatsFormat::int($row, 'recipients');
            $readers = $broadcast ? StatsFormat::int($row, 'broadcast_readers') : StatsFormat::int($row, 'personal_read');
            $eligible = $broadcast ? StatsFormat::int($row, 'broadcast_eligible') : StatsFormat::int($row, 'personal_total');

            return [
                'id' => StatsFormat::string($row, 'id'),
                'type' => StatsFormat::string($row, 'type'),
                'title' => StatsFormat::string($row, 'title'),
                'createdAt' => StatsFormat::iso($row, 'created_at'),
                'target' => $broadcast ? 'all' : 'selection',
                'recipients' => StatsFormat::int($row, 'recipients'),
                'sentCount' => StatsFormat::int($row, 'sent_count'),
                'context' => StatsFormat::nullableString($row, 'context'),
                'author' => $this->player(StatsFormat::nullableString($row, 'author_id'), StatsFormat::nullableString($row, 'author_name')),
                'reads' => ['readers' => $readers, 'eligible' => $eligible, 'readRatePercent' => StatsFormat::share($readers, $eligible)],
            ];
        }, $rows);
    }
}
