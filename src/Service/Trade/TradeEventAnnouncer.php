<?php

declare(strict_types=1);

namespace App\Service\Trade;

use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Enum\FeatureEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Service\Feature\FeatureFlags;
use App\Service\Notification\NotificationService;
use App\Service\Realtime\UserEventPublisher;

/**
 * Tells both players of an offer that it moved. Call it AFTER the transaction
 * committed; like the layers below it, it never throws.
 *
 * Both participants always get `trades-changed` (other tabs, header badge);
 * only the player who did NOT act gets a notification, and only when there is
 * something to do or learn (received, accepted, refused). A cancellation or an
 * invalidation silently drops the offer from the boxes: no notification.
 */
final readonly class TradeEventAnnouncer
{
    public function __construct(
        private UserEventPublisher $publisher,
        private NotificationService $notificationService,
        private FeatureFlags $featureFlags,
    ) {
    }

    public function created(TradeOffer $offer): void
    {
        if (!$this->isOpen()) {
            return;
        }

        $this->publishChanged($offer, TradeOfferStatusEnum::PENDING);
        $this->notify($offer->getReceiver(), NotificationTypeEnum::TRADE_RECEIVED, $offer->getProposer());
    }

    /**
     * $status is passed explicitly: the caller knows what it committed, even
     * when $offer is a stale (non identity-mapped) instance.
     */
    public function resolved(TradeOffer $offer, TradeOfferStatusEnum $status): void
    {
        if (!$this->isOpen()) {
            return;
        }

        if (TradeOfferStatusEnum::ACCEPTED === $status) {
            // both collections moved
            $this->publisher->publish($offer->getProposer(), UserEventEnum::INVENTORY_CHANGED);
            $this->publisher->publish($offer->getReceiver(), UserEventEnum::INVENTORY_CHANGED);
        }

        $this->publishChanged($offer, $status);

        match ($status) {
            TradeOfferStatusEnum::ACCEPTED => $this->notify($offer->getProposer(), NotificationTypeEnum::TRADE_ACCEPTED, $offer->getReceiver()),
            TradeOfferStatusEnum::REFUSED => $this->notify($offer->getProposer(), NotificationTypeEnum::TRADE_REFUSED, $offer->getReceiver()),
            default => null,
        };
    }

    private function publishChanged(TradeOffer $offer, TradeOfferStatusEnum $status): void
    {
        $payload = ['offerId' => (string) $offer->getId(), 'status' => $status->value];

        $this->publisher->publish($offer->getProposer(), UserEventEnum::TRADES_CHANGED, $payload);
        $this->publisher->publish($offer->getReceiver(), UserEventEnum::TRADES_CHANGED, $payload);
    }

    private function notify(DiscordUser $recipient, NotificationTypeEnum $type, DiscordUser $counterpart): void
    {
        $this->notificationService->notify($recipient, $type, [
            'playerName' => $counterpart->getUsername(),
            'playerId' => $counterpart->getDiscordId(),
        ]);
    }

    private function isOpen(): bool
    {
        return $this->featureFlags->isEnabled(FeatureEnum::TRADES);
    }
}
