<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\DiscordUser;
use App\Enum\Entity\CardRarityEnum;
use App\Exception\Booster\BoosterException;
use App\Repository\BoosterRepository;
use App\Repository\UserBoosterRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterClaimService;
use App\Service\Booster\BoosterOpeningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsLiveComponent]
final class BoosterHub extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?BoosterOpening $opening = null;

    /**
     * Card ids that the user did not own before the last opening (NOUVEAU badge).
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $newCardIds = [];

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly BoosterRepository $boosterRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly BoosterClaimService $boosterClaimService,
        private readonly BoosterOpeningService $boosterOpeningService,
    ) {
    }

    /**
     * @return list<Booster>
     */
    public function getBoosters(): array
    {
        return $this->boosterRepository->findPublished();
    }

    public function getRemainingClaims(): int
    {
        return $this->boosterClaimService->getRemainingClaims($this->getDiscordUser());
    }

    public function getNextResetTime(): \DateTimeImmutable
    {
        return $this->boosterClaimService->getNextResetTime();
    }

    /**
     * Server-computed remaining seconds before the quota reset, so the
     * client countdown never depends on the client clock.
     */
    public function getSecondsUntilReset(): int
    {
        return max(0, $this->getNextResetTime()->getTimestamp() - time());
    }

    /**
     * @return array<string, int> booster id => owned quantity
     */
    public function getInventory(): array
    {
        $inventory = [];

        foreach ($this->userBoosterRepository->findBy(['discordUser' => $this->getDiscordUser()]) as $userBooster) {
            $inventory[(string) $userBooster->getBooster()->getId()] = $userBooster->getQuantity();
        }

        return $inventory;
    }

    /**
     * Per-rarity count of the last opening, ordered from common to legendary.
     *
     * @return list<array{rarity: CardRarityEnum, count: int}>
     */
    public function getRaritySummary(): array
    {
        if (!$this->opening instanceof BoosterOpening) {
            return [];
        }

        $counts = [];
        foreach ($this->opening->getBoosterOpeningCards() as $openingCard) {
            $rarity = $openingCard->getCard()->getRarity()->value;
            $counts[$rarity] = ($counts[$rarity] ?? 0) + $openingCard->getQuantity();
        }

        $summary = [];
        foreach (CardRarityEnum::ascending() as $rarity) {
            if (isset($counts[$rarity->value])) {
                $summary[] = ['rarity' => $rarity, 'count' => $counts[$rarity->value]];
            }
        }

        return $summary;
    }

    #[LiveAction]
    public function claimBooster(#[LiveArg] string $boosterId): void
    {
        $this->error = null;

        $booster = $this->findBooster($boosterId);

        if (!$booster instanceof Booster) {
            $this->error = 'Booster introuvable.';

            return;
        }

        try {
            $this->boosterClaimService->claim($this->getDiscordUser(), $booster);
        } catch (BoosterException $exception) {
            $this->error = $exception->getUserMessage();
        }
    }

    #[LiveAction]
    public function openBooster(#[LiveArg] string $boosterId): void
    {
        $this->error = null;

        $booster = $this->findBooster($boosterId);

        if (!$booster instanceof Booster) {
            $this->error = 'Booster introuvable.';

            return;
        }

        $user = $this->getDiscordUser();
        $ownedBefore = $this->userCardRepository->findOwnedCardIds($user);

        try {
            $this->opening = $this->boosterOpeningService->open($user, $booster);
        } catch (BoosterException $exception) {
            $this->error = $exception->getUserMessage();

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
    public function closeModal(): void
    {
        $this->opening = null;
        $this->newCardIds = [];
        $this->error = null;
    }

    /**
     * The id is client-provided (LiveArg): a malformed uuid must resolve to
     * "not found" instead of a Doctrine conversion error.
     */
    private function findBooster(string $boosterId): ?Booster
    {
        if (!Uuid::isValid($boosterId)) {
            return null;
        }

        return $this->boosterRepository->find($boosterId);
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
