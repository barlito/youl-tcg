<?php

declare(strict_types=1);

namespace App\Twig\Components;

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

    #[LiveProp]
    public ?string $error = null;

    private ?Booster $boosterCache = null;

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
     * The extension's published cards ("set contents"), rarest last so the aside
     * reads common → legendary like the reveal order.
     *
     * @return list<Card>
     */
    public function getExtensionCatalog(): array
    {
        $cards = $this->cardRepository->findPublishedByExtension($this->getBooster()->getExtension());
        $rank = $this->rarityRanks();

        usort(
            $cards,
            static fn (Card $a, Card $b): int => [$rank[$a->getRarity()->value], $a->getName()]
                <=> [$rank[$b->getRarity()->value], $b->getName()],
        );

        return $cards;
    }

    /**
     * Drawn cards in reveal order (rarest revealed last — the climax), holo copies
     * flagged so the CardComponent lights up its holo layers.
     *
     * @return list<array{card: Card, holo: bool}>
     */
    public function getRevealCards(): array
    {
        if (!$this->opening instanceof BoosterOpeningEntity) {
            return [];
        }

        $rank = $this->rarityRanks();

        $cards = [];
        foreach ($this->opening->getBoosterOpeningCards() as $openingCard) {
            $card = $openingCard->getCard();
            for ($copy = 0; $copy < $openingCard->getQuantity(); ++$copy) {
                $cards[] = ['card' => $card, 'holo' => $copy < $openingCard->getHoloQuantity()];
            }
        }

        usort(
            $cards,
            static fn (array $a, array $b): int => $rank[$a['card']->getRarity()->value] <=> $rank[$b['card']->getRarity()->value],
        );

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
     * @return array<string, true> card id => true
     */
    public function getOwnedCardIds(): array
    {
        return array_fill_keys($this->userCardRepository->findOwnedCardIds($this->getDiscordUser()), true);
    }

    public function getOwnedCount(): int
    {
        $userBooster = $this->userBoosterRepository->findOneBy([
            'discordUser' => $this->getDiscordUser(),
            'booster' => $this->getBooster(),
        ]);

        return null !== $userBooster ? $userBooster->getQuantity() : 0;
    }

    #[LiveAction]
    public function open(): void
    {
        $this->error = null;

        $user = $this->getDiscordUser();
        $ownedBefore = $this->userCardRepository->findOwnedCardIds($user);

        try {
            $this->opening = $this->boosterOpeningService->open($user, $this->getBooster());
        } catch (BoosterException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

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
        $this->error = null;
    }

    /**
     * @return array<string, int> rarity value => ascending rank
     */
    private function rarityRanks(): array
    {
        return array_flip(array_map(
            static fn (CardRarityEnum $rarity): string => $rarity->value,
            CardRarityEnum::ascending(),
        ));
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
