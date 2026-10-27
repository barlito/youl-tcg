<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Exception\Market\MarketException;
use App\Exception\Wishlist\WishlistRefusedException;
use App\Repository\DiscordUserRepository;
use App\Repository\MarketListingRepository;
use App\Repository\UserCardRepository;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\WalletBalances;
use App\Service\Market\MarketListingService;
use App\Service\Market\MarketPurchaseService;
use App\Service\Wishlist\WishlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

#[AsLiveComponent]
final class MarketBoard extends AbstractController
{
    use DefaultActionTrait;

    public const int PER_PAGE = 12;

    /** @var list<string> */
    public const array SORTS = ['date_desc', 'date_asc', 'price_asc', 'price_desc'];

    #[LiveProp]
    public ?string $sellerId = null;

    #[LiveProp(writable: true, onUpdated: 'onUniverseUpdated', url: new UrlMapping(as: 'univers'))]
    public ?string $universe = null;

    #[LiveProp(writable: true, onUpdated: 'onRarityUpdated', url: new UrlMapping(as: 'rarete'))]
    public ?string $rarity = null;

    #[LiveProp(writable: true, onUpdated: 'onFinishUpdated', url: new UrlMapping(as: 'finition'))]
    public ?string $finish = null;

    #[LiveProp(writable: true, url: new UrlMapping(as: 'wishlist'))]
    public bool $wishlistOnly = false;

    #[LiveProp(writable: true, url: new UrlMapping(as: 'mine'))]
    public bool $mineOnly = false;

    #[LiveProp(writable: true, url: new UrlMapping(as: 'manquantes'))]
    public bool $missingOnly = false;

    #[LiveProp(writable: true, onUpdated: 'onQueryUpdated', url: new UrlMapping(as: 'q'))]
    public string $query = '';

    #[LiveProp(writable: true, onUpdated: 'onSortUpdated', url: new UrlMapping(as: 'tri'))]
    public string $sort = 'date_desc';

    #[LiveProp(writable: true, url: new UrlMapping(as: 'page'))]
    public int $page = 1;

    #[LiveProp]
    public ?string $confirming = null;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $success = null;

    /** @var list<MarketListing>|null */
    private ?array $listings = null;

    private ?int $total = null;

    private ?int $mineTotal = null;

    private bool $reconciled = false;

    /** @var list<Extension>|null */
    private ?array $chips = null;

    /** @var array<string, true>|null */
    private ?array $wishedIds = null;

    /** @var array<string, int>|null */
    private ?array $myCardIds = null;

    private bool $balanceLoaded = false;

    private ?CoinAmount $balance = null;

    public function __construct(
        private readonly MarketListingRepository $listingRepository,
        private readonly MarketPurchaseService $purchaseService,
        private readonly DiscordUserRepository $discordUserRepository,
        private readonly WalletBalances $walletBalances,
        private readonly RequestStack $requestStack,
        private readonly WishlistService $wishlist,
        private readonly UserCardRepository $userCardRepository,
    ) {
    }

    /** @return list<MarketListing> */
    public function getListings(): array
    {
        $this->reconcileOnce();

        return $this->listings ??= $this->listingRepository->findActivePage(
            $this->getScopeSeller(),
            $this->getExcludedSeller(),
            $this->getActiveExtension(),
            $this->getActiveRarity(),
            $this->getActiveHolo(),
            $this->getActiveSort(),
            $this->getCurrentPage(),
            self::PER_PAGE,
            $this->getWishOf(),
            $this->getSearch(),
            $this->getMissingFor(),
        );
    }

    public function getTotal(): int
    {
        return $this->total ??= $this->listingRepository->countActive(
            $this->getScopeSeller(),
            $this->getExcludedSeller(),
            $this->getActiveExtension(),
            $this->getActiveRarity(),
            $this->getActiveHolo(),
            $this->getWishOf(),
            $this->getSearch(),
            $this->getMissingFor(),
        );
    }

    public function getPageCount(): int
    {
        return max(1, (int) ceil($this->getTotal() / self::PER_PAGE));
    }

    public function getCurrentPage(): int
    {
        return min(max(1, $this->page), $this->getPageCount());
    }

    /** @return list<Extension> */
    public function getUniverseChips(): array
    {
        return $this->chips ??= $this->listingRepository->findExtensionsWithActiveListings($this->getScopeSeller(), null);
    }

    public function getMineTotal(): int
    {
        return $this->mineTotal ??= $this->listingRepository->countActive($this->getDiscordUser(), null, null, null, null);
    }

    /** Engaged listings of the player (active + reserved), the « x/3 » of the shop button. */
    public function getMyEngagedCount(): int
    {
        return $this->listingRepository->countEngagedBySeller($this->getDiscordUser());
    }

    public function getMaxListings(): int
    {
        return MarketListingService::MAX_ACTIVE_LISTINGS;
    }

    public function isMissingFilterActive(): bool
    {
        return $this->missingOnly && null === $this->sellerId && !$this->isMineFilterActive();
    }

    public function hasActiveFilters(): bool
    {
        return null !== $this->getSearch() || $this->getActiveExtension() instanceof Extension || $this->getActiveRarity() instanceof CardRarityEnum
            || null !== $this->getActiveHolo() || $this->wishlistOnly || $this->isMineFilterActive() || $this->missingOnly;
    }

    public function isMineFilterActive(): bool
    {
        return $this->mineOnly && null === $this->sellerId;
    }

    public function isOwn(MarketListing $listing): bool
    {
        return $listing->getSeller()->getDiscordId() === $this->getDiscordUser()->getDiscordId();
    }

    /** @return list<CardRarityEnum> */
    public function getRarities(): array
    {
        return array_reverse(CardRarityEnum::ascending());
    }

    public function getActiveSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'date_desc';
    }

    public function getBalance(): ?CoinAmount
    {
        if (!$this->balanceLoaded) {
            $this->balance = $this->walletBalances->get($this->getDiscordUser()->getDiscordId());
            $this->balanceLoaded = true;
        }

        return $this->balance;
    }

    public function getBuyBlock(MarketListing $listing): ?string
    {
        $balance = $this->getBalance();

        return match (true) {
            $this->isOwn($listing) => 'Ton annonce',
            !$balance instanceof CoinAmount => 'Youl Coin indisponible',
            $balance->isLessThan(CoinAmount::fromCoins($listing->getPrice())) => 'Solde insuffisant',
            default => null,
        };
    }

    public function onUniverseUpdated(?string $previous): void
    {
        $this->universe = self::nullIfEmpty($this->universe);
        $this->resetPageIfChanged(self::nullIfEmpty($previous), $this->universe);
    }

    public function onRarityUpdated(?string $previous): void
    {
        $this->rarity = self::nullIfEmpty($this->rarity);
        $this->resetPageIfChanged(self::nullIfEmpty($previous), $this->rarity);
    }

    public function onFinishUpdated(?string $previous): void
    {
        $this->finish = self::nullIfEmpty($this->finish);
        $this->resetPageIfChanged(self::nullIfEmpty($previous), $this->finish);
    }

    public function onQueryUpdated(string $previous): void
    {
        $this->resetPageIfChanged($previous, $this->query);
    }

    public function onSortUpdated(string $previous): void
    {
        $this->resetPageIfChanged($previous, $this->sort);
    }

    public function resetPage(): void
    {
        $this->page = 1;
        $this->confirming = null;
    }

    // every action re-sends the selects' '' (prop null): only a real change may reset the page and the confirmation
    private function resetPageIfChanged(?string $previous, ?string $current): void
    {
        if ($previous !== $current) {
            $this->resetPage();
        }
    }

    // a select's « toutes » option posts '': keep it out of the url (?univers=&rarete=)
    private static function nullIfEmpty(?string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    #[LiveAction]
    public function filterUniverse(#[LiveArg] string $slug): void
    {
        $this->universe = '' === $slug ? null : $slug;
        $this->resetPage();
    }

    #[LiveAction]
    public function toggleWishlistFilter(): void
    {
        $this->wishlistOnly = !$this->wishlistOnly;
        $this->mineOnly = $this->mineOnly && !$this->wishlistOnly;
        $this->resetPage();
    }

    #[LiveAction]
    public function toggleMineFilter(): void
    {
        $this->mineOnly = !$this->mineOnly;
        $this->wishlistOnly = $this->wishlistOnly && !$this->mineOnly;
        $this->missingOnly = $this->missingOnly && !$this->mineOnly;
        $this->resetPage();
    }

    #[LiveAction]
    public function toggleMissingFilter(): void
    {
        $this->missingOnly = !$this->missingOnly;
        $this->mineOnly = $this->mineOnly && !$this->missingOnly;
        $this->resetPage();
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, $page);
        $this->confirming = null;
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->universe = null;
        $this->rarity = null;
        $this->finish = null;
        $this->wishlistOnly = false;
        $this->mineOnly = false;
        $this->missingOnly = false;
        $this->query = '';
        $this->page = 1;
    }

    #[LiveAction]
    public function toggleWish(#[LiveArg] string $listingId): void
    {
        $this->error = null;
        $this->success = null;
        $listing = $this->findListing($listingId);

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
        $this->listings = null;
        $this->total = null;
    }

    #[LiveAction]
    public function askBuy(#[LiveArg] string $listingId): void
    {
        $this->error = null;
        $this->success = null;
        $this->confirming = null;

        $listing = $this->findListing($listingId);

        if (!$listing instanceof MarketListing || !$listing->isActive()) {
            $this->error = 'Cette annonce n\'est plus disponible.';

            return;
        }

        if (null !== ($block = $this->getBuyBlock($listing))) {
            $this->error = $block . '.';

            return;
        }

        $this->confirming = $listingId;
    }

    #[LiveAction]
    public function abortBuy(): void
    {
        $this->confirming = null;
    }

    #[LiveAction]
    public function buy(#[LiveArg] string $listingId): void
    {
        $this->error = null;
        $this->success = null;

        $confirmed = $this->confirming === $listingId;
        $this->confirming = null;
        $listing = $this->findListing($listingId);
        $playerToken = $this->requestStack->getCurrentRequest()?->cookies->get('jwt');

        if (!$confirmed || !$listing instanceof MarketListing || !\is_string($playerToken)) {
            $this->error = 'Achat impossible, recharge la page et réessaie.';

            return;
        }

        try {
            $purchase = $this->purchaseService->purchase($this->getDiscordUser(), $listing, $playerToken);
        } catch (MarketException $exception) {
            $this->error = $exception->getUserMessage();
            $this->listings = null;
            $this->total = null;

            return;
        }

        // results and balance memoized before the purchase are stale
        $this->listings = null;
        $this->total = null;
        $this->balanceLoaded = false;

        $this->success = MarketPurchaseStatusEnum::PAYMENT_PENDING === $purchase->getStatus()
            ? 'Paiement en cours de vérification : la carte arrivera dès que Youl Coin le confirme.'
            : 'Carte achetée ! Elle est dans ta collection.';
    }

    public function isWishlistEnabled(): bool
    {
        return $this->wishlist->isEnabled();
    }

    public function isWished(MarketListing $listing): bool
    {
        $this->wishedIds ??= $this->wishlist->wishedCardIds($this->getDiscordUser());

        return isset($this->wishedIds[(string) $listing->getCard()->getId()]);
    }

    // « te manque / tu l'as déjà »: listings are in clear, so this leaks nothing
    public function isOwnedByMe(MarketListing $listing): bool
    {
        $this->myCardIds ??= array_flip($this->userCardRepository->findOwnedCardIds($this->getDiscordUser()));

        return isset($this->myCardIds[(string) $listing->getCard()->getId()]);
    }

    private function getSearch(): ?string
    {
        $query = mb_trim($this->query);

        return null === $this->sellerId && '' !== $query ? mb_substr($query, 0, 80) : null;
    }

    // « Me manquent »: one NOT EXISTS in the listing query itself, never one query per card
    private function getMissingFor(): ?DiscordUser
    {
        return $this->isMissingFilterActive() ? $this->getDiscordUser() : null;
    }

    private function getWishOf(): ?DiscordUser
    {
        return $this->wishlistOnly && null === $this->sellerId && !$this->isMineFilterActive() && $this->wishlist->isEnabled() ? $this->getDiscordUser() : null;
    }

    // a player shop shows one seller; the board shows everyone, or only me under « Mes annonces »
    private function getScopeSeller(): ?DiscordUser
    {
        return $this->getSeller() ?? ($this->isMineFilterActive() ? $this->getDiscordUser() : null);
    }

    // my own listings never match « Ma wishlist »: I already own those cards
    private function getExcludedSeller(): ?DiscordUser
    {
        return $this->getWishOf();
    }

    private function getSeller(): ?DiscordUser
    {
        if (null === $this->sellerId) {
            return null;
        }

        return $this->discordUserRepository->find($this->sellerId) ?? throw $this->createNotFoundException('Unknown seller.');
    }

    public function getActiveExtension(): ?Extension
    {
        if (null === $this->universe || '' === $this->universe) {
            return null;
        }

        foreach ($this->getUniverseChips() as $extension) {
            if ($extension->getSlug() === $this->universe) {
                return $extension;
            }
        }

        return null;
    }

    public function getActiveRarity(): ?CardRarityEnum
    {
        return null === $this->rarity || '' === $this->rarity ? null : CardRarityEnum::tryFrom($this->rarity);
    }

    private function getActiveHolo(): ?bool
    {
        return match ($this->finish) {
            'holo' => true,
            'normal' => false,
            default => null,
        };
    }

    private function findListing(string $listingId): ?MarketListing
    {
        // client-provided: a malformed id is "not found", never a conversion error
        return Uuid::isValid($listingId) ? $this->listingRepository->find($listingId) : null;
    }

    // unconfirmed sales and payouts of this player resume as the page renders, not after it
    private function reconcileOnce(): void
    {
        if (!$this->reconciled) {
            $this->reconciled = true;
            $this->purchaseService->reconcile($this->getDiscordUser());
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
