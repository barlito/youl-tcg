<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Attribute\RequiresFeature;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\FeatureEnum;
use App\Exception\Fusion\FusionException;
use App\Repository\CardRepository;
use App\Repository\UserCardRepository;
use App\Service\Fusion\FusionService;
use App\Service\Trade\EngagedCopies;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Fusion page: cards of which the player holds enough normal copies, grouped by
 * universe. Fusing is two clicks (ask, then confirm with a fusion count); the
 * displayed figures are informative only, FusionService re-validates under lock.
 */
#[RequiresFeature(FeatureEnum::FUSION)]
#[AsLiveComponent]
final class FusionHub extends AbstractController
{
    use DefaultActionTrait;

    /** Card awaiting the second click. */
    #[LiveProp]
    public ?string $confirming = null;

    #[LiveProp]
    public int $times = 1;

    /** Card that fused last, shown holo in the success banner. */
    #[LiveProp]
    public ?string $justFused = null;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $success = null;

    /**
     * @var array<string, UserCard>|null
     */
    private ?array $rows = null;

    /**
     * @var array<string, array{normal: int, holo: int}>|null
     */
    private ?array $reserved = null;

    /**
     * @var array<string, true>|null
     */
    private ?array $listed = null;

    public function __construct(
        private readonly UserCardRepository $userCardRepository,
        private readonly CardRepository $cardRepository,
        private readonly FusionService $fusionService,
        private readonly EngagedCopies $engagedCopies,
    ) {
    }

    public function getCost(): int
    {
        return FusionService::FUSION_COST;
    }

    /**
     * @return list<array{extension: Extension, rows: list<UserCard>, fusable: int}>
     */
    public function getUniverses(): array
    {
        $universes = [];
        foreach ($this->getRowMap() as $row) {
            $extension = $row->getCard()->getExtension();
            if (!$extension instanceof Extension) {
                continue;
            }

            $key = (string) $extension->getId();
            $universes[$key] ??= ['extension' => $extension, 'rows' => [], 'fusable' => 0];
            $universes[$key]['rows'][] = $row;
            $universes[$key]['fusable'] += $this->maxFusions($row);
        }

        $universes = array_values($universes);
        usort(
            $universes,
            static fn (array $a, array $b): int => $b['fusable'] <=> $a['fusable'] ?: $a['extension']->getName() <=> $b['extension']->getName(),
        );

        return $universes;
    }

    public function hasRows(): bool
    {
        return [] !== $this->getRowMap();
    }

    public function getFusableTotal(): int
    {
        return array_sum(array_map($this->maxFusions(...), array_values($this->getRowMap())));
    }

    public function freeNormal(UserCard $row): int
    {
        return FusionService::freeNormalCopies($row, $this->getReserved()[(string) $row->getCard()->getId()] ?? null);
    }

    public function maxFusions(UserCard $row): int
    {
        return FusionService::maxFusions($this->freeNormal($row));
    }

    /**
     * Normal copies of the card held back by a pending offer or a listing.
     */
    public function engagedNormal(UserCard $row): int
    {
        return $this->getReserved()[(string) $row->getCard()->getId()]['normal'] ?? 0;
    }

    public function isListed(UserCard $row): bool
    {
        $this->listed ??= $this->engagedCopies->listedCardIds($this->getDiscordUser());

        return isset($this->listed[(string) $row->getCard()->getId()]);
    }

    public function getFusedCard(): ?Card
    {
        return null !== $this->justFused && Uuid::isValid($this->justFused) ? $this->cardRepository->find($this->justFused) : null;
    }

    public function isConfirming(UserCard $row): bool
    {
        return $this->confirming === (string) $row->getCard()->getId();
    }

    #[LiveAction]
    public function askFuse(#[LiveArg] string $cardId): void
    {
        $this->resetMessages();

        $row = $this->getRowMap()[$cardId] ?? null;
        if (!$row instanceof UserCard || $this->maxFusions($row) < 1) {
            return;
        }

        $this->confirming = $cardId;
        $this->times = 1;
    }

    #[LiveAction]
    public function abort(): void
    {
        $this->confirming = null;
        $this->times = 1;
    }

    #[LiveAction]
    public function changeTimes(#[LiveArg] int $delta): void
    {
        $row = null === $this->confirming ? null : ($this->getRowMap()[$this->confirming] ?? null);
        if (!$row instanceof UserCard) {
            return;
        }

        $this->times = max(1, min($this->maxFusions($row), $this->times + $delta));
    }

    #[LiveAction]
    public function fuse(): void
    {
        $cardId = $this->confirming;
        $this->resetMessages();

        $card = null !== $cardId && Uuid::isValid($cardId) ? $this->cardRepository->find($cardId) : null;
        if (!$card instanceof Card) {
            $this->error = 'Choisis d\'abord une carte à fusionner.';
            $this->confirming = null;

            return;
        }

        try {
            $operation = $this->fusionService->fuse($this->getDiscordUser(), $card, $this->times);
        } catch (FusionException $exception) {
            $this->error = $exception->getUserMessage();
            $this->confirming = null;
            $this->forget();

            return;
        }

        $this->confirming = null;
        $this->forget();
        $this->justFused = $cardId;
        $this->success = \sprintf(
            '%d copie%s normale%s de « %s » fusionnée%s en %d holo ✦ !',
            $operation->getCopiesConsumed(),
            $operation->getCopiesConsumed() > 1 ? 's' : '',
            $operation->getCopiesConsumed() > 1 ? 's' : '',
            $card->getName(),
            $operation->getCopiesConsumed() > 1 ? 's' : '',
            $operation->getHolosCreated(),
        );
        $this->times = 1;
    }

    /**
     * @return array<string, UserCard> card id => row
     */
    private function getRowMap(): array
    {
        if (null !== $this->rows) {
            return $this->rows;
        }

        $rows = [];
        foreach ($this->userCardRepository->findWithNormalCopies($this->getDiscordUser(), FusionService::FUSION_COST) as $userCard) {
            $rows[(string) $userCard->getCard()->getId()] = $userCard;
        }

        return $this->rows = $rows;
    }

    /**
     * @return array<string, array{normal: int, holo: int}>
     */
    private function getReserved(): array
    {
        return $this->reserved ??= $this->engagedCopies->reservedQuantities($this->getDiscordUser());
    }

    /**
     * The memoized inventory and ledger are stale after a fusion.
     */
    private function forget(): void
    {
        $this->rows = null;
        $this->reserved = null;
        $this->listed = null;
    }

    private function resetMessages(): void
    {
        $this->error = null;
        $this->success = null;
        $this->justFused = null;
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
