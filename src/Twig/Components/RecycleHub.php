<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\RecycleSelectionLine;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Exception\Recycle\RecycleException;
use App\Repository\BoosterRepository;
use App\Repository\CardRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Recycle\RecycleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Recycle page: pick duplicate copies (normal / holo counted apart), watch the
 * live point total, choose a claimable booster, confirm. The selection lives
 * in a non-writable LiveProp mutated by actions only (checksummed, so the
 * client cannot forge it), and RecycleService re-validates everything under
 * lock anyway — the component never trusts client-computed points.
 */
#[AsLiveComponent]
final class RecycleHub extends AbstractController
{
    use DefaultActionTrait;

    private const string KIND_NORMAL = 'normal';
    private const string KIND_HOLO = 'holo';

    /**
     * @var array<string, array{normal: int, holo: int}> card id => selected copies
     */
    #[LiveProp]
    public array $selection = [];

    #[LiveProp(writable: true)]
    public ?string $boosterId = null;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $success = null;

    /**
     * Recyclable rows are read by several getters per render: memoize them
     * for the lifetime of the (per-request) component instance.
     *
     * @var array<string, UserCard>|null
     */
    private ?array $rows = null;

    public function __construct(
        private readonly UserCardRepository $userCardRepository,
        private readonly BoosterRepository $boosterRepository,
        private readonly CardRepository $cardRepository,
        private readonly BoosterAvailabilityService $boosterAvailability,
        private readonly RecycleService $recycleService,
    ) {
    }

    /**
     * @return list<UserCard> inventory rows with at least one duplicate
     */
    public function getRows(): array
    {
        return array_values($this->getRowMap());
    }

    /**
     * Boosters offered in exchange: the claimable published ones — the same
     * set the daily claim allows, enforced again by RecycleService.
     *
     * @return list<Booster>
     */
    public function getBoosters(): array
    {
        return array_values(array_filter(
            $this->boosterRepository->findPublished(),
            $this->boosterAvailability->isClaimable(...),
        ));
    }

    public function getCost(): int
    {
        return RecycleService::BOOSTER_COST;
    }

    /**
     * @return list<CardRarityEnum> the point scale legend, least rare first
     */
    public function getScale(): array
    {
        return CardRarityEnum::ascending();
    }

    /**
     * Server-computed point total of the current selection (never client math).
     */
    public function getPoints(): int
    {
        $points = 0;

        foreach ($this->selection as $cardId => $copies) {
            $row = $this->getRowMap()[$cardId] ?? null;

            if (!$row instanceof UserCard) {
                continue;
            }

            $rarity = $row->getCard()->getRarity();
            $points += $copies[self::KIND_NORMAL] * $rarity->recyclePoints()
                + $copies[self::KIND_HOLO] * $rarity->holoRecyclePoints();
        }

        return $points;
    }

    public function getSelectedCopies(): int
    {
        $copies = 0;

        foreach ($this->selection as $selected) {
            $copies += $selected[self::KIND_NORMAL] + $selected[self::KIND_HOLO];
        }

        return $copies;
    }

    public function getSurplus(): int
    {
        return max(0, $this->getPoints() - RecycleService::BOOSTER_COST);
    }

    /**
     * @return array{normal: int, holo: int}
     */
    public function selectionFor(string | Uuid $cardId): array
    {
        return $this->selection[(string) $cardId] ?? [self::KIND_NORMAL => 0, self::KIND_HOLO => 0];
    }

    #[LiveAction]
    public function addCopy(#[LiveArg] string $cardId, #[LiveArg] string $kind): void
    {
        $this->resetMessages();

        $row = $this->getRowMap()[$cardId] ?? null;

        if (!$row instanceof UserCard || !\in_array($kind, [self::KIND_NORMAL, self::KIND_HOLO], true)) {
            return;
        }

        $selected = $this->selectionFor($cardId);

        // keep-one rule: at most quantity - 1 copies of a card, kinds combined
        if ($selected[self::KIND_NORMAL] + $selected[self::KIND_HOLO] >= $row->getQuantity() - 1) {
            return;
        }

        // quantity is the TOTAL (holo included): normal copies = quantity - holoQuantity
        $kindCap = self::KIND_NORMAL === $kind
            ? $row->getQuantity() - $row->getHoloQuantity()
            : $row->getHoloQuantity();

        if ($selected[$kind] >= $kindCap) {
            return;
        }

        ++$selected[$kind];
        $this->selection[$cardId] = $selected;
    }

    #[LiveAction]
    public function removeCopy(#[LiveArg] string $cardId, #[LiveArg] string $kind): void
    {
        $this->resetMessages();

        if (!\in_array($kind, [self::KIND_NORMAL, self::KIND_HOLO], true)) {
            return;
        }

        $selected = $this->selectionFor($cardId);
        $selected[$kind] = max(0, $selected[$kind] - 1);

        if (0 === $selected[self::KIND_NORMAL] && 0 === $selected[self::KIND_HOLO]) {
            unset($this->selection[$cardId]);

            return;
        }

        $this->selection[$cardId] = $selected;
    }

    #[LiveAction]
    public function recycle(): void
    {
        $this->resetMessages();

        if ([] === $this->selection) {
            $this->error = 'Sélectionne d\'abord des copies à recycler.';

            return;
        }

        $booster = $this->findBooster();

        if (!$booster instanceof Booster) {
            $this->error = 'Choisis le pack à récupérer en échange.';

            return;
        }

        $lines = [];
        foreach ($this->selection as $cardId => $copies) {
            $card = Uuid::isValid($cardId) ? $this->cardRepository->find($cardId) : null;

            if (!$card instanceof Card) {
                $this->error = 'Sélection invalide, recharge la page et réessaie.';

                return;
            }

            $lines[] = new RecycleSelectionLine($card, $copies[self::KIND_NORMAL], $copies[self::KIND_HOLO]);
        }

        try {
            $operation = $this->recycleService->recycle($this->getDiscordUser(), $lines, $booster);
        } catch (RecycleException $exception) {
            $this->error = $exception->getUserMessage();

            return;
        }

        $surplus = $operation->getPoints() - RecycleService::BOOSTER_COST;
        $copies = $operation->getRecycledCardCount();

        $this->selection = [];
        $this->rows = null; // the memoized inventory is stale after the debit
        $this->success = \sprintf(
            '%d copie%s recyclée%s — 1 pack « %s » ajouté à ton stock !%s',
            $copies,
            $copies > 1 ? 's' : '',
            $copies > 1 ? 's' : '',
            $booster->getDisplayName(),
            $surplus > 0 ? \sprintf(' (%d point%s de surplus perdu%s)', $surplus, $surplus > 1 ? 's' : '', $surplus > 1 ? 's' : '') : '',
        );
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

        foreach ($this->userCardRepository->findRecyclableWithCards($this->getDiscordUser()) as $userCard) {
            $rows[(string) $userCard->getCard()->getId()] = $userCard;
        }

        return $this->rows = $rows;
    }

    /**
     * The id is client-provided (writable prop): a malformed uuid must resolve
     * to "not found" instead of a Doctrine conversion error.
     */
    private function findBooster(): ?Booster
    {
        if (null === $this->boosterId || !Uuid::isValid($this->boosterId)) {
            return null;
        }

        return $this->boosterRepository->find($this->boosterId);
    }

    private function resetMessages(): void
    {
        $this->error = null;
        $this->success = null;
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
