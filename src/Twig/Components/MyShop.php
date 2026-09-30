<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Exception\Market\MarketException;
use App\Repository\CardRepository;
use App\Repository\MarketListingRepository;
use App\Repository\MarketPurchaseRepository;
use App\Service\Market\MarketListingService;
use App\Service\Market\MarketPurchaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MyShop extends AbstractController
{
    use DefaultActionTrait;

    public const int HISTORY_LIMIT = 20;
    public const int SELLABLE_LIMIT = 24;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $success = null;

    #[LiveProp]
    public ?string $editingId = null;

    #[LiveProp(writable: true)]
    public string $editPrice = '';

    #[LiveProp]
    public ?string $selling = null;

    #[LiveProp(writable: true)]
    public string $sellPrice = '';

    #[LiveProp(writable: true)]
    public string $search = '';

    /** @var list<array{card: Card, normal: int, holo: int}>|null */
    private ?array $sellable = null;

    private bool $reconciled = false;

    public function __construct(
        private readonly MarketListingRepository $listingRepository,
        private readonly MarketPurchaseRepository $purchaseRepository,
        private readonly MarketListingService $listingService,
        private readonly MarketPurchaseService $purchaseService,
        private readonly CardRepository $cardRepository,
    ) {
    }

    /** @return list<MarketListing> */
    public function getListings(): array
    {
        $this->reconcileOnce();

        return $this->listingRepository->findEngagedBySeller($this->getDiscordUser());
    }

    public function getMaxListings(): int
    {
        return MarketListingService::MAX_ACTIVE_LISTINGS;
    }

    /** @return list<array{card: Card, normal: int, holo: int}> */
    public function getSellable(): array
    {
        $needle = mb_trim(mb_strtolower($this->search));

        return \array_slice(array_values(array_filter(
            $this->allSellable(),
            static fn (array $copy): bool => '' === $needle || str_contains(mb_strtolower($copy['card']->getName()), $needle),
        )), 0, self::SELLABLE_LIMIT);
    }

    public function getSellableCount(): int
    {
        return \count($this->allSellable());
    }

    /** @return list<MarketPurchase> */
    public function getHistory(): array
    {
        return $this->purchaseRepository->findRecentFor($this->getDiscordUser(), self::HISTORY_LIMIT);
    }

    public function isSeller(MarketPurchase $purchase): bool
    {
        return $purchase->getSeller()->getDiscordId() === $this->getDiscordUser()->getDiscordId();
    }

    public function netPayout(MarketPurchase $purchase): string
    {
        return MarketPurchaseService::payoutAmount($purchase)->format();
    }

    public function canPublish(): bool
    {
        return \count($this->getListings()) < $this->getMaxListings();
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] string $listingId): void
    {
        $this->resetMessages();
        $listing = $this->findOwnListing($listingId);

        if ($listing instanceof MarketListing) {
            $this->editingId = $listingId;
            $this->editPrice = (string) $listing->getPrice();
        }
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    #[LiveAction]
    public function savePrice(#[LiveArg] string $listingId): void
    {
        $this->resetMessages();
        $listing = $this->findOwnListing($listingId);
        $price = $this->parsePrice($this->editPrice);

        if (!$listing instanceof MarketListing || null === $price) {
            $this->error ??= 'Cette annonce n\'existe plus.';

            return;
        }

        $this->run(fn () => $this->listingService->changePrice($this->getDiscordUser(), $listing, $price), 'Prix mis à jour.');
        $this->editingId = null;
    }

    #[LiveAction]
    public function withdraw(#[LiveArg] string $listingId): void
    {
        $this->resetMessages();
        $listing = $this->findOwnListing($listingId);

        if (!$listing instanceof MarketListing) {
            $this->error = 'Cette annonce n\'existe plus.';

            return;
        }

        $this->run(fn () => $this->listingService->withdraw($this->getDiscordUser(), $listing), 'Annonce retirée : la carte est de nouveau libre.');
    }

    #[LiveAction]
    public function startSelling(#[LiveArg] string $cardId, #[LiveArg] string $finish): void
    {
        $this->resetMessages();
        $this->selling = \in_array($finish, ['normal', 'holo'], true) ? $cardId . '|' . $finish : null;
        $this->sellPrice = '';
    }

    #[LiveAction]
    public function cancelSelling(): void
    {
        $this->selling = null;
    }

    #[LiveAction]
    public function publish(): void
    {
        $this->resetMessages();
        [$cardId, $finish] = array_pad(explode('|', (string) $this->selling, 2), 2, '');
        $card = Uuid::isValid($cardId) ? $this->cardRepository->find($cardId) : null;
        $price = $this->parsePrice($this->sellPrice);

        if (!$card instanceof Card || !\in_array($finish, ['normal', 'holo'], true) || null === $price) {
            $this->error ??= 'Choisis une carte et un prix valides.';

            return;
        }

        $this->run(fn (): MarketListing => $this->listingService->create($this->getDiscordUser(), $card, 'holo' === $finish, $price), 'Carte mise en vente !');

        if (null === $this->error) {
            $this->selling = null;
            $this->sellPrice = '';
            $this->sellable = null;
        }
    }

    /** @return list<array{card: Card, normal: int, holo: int}> */
    private function allSellable(): array
    {
        return $this->sellable ??= $this->listingService->getSellableCopies($this->getDiscordUser());
    }

    private function run(\Closure $action, string $success): void
    {
        try {
            $action();
            $this->success = $success;
        } catch (MarketException $exception) {
            $this->error = $exception->getUserMessage();
        }
    }

    private function parsePrice(string $raw): ?int
    {
        $raw = mb_trim($raw);

        if (!ctype_digit($raw) || \strlen($raw) > 9) {
            $this->error = 'Le prix doit être un nombre entier de coins.';

            return null;
        }

        return (int) $raw;
    }

    private function findOwnListing(string $listingId): ?MarketListing
    {
        // client-provided: a malformed id is "not found", never a conversion error
        $listing = Uuid::isValid($listingId) ? $this->listingRepository->find($listingId) : null;

        return $listing instanceof MarketListing && $listing->getSeller()->getDiscordId() === $this->getDiscordUser()->getDiscordId() ? $listing : null;
    }

    private function resetMessages(): void
    {
        $this->error = null;
        $this->success = null;
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
