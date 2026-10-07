<?php

declare(strict_types=1);

namespace App\Service\Wishlist;

use App\Entity\DiscordUser;
use App\Entity\MarketListing;
use App\Enum\FeatureEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Repository\WishlistAlertRepository;
use App\Repository\WishlistEntryRepository;
use App\Service\Feature\FeatureFlags;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Market alerts: a listing of a wished card (or of a missing card of a watched
 * universe) notifies the players concerned, never its seller, at most once per
 * (player, listing) — the dedup is the unique row of WishlistAlert.
 */
final readonly class WishlistAlertService
{
    public function __construct(
        private WishlistEntryRepository $entryRepository,
        private WishlistAlertRepository $alertRepository,
        private NotificationService $notificationService,
        private EntityManagerInterface $entityManager,
        private FeatureFlags $featureFlags,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** Post-commit, best effort. Returns the number of players notified. */
    public function alertListing(MarketListing $listing): int
    {
        try {
            if (!$this->featureFlags->isEnabled(FeatureEnum::WISHLIST) || !$listing->isActive()) {
                return 0;
            }

            $card = $listing->getCard();
            $extension = $card->getExtension();
            $notified = 0;

            foreach ($this->entryRepository->findListingAudience($listing) as $audience) {
                if (!$this->alertRepository->insertIgnore($audience['id'], $listing, $this->clock->now())) {
                    continue;
                }

                $recipient = $this->entityManager->find(DiscordUser::class, $audience['id']);
                if (!$recipient instanceof DiscordUser) {
                    continue;
                }

                // a watched universe never names the card: only a direct wish does
                $this->notificationService->notify($recipient, NotificationTypeEnum::WISHLIST_LISTED, [
                    'listingId' => (string) $listing->getId(),
                    'cardName' => $audience['direct'] ? $card->getName() : null,
                    'universe' => $extension?->getName(),
                    'slug' => $extension?->getSlug(),
                    'price' => $listing->getPrice(),
                ]);
                ++$notified;
            }

            return $notified;
        } catch (\Throwable $exception) {
            $this->logger->error('Wishlist alert failed: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return 0;
        }
    }
}
