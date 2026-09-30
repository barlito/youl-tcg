<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Exception\Market\MarketException;
use App\Repository\DiscordUserRepository;
use App\Repository\MarketListingRepository;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\WalletBalances;
use App\Service\Market\MarketPurchaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * The market board, or one player's shop when `sellerId` is set: active listings
 * of the published catalogue in clear, filters, sort, pagination, and the
 * two-step purchase. The listing id is client-provided: it is only a lookup key,
 * MarketPurchaseService re-checks everything under lock.
 */
#[AsLiveComponent]
final class MarketBoard extends AbstractController
{
    use DefaultActionTrait;

    public const int PER_PAGE = 12;

    /** @var list<string> */
    public const array SORTS = ['date_desc', 'date_asc', 'price_asc', 'price_desc'];

    /** Restricts the board to one seller (their shop on their profile). */
    #[LiveProp]
    public ?string $sellerId = null;

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'univers'))]
    public ?string $universe = null;

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'rarete'))]
    public ?string $rarity = null;

    /** 'normal' | 'holo' | null (both). */
    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'finition'))]
    public ?string $finish = null;

    #[LiveProp(writable: true, onUpdated: 'resetPage', url: new UrlMapping(as: 'tri'))]
    public string $sort = 'date_desc';

    #[LiveProp(writable: true, url: new UrlMapping(as: 'page'))]
    public int $page = 1;

    /** Listing awaiting the second click of the purchase. */
    #[LiveProp]
    public ?string $confirming = null;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $success = null;

    /** @var list<MarketListing>|null */
    private ?array $listings = null;

    private ?int $total = null;

    private bool $reconciled = false;

    /** @var list<Extension>|null */
    private ?array $chips = null;

    private bool $balanceLoaded = false;

    private ?CoinAmount $balance = null;

    public function __construct(
        private readonly MarketListingRepository $listingRepository,
        private readonly MarketPurchaseService $purchaseService,
        private readonly DiscordUserRepository $discordUserRepository,
        private readonly WalletBalances $walletBalances,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<MarketListing>
     */
    public function getListings(): array
    {
        $this->reconcileOnce();

        return $this->listings ??= $this->listingRepository->findActivePage(
            $this->getSeller(),
            $this->getSeller() instanceof DiscordUser ? null : $this->getDiscordUser(),
            $this->getActiveExtension(),
            $this->getActiveRarity(),
            $this->getActiveHolo(),
            $this->getActiveSort(),
            $this->getCurrentPage(),
            self::PER_PAGE,
        );
    }

    public function getTotal(): int
    {
        return $this->total ??= $this->listingRepository->countActive(
            $this->getSeller(),
            $this->getSeller() instanceof DiscordUser ? null : $this->getDiscordUser(),
            $this->getActiveExtension(),
            $this->getActiveRarity(),
            $this->getActiveHolo(),
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

    /**
     * @return list<Extension>
     */
    public function getUniverseChips(): array
    {
        return $this->chips ??= $this->listingRepository->findExtensionsWithActiveListings(
            $this->getSeller(),
            $this->getSeller() instanceof DiscordUser ? null : $this->getDiscordUser(),
        );
    }

    /**
     * @return list<CardRarityEnum>
     */
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

    /** Why the buy button is dead, null when the listing can be bought. */
    public function getBuyBlock(MarketListing $listing): ?string
    {
        $balance = $this->getBalance();

        return match (true) {
            $listing->getSeller()->getDiscordId() === $this->getDiscordUser()->getDiscordId() => 'Ton annonce',
            !$balance instanceof CoinAmount => 'Youl Coin indisponible',
            $balance->isLessThan(CoinAmount::fromCoins($listing->getPrice())) => 'Solde insuffisant',
            default => null,
        };
    }

    public function resetPage(): void
    {
        $this->page = 1;
        $this->confirming = null;
    }

    #[LiveAction]
    public function filterUniverse(#[LiveArg] string $slug): void
    {
        $this->universe = '' === $slug ? null : $slug;
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
        $this->page = 1;
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

    private function getSeller(): ?DiscordUser
    {
        if (null === $this->sellerId) {
            return null;
        }

        return $this->discordUserRepository->find($this->sellerId) ?? throw $this->createNotFoundException('Unknown seller.');
    }

    private function getActiveExtension(): ?Extension
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

    private function getActiveRarity(): ?CardRarityEnum
    {
        return null === $this->rarity ? null : CardRarityEnum::tryFrom($this->rarity);
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
