<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\DrawnCard;
use App\Entity\Booster;
use App\Entity\BoosterOpening as BoosterOpeningEntity;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Enum\Entity\CardRarityEnum;
use App\Exception\Booster\BoosterException;
use App\Repository\BoosterRepository;
use App\Repository\CardRepository;
use App\Repository\UserBoosterRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterOpeningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Single-booster opening (dedicated page, packs.com-style). The pack stands on
 * the left, the extension's card set on the right; `open()` draws server-side
 * (one atomic, pessimistic-locked transaction via BoosterOpeningService) and the
 * client choreographs the reveal as the player peels the 3D pack.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsLiveComponent]
final class BoosterOpening extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: false)]
    public string $boosterId = '';

    #[LiveProp]
    public ?BoosterOpeningEntity $opening = null;

    /**
     * Card ids the user did not own before this opening (NOUVEAU highlight).
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $newCardIds = [];

    /**
     * The drawn slots in TRUE draw order ({cardId, holo} per slot), captured at
     * open() time — the audit rows aggregate duplicates, so the slot order only
     * survives here. The reveal follows it faithfully: the player reads per-slot
     * drop rates, the cards must come out slot by slot.
     *
     * @var list<array{cardId: string, holo: bool}>
     */
    #[LiveProp]
    public array $revealOrder = [];

    #[LiveProp]
    public ?string $error = null;

    private ?Booster $boosterCache = null;

    /** @var list<Card>|null */
    private ?array $catalogCache = null;

    /**
     * Owned card ids for the mask (see getOwnedCardIds). Memoized per request:
     * open() seeds it with its pre-draw snapshot — the post-credit owned set
     * minus newCardIds is exactly the pre-draw set, so the re-render needs no
     * second findOwnedCardIds query.
     *
     * @var array<string, true>|null
     */
    private ?array $ownedCardIdsCache = null;

    public function __construct(
        private readonly BoosterRepository $boosterRepository,
        private readonly CardRepository $cardRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly BoosterOpeningService $boosterOpeningService,
    ) {
    }

    public function getBooster(): Booster
    {
        if (!$this->boosterCache instanceof Booster) {
            $booster = $this->boosterRepository->find($this->boosterId);

            if (null === $booster) {
                throw $this->createNotFoundException();
            }

            $this->boosterCache = $booster;
        }

        return $this->boosterCache;
    }

    /**
     * The extension's published cards ("set contents"), rarest FIRST then name —
     * this is also the catalog order the aside numbers the cards by (n°1 = rarest).
     *
     * @return list<Card>
     */
    public function getExtensionCatalog(): array
    {
        if (null !== $this->catalogCache) {
            return $this->catalogCache;
        }

        $cards = $this->cardRepository->findPublishedByExtension($this->getBooster()->getExtension());

        usort(
            $cards,
            static fn (Card $a, Card $b): int => CardRarityEnum::compareRarestFirst($a->getRarity(), $b->getRarity())
                ?: $a->getName() <=> $b->getName(),
        );

        return $this->catalogCache = $cards;
    }

    /**
     * « Taux de ce pack »: each rarity's share of the pack's cards (the per-slot
     * percentages averaged over the slots), plus the average holo and 1/1 chances.
     *
     * @return array{rarities: array<string, float>, holo: float, unique: float}
     */
    public function getPackRates(): array
    {
        $slots = $this->getBooster()->getDropRates();
        $count = \count($slots);
        $rarities = [];
        $holo = 0.0;
        $unique = 0.0;

        foreach (CardRarityEnum::ascending() as $rarity) {
            $sum = 0.0;
            foreach ($slots as $slot) {
                $sum += $slot['rates'][$rarity->value] ?? 0.0;
            }
            if ($sum > 0) {
                $rarities[$rarity->value] = round($sum / $count, 1);
            }
        }

        foreach ($slots as $slot) {
            $holo += $slot['holoChance'];
            $unique += $slot['uniqueRate'];
        }

        return [
            'rarities' => $rarities,
            'holo' => $count > 0 ? round($holo / $count, 1) : 0.0,
            'unique' => $count > 0 ? round($unique / $count, 2) : 0.0,
        ];
    }

    /**
     * Published cards of the set the player already owned BEFORE the ongoing
     * opening (the mask), i.e. the « x / y trouvées » counter before the reveal.
     */
    public function getFoundCount(): int
    {
        $owned = $this->getOwnedCardIds();

        return \count(array_filter(
            $this->getExtensionCatalog(),
            static fn (Card $card): bool => isset($owned[(string) $card->getId()]),
        ));
    }

    /**
     * End-of-opening summary: best find (1/1, then rarest, then holo, then new,
     * first in draw order), new card count and the universe completion before →
     * after the draw — all derived from data already loaded, no extra query.
     *
     * @return array{
     *     best: array{card: Card, holo: bool, new: bool, index: int},
     *     cardCount: int,
     *     newCount: int,
     *     before: array{owned: int, total: int, percentage: int},
     *     after: array{owned: int, total: int, percentage: int},
     * }|null
     */
    public function getRecap(): ?array
    {
        $reveal = $this->getRevealCards();
        if ([] === $reveal) {
            return null;
        }

        $best = null;
        $bestScore = -1;
        foreach ($reveal as $index => $slot) {
            $card = $slot['card'];
            $new = \in_array((string) $card->getId(), $this->newCardIds, true);
            $score = ($card->isUnique() ? 100 : $card->getRarity()->rank() * 10) + ($slot['holo'] ? 2 : 0) + ($new ? 1 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['card' => $card, 'holo' => $slot['holo'], 'new' => $new, 'index' => $index];
            }
        }
        \assert(null !== $best);

        $catalog = $this->getExtensionCatalog();
        $total = \count($catalog);
        $ownedBefore = $this->getFoundCount();
        $newInSet = \count(array_filter(
            $catalog,
            fn (Card $card): bool => \in_array((string) $card->getId(), $this->newCardIds, true),
        ));

        return [
            'best' => $best,
            'cardCount' => \count($reveal),
            'newCount' => \count(array_unique($this->newCardIds)),
            'before' => $this->completion($ownedBefore, $total),
            'after' => $this->completion($ownedBefore + $newInSet, $total),
        ];
    }

    /**
     * Drawn cards in DRAW order: the reveal mirrors the booster's slot order
     * (whose per-slot rates the player can read), not a rarest-last re-sort —
     * a lucky legendary on slot 1 comes out first.
     *
     * @return list<array{card: Card, holo: bool}>
     */
    public function getRevealCards(): array
    {
        if (!$this->opening instanceof BoosterOpeningEntity) {
            return [];
        }

        $cardsById = [];
        foreach ($this->opening->getBoosterOpeningCards() as $openingCard) {
            $cardsById[(string) $openingCard->getCard()->getId()] = $openingCard->getCard();
        }

        $cards = [];
        foreach ($this->revealOrder as $slot) {
            if (isset($cardsById[$slot['cardId']])) {
                $cards[] = ['card' => $cardsById[$slot['cardId']], 'holo' => $slot['holo']];
            }
        }

        return $cards;
    }

    /**
     * Per-card draw summary keyed by card id, to annotate the catalog slots.
     *
     * @return array<string, array{quantity: int, holoQuantity: int, new: bool}>
     */
    public function getDrawnByCardId(): array
    {
        if (!$this->opening instanceof BoosterOpeningEntity) {
            return [];
        }

        $drawn = [];
        foreach ($this->opening->getBoosterOpeningCards() as $openingCard) {
            $cardId = (string) $openingCard->getCard()->getId();
            $drawn[$cardId] = [
                'quantity' => $openingCard->getQuantity(),
                'holoQuantity' => $openingCard->getHoloQuantity(),
                'new' => \in_array($cardId, $this->newCardIds, true),
            ];
        }

        return $drawn;
    }

    /**
     * Card ids the user owns (or has owned). The set list masks every other card
     * as "?" so the opening only reveals what the player actually has / gets.
     *
     * Cards FIRST acquired by the ongoing opening are excluded on purpose: the
     * inventory is already credited when the component re-renders, and listing
     * them would spoil the showcase before a single flip. They render as
     * `is-pending` tiles that the reveal controller unmasks flip by flip.
     *
     * @return array<string, true> card id => true
     */
    public function getOwnedCardIds(): array
    {
        if (null !== $this->ownedCardIdsCache) {
            return $this->ownedCardIdsCache;
        }

        $owned = array_fill_keys($this->userCardRepository->findOwnedCardIds($this->getDiscordUser()), true);

        foreach ($this->newCardIds as $cardId) {
            unset($owned[$cardId]);
        }

        return $this->ownedCardIdsCache = $owned;
    }

    public function getOwnedCount(): int
    {
        $userBooster = $this->userBoosterRepository->findOneBy([
            'discordUser' => $this->getDiscordUser(),
            'booster' => $this->getBooster(),
        ]);

        return $userBooster?->getQuantity() ?? 0;
    }

    #[LiveAction]
    public function open(): void
    {
        $this->error = null;

        $user = $this->getDiscordUser();
        $ownedBefore = $this->userCardRepository->findOwnedCardIds($user);
        // the re-render's mask (owned minus newCardIds) IS the pre-draw set:
        // seed the memo so it doesn't re-query after the credit
        $this->ownedCardIdsCache = array_fill_keys($ownedBefore, true);

        try {
            $result = $this->boosterOpeningService->open($user, $this->getBooster());
        } catch (BoosterException $exception) {
            $this->error = $exception->getUserMessage();
            $this->boosterCache = null;
            $this->ownedCardIdsCache = null;
            // drop the previous draw so the client reseals the pack and shows the right CTA
            $this->opening = null;
            $this->revealOrder = [];
            $this->newCardIds = [];

            return;
        }

        $this->opening = $result->opening;
        $this->revealOrder = array_map(
            static fn (DrawnCard $drawnCard): array => ['cardId' => (string) $drawnCard->card->getId(), 'holo' => $drawnCard->holo],
            $result->drawnCards,
        );

        $this->newCardIds = [];
        foreach ($this->opening->getBoosterOpeningCards() as $openingCard) {
            $cardId = (string) $openingCard->getCard()->getId();
            if (!\in_array($cardId, $ownedBefore, true)) {
                $this->newCardIds[] = $cardId;
            }
        }
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->opening = null;
        $this->newCardIds = [];
        $this->revealOrder = [];
        $this->error = null;
    }

    /**
     * @return array{owned: int, total: int, percentage: int}
     */
    private function completion(int $owned, int $total): array
    {
        return [
            'owned' => $owned,
            'total' => $total,
            'percentage' => $total > 0 ? (int) floor($owned / $total * 100) : 0,
        ];
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
