<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Attribute\RequiresFeature;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\WishlistEntry;
use App\Entity\WishlistUniverse;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Exception\Wishlist\WishlistRefusedException;
use App\Repository\ExtensionRepository;
use App\Repository\MarketListingRepository;
use App\Service\Wishlist\WishlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * « Ma wishlist »: wishes in clear only for the cards the player can see
 * (the rest stays a card back), watched universes with their missing count.
 */
#[RequiresFeature(FeatureEnum::WISHLIST)]
#[AsLiveComponent]
final class WishlistPanel extends AbstractController
{
    use DefaultActionTrait;

    public const int ON_SALE_LIMIT = 4;

    #[LiveProp]
    public ?string $error = null;

    /** Universe picked in the « Surveiller un univers » select (slug). */
    #[LiveProp(writable: true)]
    public string $watchSlug = '';

    /** @var array<string, true>|null */
    private ?array $wishedIds = null;

    public function __construct(
        private readonly WishlistService $wishlist,
        private readonly ExtensionRepository $extensionRepository,
        private readonly MarketListingRepository $listingRepository,
    ) {
    }

    /** @return list<array{extension: Extension, missing: int}> */
    public function getWatchable(): array
    {
        return $this->wishlist->listWatchableUniverses($this->getDiscordUser());
    }

    /**
     * « En vente et qui te manquent »: the newest listings of other players for cards the
     * player owns no copy of — listings are public, so nothing is revealed.
     *
     * @return list<MarketListing>
     */
    public function getMissingOnSale(): array
    {
        $player = $this->getDiscordUser();

        return $this->listingRepository->findActivePage(null, $player, null, null, null, 'date_desc', 1, self::ON_SALE_LIMIT, null, null, $player);
    }

    public function isWished(MarketListing $listing): bool
    {
        $this->wishedIds ??= $this->wishlist->wishedCardIds($this->getDiscordUser());

        return isset($this->wishedIds[(string) $listing->getCard()->getId()]);
    }

    #[LiveAction]
    public function watch(): void
    {
        $this->error = null;
        $extension = '' === $this->watchSlug ? null : $this->extensionRepository->findOneBy(['slug' => $this->watchSlug, 'status' => ExtensionStatusEnum::PUBLISHED]);

        if (!$extension instanceof Extension) {
            $this->error = 'Choisis d\'abord un univers à surveiller.';

            return;
        }

        try {
            $this->wishlist->watchUniverse($this->getDiscordUser(), $extension);
            $this->watchSlug = '';
        } catch (WishlistRefusedException $exception) {
            $this->error = $exception->getUserMessage();
        }
    }

    #[LiveAction]
    public function toggleListingWish(#[LiveArg] string $listingId): void
    {
        $this->error = null;
        $listing = Uuid::isValid($listingId) ? $this->listingRepository->find($listingId) : null;

        if (!$listing instanceof MarketListing || !$listing->isActive()) {
            $this->error = 'Cette annonce n\'est plus disponible.';

            return;
        }

        try {
            $this->wishlist->toggle($this->getDiscordUser(), $listing->getCard());
        } catch (WishlistRefusedException $exception) {
            $this->error = $exception->getUserMessage();
        }

        $this->wishedIds = null;
    }

    /** @return list<array{entry: WishlistEntry, visible: bool, listed: bool}> */
    public function getEntries(): array
    {
        return $this->wishlist->listEntries($this->getDiscordUser());
    }

    /** @return list<array{universe: WishlistUniverse, missing: int}> */
    public function getUniverses(): array
    {
        return $this->wishlist->listUniverses($this->getDiscordUser());
    }

    public function getMax(): int
    {
        return WishlistService::MAX_ENTRIES;
    }

    #[LiveAction]
    public function removeEntry(#[LiveArg] string $entryId): void
    {
        $this->error = null;

        try {
            $this->wishlist->removeEntry($this->getDiscordUser(), $entryId);
        } catch (WishlistRefusedException $exception) {
            $this->error = $exception->getUserMessage();
        }
    }

    #[LiveAction]
    public function unwatch(#[LiveArg] string $slug): void
    {
        $this->error = null;
        $extension = $this->extensionRepository->findOneBy(['slug' => $slug, 'status' => ExtensionStatusEnum::PUBLISHED]);

        if (!$extension instanceof Extension) {
            return;
        }

        try {
            $this->wishlist->unwatchUniverse($this->getDiscordUser(), $extension);
        } catch (WishlistRefusedException $exception) {
            $this->error = $exception->getUserMessage();
        }
    }

    private function getDiscordUser(): DiscordUser
    {
        $user = $this->getUser();

        if (!$user instanceof DiscordUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
