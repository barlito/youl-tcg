<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Enum\Entity\CardRarityEnum;
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

    #[LiveProp(writable: true)]
    public string $universe = '';

    #[LiveProp(writable: true)]
    public string $rarity = '';

    #[LiveProp(writable: true)]
    public string $finish = '';

    #[LiveProp(writable: true)]
    public bool $duplicatesOnly = false;

    #[LiveProp(writable: true)]
    public string $sort = 'copies';

    /** @var list<array{card: Card, normal: int, holo: int}>|null */
    private ?array $sellable = null;

    /** @var list<array{card: Card, finish: string, copies: int, cardCopies: int}>|null */
    private ?array $rows = null;

    /** @var array<string, int>|null */
    private ?array $lastSales = null;

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

    /**
     * One row per (card, finish) with free copies, filtered and sorted, capped at SELLABLE_LIMIT.
     *
     * @return list<array{card: Card, finish: string, copies: int, cardCopies: int}>
     */
    public function getSellable(): array
    {
        return \array_slice($this->getFilteredRows(), 0, self::SELLABLE_LIMIT);
    }

    public function getMatchingCount(): int
    {
        return \count($this->getFilteredRows());
    }

    /** Sellable (card, finish) rows before any filter. */
    public function getSellableCount(): int
    {
        return array_sum(array_map(static fn (array $copy): int => ($copy['normal'] > 0 ? 1 : 0) + ($copy['holo'] > 0 ? 1 : 0), $this->allSellable()));
    }

    /**
     * Universes holding sellable copies, with their count of cards, by name.
     *
     * @return list<array{extension: Extension, count: int}>
     */
    public function getUniverseOptions(): array
    {
        $options = [];
        foreach ($this->allSellable() as $copy) {
            $extension = $copy['card']->getExtension();
            if (!$extension instanceof Extension) {
                continue;
            }

            $options[(string) $extension->getId()] ??= ['extension' => $extension, 'count' => 0];
            ++$options[(string) $extension->getId()]['count'];
        }

        $options = array_values($options);
        usort($options, static fn (array $a, array $b): int => $a['extension']->getName() <=> $b['extension']->getName());

        return $options;
    }

    /** @return list<CardRarityEnum> */
    public function getRarities(): array
    {
        return array_reverse(CardRarityEnum::ascending());
    }

    public function hasActiveFilters(): bool
    {
        return '' !== mb_trim($this->search) || '' !== $this->universe || $this->getActiveRarity() instanceof CardRarityEnum
            || \in_array($this->finish, ['normal', 'holo'], true) || $this->duplicatesOnly;
    }

    /**
     * Price of the last concluded market sale of this card in this finish, null when never sold.
     */
    public function lastSale(Card $card, string $finish): ?int
    {
        if (null === $this->lastSales) {
            $cardIds = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['card']->getId(), $this->getSellable())));
            $this->lastSales = $this->purchaseRepository->findLastSalePrices($cardIds);
        }

        return $this->lastSales[$card->getId() . '|' . $finish] ?? null;
    }

    #[LiveAction]
    public function toggleDuplicates(): void
    {
        $this->duplicatesOnly = !$this->duplicatesOnly;
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->search = '';
        $this->universe = '';
        $this->rarity = '';
        $this->finish = '';
        $this->duplicatesOnly = false;
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
            $this->rows = null;
            $this->lastSales = null;
        }
    }

    /** @return list<array{card: Card, finish: string, copies: int, cardCopies: int}> */
    private function getFilteredRows(): array
    {
        if (null !== $this->rows) {
            return $this->rows;
        }

        $rows = [];

        foreach ($this->allSellable() as $copy) {
            $card = $copy['card'];
            if (!$this->matchesFilters($card, $copy['normal'] + $copy['holo'])) {
                continue;
            }

            foreach (['normal', 'holo'] as $finish) {
                if ($copy[$finish] > 0 && \in_array($this->finish, ['', $finish], true)) {
                    $rows[] = ['card' => $card, 'finish' => $finish, 'copies' => $copy[$finish], 'cardCopies' => $copy['normal'] + $copy['holo']];
                }
            }
        }

        usort($rows, match ($this->sort) {
            'rarity' => static fn (array $a, array $b): int => CardRarityEnum::compareRarestFirst($a['card']->getRarity(), $b['card']->getRarity())
                ?: $a['card']->getName() <=> $b['card']->getName(),
            'name' => static fn (array $a, array $b): int => $a['card']->getName() <=> $b['card']->getName() ?: $a['finish'] <=> $b['finish'],
            default => static fn (array $a, array $b): int => $b['copies'] <=> $a['copies'] ?: $a['card']->getName() <=> $b['card']->getName(),
        });

        return $this->rows = $rows;
    }

    private function matchesFilters(Card $card, int $freeCopies): bool
    {
        $needle = mb_strtolower(mb_trim($this->search));
        $rarity = $this->getActiveRarity();

        return ('' === $needle || str_contains(mb_strtolower($card->getName()), $needle))
            && ('' === $this->universe || $card->getExtension()?->getSlug() === $this->universe)
            && (!$rarity instanceof CardRarityEnum || $card->getRarity() === $rarity)
            && (!$this->duplicatesOnly || $freeCopies >= 2);
    }

    private function getActiveRarity(): ?CardRarityEnum
    {
        return CardRarityEnum::tryFrom($this->rarity);
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
