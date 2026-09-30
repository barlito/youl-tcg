<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\MarketListing;
use App\Entity\UserCard;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Market\MarketListingRefusedException;
use App\Repository\MarketListingRepository;
use App\Repository\UserCardRepository;
use App\Service\Coin\YoulCoinClient;
use App\Service\Realtime\UserEventPublisher;
use App\Service\Trade\EngagedCopies;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class MarketListingService
{
    public const int MAX_ACTIVE_LISTINGS = 3;
    public const int MAX_PRICE = 10_000_000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketListingRepository $listingRepository,
        private UserCardRepository $userCardRepository,
        private EngagedCopies $engagedCopies,
        private ClockInterface $clock,
        private UserEventPublisher $userEventPublisher,
        private YoulCoinClient $coin,
    ) {
    }

    /** @throws MarketListingRefusedException */
    public function create(DiscordUser $seller, Card $card, bool $holo, int $price): MarketListing
    {
        $this->assertValidPrice($price);

        if (CardStatusEnum::PUBLISHED !== $card->getStatus() || ExtensionStatusEnum::PUBLISHED !== $card->getExtension()?->getStatus()) {
            throw new MarketListingRefusedException(\sprintf('Card %s is not published.', $card->getId()), \sprintf('« %s » ne fait pas partie du catalogue publié : elle ne se vend pas.', $card->getName()));
        }

        $wallet = $this->coin->hasWallet($seller->getDiscordId());

        if (null === $wallet) {
            throw new MarketListingRefusedException('Coin unavailable.', 'Youl Coin est indisponible, réessaie dans un instant.');
        }

        if (!$wallet) {
            throw new MarketListingRefusedException('Seller has no coin wallet.', 'Pour vendre, ton compte Youl Coin doit exister : connecte-toi une fois sur Youl Coin, puis reviens.');
        }

        // the closure returns its refusal: throwing inside wrapInTransaction would close the EntityManager
        $result = $this->entityManager->wrapInTransaction(function () use ($seller, $card, $holo, $price): MarketListing | MarketListingRefusedException {
            // the quota is a COUNT then an INSERT: serialize the seller's creations on their row
            $this->entityManager->find(DiscordUser::class, $seller->getDiscordId(), LockMode::PESSIMISTIC_WRITE);

            if ($this->listingRepository->countEngagedBySeller($seller) >= self::MAX_ACTIVE_LISTINGS) {
                return new MarketListingRefusedException('Listing quota reached.', \sprintf('Tu as déjà %d annonces en vente : retire-en une avant d\'en publier une autre.', self::MAX_ACTIVE_LISTINGS));
            }

            // same row lock as trades and recycling, taken BEFORE reading the reservation ledger
            $row = $this->userCardRepository->lockForDebit($seller, [$card])[(string) $card->getId()] ?? null;
            $refusal = $this->coverageRefusal($seller, $card, $holo, $row);

            if ($refusal instanceof MarketListingRefusedException) {
                return $refusal;
            }

            $listing = new MarketListing($seller, $card, $holo, $price);
            $this->entityManager->persist($listing);
            $this->entityManager->flush();

            return $listing;
        });

        if ($result instanceof MarketListingRefusedException) {
            throw $result;
        }

        $this->announce($result);

        return $result;
    }

    /** @return list<array{card: Card, normal: int, holo: int}> */
    public function getSellableCopies(DiscordUser $seller): array
    {
        $reserved = $this->engagedCopies->reservedQuantities($seller);
        $sellable = [];

        foreach ($this->userCardRepository->findOwnedWithCards($seller, publishedOnly: true) as $row) {
            $held = $reserved[(string) $row->getCard()->getId()] ?? ['normal' => 0, 'holo' => 0];
            $holo = max(0, $row->getHoloQuantity() - $held['holo']);
            $normal = max(0, $row->getQuantity() - $row->getHoloQuantity() - $held['normal']);

            if ($normal > 0 || $holo > 0) {
                $sellable[] = ['card' => $row->getCard(), 'normal' => $normal, 'holo' => $holo];
            }
        }

        return $sellable;
    }

    /** @throws MarketListingRefusedException */
    public function changePrice(DiscordUser $seller, MarketListing $listing, int $price): void
    {
        $this->assertValidPrice($price);

        $this->mutateActive($seller, $listing, static function (MarketListing $locked) use ($price): void {
            $locked->setPrice($price);
        });
        $this->announce($listing);
    }

    /** @throws MarketListingRefusedException */
    public function withdraw(DiscordUser $seller, MarketListing $listing): void
    {
        $this->mutateActive($seller, $listing, function (MarketListing $locked): void {
            $locked->close(MarketListingStatusEnum::WITHDRAWN, $this->clock->now());
        });
        $this->announce($listing);
    }

    /** @param \Closure(MarketListing): void $mutation */
    private function mutateActive(DiscordUser $seller, MarketListing $listing, \Closure $mutation): void
    {
        $refusal = $this->entityManager->wrapInTransaction(function () use ($seller, $listing, $mutation): ?MarketListingRefusedException {
            $locked = $this->listingRepository->findOneForUpdate((string) $listing->getId());

            if (!$locked instanceof MarketListing) {
                return new MarketListingRefusedException('Listing not found.', 'Cette annonce n\'existe plus.');
            }

            // the locked SELECT does not re-hydrate an already-loaded entity
            $this->entityManager->refresh($locked);

            if ($locked->getSeller()->getDiscordId() !== $seller->getDiscordId()) {
                return new MarketListingRefusedException('Not the seller.', 'Cette annonce ne t\'appartient pas.');
            }

            if (MarketListingStatusEnum::RESERVED_FOR_PURCHASE === $locked->getStatus()) {
                return new MarketListingRefusedException('Purchase in progress.', 'Un achat est en cours sur cette annonce : réessaie dans un instant.');
            }

            if (!$locked->isActive()) {
                return new MarketListingRefusedException('Listing is closed.', 'Cette annonce n\'est plus en vente.');
            }

            $mutation($locked);
            $this->entityManager->flush();

            return null;
        });

        if ($refusal instanceof MarketListingRefusedException) {
            throw $refusal;
        }
    }

    private function coverageRefusal(DiscordUser $seller, Card $card, bool $holo, ?UserCard $row): ?MarketListingRefusedException
    {
        $ownedHolo = $row?->getHoloQuantity() ?? 0;
        $owned = $holo ? $ownedHolo : ($row?->getQuantity() ?? 0) - $ownedHolo;
        $reserved = $this->engagedCopies->reservedQuantities($seller)[(string) $card->getId()] ?? ['normal' => 0, 'holo' => 0];

        if ($owned < 1) {
            return new MarketListingRefusedException(\sprintf('Card %s: no %s copy owned.', $card->getId(), $holo ? 'holo' : 'normal'), \sprintf('Tu ne possèdes pas d\'exemplaire %s de « %s ».', $holo ? 'holo' : 'normal', $card->getName()));
        }

        if ($owned - $reserved[$holo ? 'holo' : 'normal'] < 1) {
            return new MarketListingRefusedException(\sprintf('Card %s: copies already engaged.', $card->getId()), \sprintf('Tous tes exemplaires de « %s » sont déjà engagés (échange en attente ou annonce).', $card->getName()));
        }

        if ($card->isUnique()) {
            // fresh read: the identity-mapped card may carry a stale claimedBy
            $this->entityManager->refresh($card);

            if ($card->getClaimedBy()?->getDiscordId() !== $seller->getDiscordId()) {
                return new MarketListingRefusedException(\sprintf('Unique card %s is not claimed by the seller.', $card->getId()), \sprintf('« %s » (exemplaire unique) ne t\'appartient pas.', $card->getName()));
            }
        }

        return null;
    }

    // post-commit, public: the listing is visible to every player anyway
    private function announce(MarketListing $listing): void
    {
        $this->userEventPublisher->publishBroadcast(UserEventEnum::MARKET_CHANGED, ['listingId' => (string) $listing->getId(), 'status' => $listing->getStatus()->value]);
    }

    private function assertValidPrice(int $price): void
    {
        if ($price < 1 || $price > self::MAX_PRICE) {
            throw new MarketListingRefusedException(\sprintf('Invalid price %d.', $price), \sprintf('Le prix doit être un nombre entier de coins entre 1 et %s.', number_format(self::MAX_PRICE, 0, ',', ' ')));
        }
    }
}
