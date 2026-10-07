<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Hand-made activity around two extra players; "now" is 2026-10-07 10:00 UTC (12:00 Paris).
 */
trait StatsApiScenarioTrait
{
    private const string NOW = '2026-10-07 10:00:00';
    private const string STATSY = '900000000000000001';
    private const string BUYER = '900000000000000002';
    private const string COLLECTOR = '100000000000000001';

    private Connection $conn;

    /** @var array<string, string> */
    private array $ids = [];

    private function seedScenario(): void
    {
        $this->conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        static::getContainer()->get('cache.app')->clear();

        $this->conn->executeStatement("UPDATE discord_user SET created_at = '2026-01-01 00:00:00', updated_at = '2026-01-01 00:00:00'");
        $this->conn->insert('discord_user', ['discord_id' => self::STATSY, 'username' => 'Statsy', 'roles' => '[]', 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
        $this->conn->insert('discord_user', ['discord_id' => self::BUYER, 'username' => 'Buyer', 'roles' => '[]', 'created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00']);

        $booster = $this->scalar("SELECT b.id FROM booster b JOIN extension e ON e.id = b.extension_id WHERE e.slug = 'cyberpunk-2077'");
        $common = $this->scalar("SELECT id FROM card WHERE name = 'Cyberpunk Barlito'");
        $rare = $this->scalar("SELECT id FROM card WHERE name = 'Cyberpunk Rogue'");
        $unique = $this->scalar("SELECT id FROM card WHERE name = 'Cyberpunk Farf'");
        $magic = $this->scalar("SELECT id FROM extension WHERE slug = 'magic'");
        $cyberpunk = $this->scalar("SELECT id FROM extension WHERE slug = 'cyberpunk-2077'");
        $this->ids = ['booster' => $booster, 'common' => $common, 'rare' => $rare, 'unique' => $unique];

        // Statsy: three openings in the period + one old, a unique drawn by the collector
        $openings = [
            ['2026-06-01 10:00:00', [[$common, 1, 0]]],
            ['2026-10-05 08:00:00', [[$common, 2, 1], [$rare, 1, 0]]],
            ['2026-10-06 09:00:00', [[$common, 1, 0]]],
            ['2026-10-07 07:00:00', [[$rare, 1, 1]]],
        ];

        foreach ($openings as [$at, $cards]) {
            $this->opening(self::STATSY, $booster, $at, $cards);
        }
        $this->opening(self::COLLECTOR, $booster, '2026-10-04 12:00:00', [[$unique, 1, 0]]);

        $this->row('booster_claim', ['discord_user_id' => self::STATSY, 'booster_id' => $booster, 'claimed_at' => '2026-10-06 06:00:00']);
        $this->row('booster_claim', ['discord_user_id' => self::STATSY, 'booster_id' => $booster, 'claimed_at' => '2026-10-07 06:30:00']);

        $this->row('booster_purchase', ['discord_user_id' => self::STATSY, 'booster_id' => $booster, 'price' => 50, 'status' => 'completed', 'requested_at' => '2026-10-06 12:00:00', 'resolved_at' => '2026-10-06 12:00:05']);
        $this->row('booster_purchase', ['discord_user_id' => self::STATSY, 'booster_id' => $booster, 'price' => 50, 'status' => 'failed', 'requested_at' => '2026-10-06 13:00:00', 'resolved_at' => '2026-10-06 13:00:05']);

        $code = $this->row('booster_code', ['code' => 'STATSCODE001', 'booster_id' => $booster, 'quantity' => 2, 'max_uses' => 5, 'uses' => 1, 'disabled' => false, 'batch_label' => 'LOT-A']);
        $this->row('booster_code_redemption', ['booster_code_id' => $code, 'discord_user_id' => self::STATSY, 'redeemed_at' => '2026-10-05 09:00:00', 'quantity' => 2]);

        $recycle = $this->row('recycle_operation', ['discord_user_id' => self::STATSY, 'booster_id' => $booster, 'points' => 9, 'booster_count' => 1, 'recycled_at' => '2026-10-06 15:00:00']);
        $this->conn->insert('recycle_operation_card', ['recycle_operation_id' => $recycle, 'card_id' => $rare, 'quantity' => 2, 'holo_quantity' => 0]);
        $this->conn->insert('recycle_operation_card', ['recycle_operation_id' => $recycle, 'card_id' => $common, 'quantity' => 2, 'holo_quantity' => 1]);

        $this->row('fusion_operation', ['discord_user_id' => self::STATSY, 'card_id' => $common, 'fusion_count' => 2, 'copies_consumed' => 20, 'holos_created' => 2, 'fused_at' => '2026-10-06 16:00:00']);
        $this->row('fusion_operation', ['discord_user_id' => self::STATSY, 'card_id' => $rare, 'fusion_count' => 1, 'copies_consumed' => 10, 'holos_created' => 1, 'fused_at' => '2026-06-01 16:00:00']);
        $this->row('fusion_operation', ['discord_user_id' => self::BUYER, 'card_id' => $common, 'fusion_count' => 1, 'copies_consumed' => 10, 'holos_created' => 1, 'fused_at' => '2026-10-07 08:00:00']);

        $this->conn->insert('user_card', ['discord_user_id' => self::STATSY, 'card_id' => $common, 'quantity' => 3, 'holo_quantity' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->conn->insert('user_card', ['discord_user_id' => self::STATSY, 'card_id' => $rare, 'quantity' => 1, 'holo_quantity' => 0, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->conn->insert('user_booster', ['discord_user_id' => self::STATSY, 'booster_id' => $booster, 'quantity' => 4, 'created_at' => self::NOW, 'updated_at' => self::NOW]);

        // market: L1 sold for 100 (completed), L3 sold for 40 (card transferred), L2 active, L4 reserved by a pending payment
        $l1 = $this->row('market_listing', ['seller_id' => self::STATSY, 'card_id' => $rare, 'holo' => false, 'price' => 100, 'status' => 'sold', 'created_at' => '2026-10-06 10:00:00']);
        $this->row('market_listing', ['seller_id' => self::STATSY, 'card_id' => $common, 'holo' => false, 'price' => 20, 'status' => 'active', 'created_at' => '2026-10-07 08:00:00']);
        $l3 = $this->row('market_listing', ['seller_id' => self::BUYER, 'card_id' => $common, 'holo' => false, 'price' => 40, 'status' => 'sold', 'created_at' => '2026-10-05 08:00:00']);
        $l4 = $this->row('market_listing', ['seller_id' => self::BUYER, 'card_id' => $rare, 'holo' => true, 'price' => 10, 'status' => 'reserved_for_purchase', 'created_at' => '2026-10-07 09:00:00']);
        $this->row('market_purchase', ['listing_id' => $l1, 'buyer_id' => self::BUYER, 'seller_id' => self::STATSY, 'price' => 100, 'fee_minor' => 500000000, 'status' => 'completed', 'requested_at' => '2026-10-06 16:00:00', 'resolved_at' => '2026-10-06 16:01:00']);
        $this->row('market_purchase', ['listing_id' => $l3, 'buyer_id' => self::STATSY, 'seller_id' => self::BUYER, 'price' => 40, 'fee_minor' => 200000000, 'status' => 'card_transferred', 'requested_at' => '2026-10-05 10:00:00']);
        $this->row('market_purchase', ['listing_id' => $l4, 'buyer_id' => self::STATSY, 'seller_id' => self::BUYER, 'price' => 10, 'fee_minor' => 50000000, 'status' => 'payment_pending', 'requested_at' => '2026-10-07 09:15:00']);

        // trades: accepted, refused (by Statsy), pending
        $to1 = $this->row('trade_offer', ['proposer_id' => self::STATSY, 'receiver_id' => self::BUYER, 'status' => 'accepted', 'created_at' => '2026-10-05 11:00:00', 'resolved_at' => '2026-10-05 12:00:00']);
        $this->line($to1, 'offered', $common, 1, 0);
        $this->line($to1, 'requested', $rare, 1, 0);
        $to2 = $this->row('trade_offer', ['proposer_id' => self::BUYER, 'receiver_id' => self::STATSY, 'status' => 'refused', 'created_at' => '2026-10-06 11:00:00', 'resolved_at' => '2026-10-06 12:00:00']);
        $this->line($to2, 'offered', $rare, 1, 1);
        $this->row('trade_offer', ['proposer_id' => self::STATSY, 'receiver_id' => self::BUYER, 'status' => 'pending', 'created_at' => '2026-10-07 08:30:00']);

        $this->row('streak_reward', ['discord_user_id' => self::STATSY, 'series_started_on' => '2026-09-30', 'milestone' => 7, 'awarded_at' => '2026-10-06 08:00:00', 'chosen_booster_id' => $booster, 'chosen_at' => '2026-10-06 17:00:00']);

        $this->row('universe_completion_reward', ['discord_user_id' => self::STATSY, 'extension_id' => $cyberpunk, 'amount' => 500, 'status' => 'paid', 'completed_at' => '2026-10-06 11:00:00', 'paid_at' => '2026-10-06 11:00:30']);
        $this->row('universe_completion_reward', ['discord_user_id' => self::BUYER, 'extension_id' => $magic, 'amount' => 300, 'status' => 'pending', 'completed_at' => '2026-10-07 08:00:00']);

        // notifications: two personal (one read), a broadcast read by Buyer, an announcement broadcast read by Buyer
        $this->notification(self::STATSY, 'booster_credited', '{}', '2026-10-05 09:00:00', '2026-10-05 09:00:00');
        $this->notification(self::STATSY, 'booster_credited', '{}', '2026-10-06 09:00:00', null);
        $broadcast = $this->notification(null, 'unique_pulled', '{"playerName":"Collectionneur"}', '2026-10-06 12:00:00', null);
        $announcementNotification = $this->notification(null, 'announcement', '{"title":"Hello","message":"m","link":null}', '2026-10-07 09:00:01', null);
        $this->conn->insert('notification_broadcast_read', ['notification_id' => $broadcast, 'discord_user_id' => self::BUYER, 'read_at' => '2026-10-06 13:00:00']);
        $this->conn->insert('notification_broadcast_read', ['notification_id' => $announcementNotification, 'discord_user_id' => self::BUYER, 'read_at' => '2026-10-07 09:30:00']);
        $this->row('announcement', ['type' => 'announcement', 'title' => 'Hello', 'message' => 'm', 'sent_count' => 1, 'created_at' => '2026-10-07 09:00:00', 'author_id' => '188967649332428800'], false);
    }

    /**
     * @param list<array{string, int, int}> $cards card id, quantity, holo quantity
     */
    private function opening(string $player, string $booster, string $at, array $cards): void
    {
        $opening = $this->row('booster_opening', ['discord_user_id' => $player, 'booster_id' => $booster, 'seed' => 1, 'opened_at' => $at]);

        foreach ($cards as [$card, $quantity, $holo]) {
            $this->conn->insert('booster_opening_card', ['booster_opening_id' => $opening, 'card_id' => $card, 'quantity' => $quantity, 'holo_quantity' => $holo]);
        }
    }

    private function line(string $offer, string $side, string $card, int $normal, int $holo): void
    {
        $this->row('trade_offer_line', ['trade_offer_id' => $offer, 'side' => $side, 'card_id' => $card, 'normal_quantity' => $normal, 'holo_quantity' => $holo]);
    }

    private function notification(?string $recipient, string $type, string $payload, string $createdAt, ?string $readAt): string
    {
        $id = (string) Uuid::v7();
        $this->conn->insert('notification', ['id' => $id, 'recipient_id' => $recipient, 'type' => $type, 'payload' => $payload, 'created_at' => $createdAt, 'read_at' => $readAt]);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function row(string $table, array $data, bool $timestamps = true): string
    {
        $id = (string) Uuid::v7();
        $defaults = ['id' => $id];

        if ($timestamps) {
            $defaults['created_at'] = self::NOW;
            $defaults['updated_at'] = self::NOW;
        }

        $this->insert($table, [...$defaults, ...$data]);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(string $table, array $data): void
    {
        $types = array_map(static fn (mixed $value): ParameterType => \is_bool($value) ? ParameterType::BOOLEAN : ParameterType::STRING, $data);
        $this->conn->insert($table, $data, array_values($types));
    }

    private function scalar(string $sql): string
    {
        return (string) $this->conn->fetchOne($sql);
    }
}
