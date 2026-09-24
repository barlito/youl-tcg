<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\RecycleSelectionLine;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
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
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * Recycle page: pick duplicate copies (normal / holo counted apart) grouped by
 * universe, watch the live point total, choose a retrievable booster, confirm —
 * every full tranche of points is one copy of that booster. The selection lives
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

    /**
     * Universe filter (extension slug), mirrored in the url: an unknown slug
     * simply shows every universe.
     */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'univers'))]
    public ?string $universe = null;

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

    /**
     * @var list<Booster>|null
     */
    private ?array $boosters = null;

    public function __construct(
        private readonly UserCardRepository $userCardRepository,
        private readonly BoosterRepository $boosterRepository,
        private readonly CardRepository $cardRepository,
        private readonly BoosterAvailabilityService $boosterAvailability,
        private readonly RecycleService $recycleService,
    ) {
    }

    /**
     * Universes holding at least one duplicate, the most recyclable first
     * (name as tiebreak); cards inside sorted rarest first then by name.
     *
     * @return list<array{extension: Extension, rows: list<UserCard>, recyclable: int, maxPoints: int}>
     */
    public function getUniverses(): array
    {
        $universes = [];
        foreach ($this->getRowMap() as $row) {
            $extension = $row->getCard()->getExtension();
            if (!$extension instanceof Extension) {
                continue; // the column is NOT NULL: never happens on a persisted card
            }

            $key = (string) $extension->getId();
            $universes[$key] ??= ['extension' => $extension, 'rows' => [], 'recyclable' => 0, 'maxPoints' => 0];
            $universes[$key]['rows'][] = $row;
            $universes[$key]['recyclable'] += $this->recyclableCopies($row);
            $universes[$key]['maxPoints'] += $this->maxPoints($row);
        }

        $universes = array_values($universes);
        usort(
            $universes,
            static fn (array $a, array $b): int => $b['recyclable'] <=> $a['recyclable']
                ?: $a['extension']->getName() <=> $b['extension']->getName(),
        );

        return $universes;
    }

    /**
     * The universes rendered under the current filter.
     *
     * @return list<array{extension: Extension, rows: list<UserCard>, recyclable: int, maxPoints: int}>
     */
    public function getVisibleUniverses(): array
    {
        $active = $this->getActiveExtension();

        return array_values(array_filter(
            $this->getUniverses(),
            static fn (array $universe): bool => !$active instanceof Extension || $universe['extension'] === $active,
        ));
    }

    public function getActiveExtension(): ?Extension
    {
        foreach ($this->getUniverses() as $universe) {
            if ($universe['extension']->getSlug() === $this->universe) {
                return $universe['extension'];
            }
        }

        return null;
    }

    /**
     * Tiles of the shared universe strip, recyclable copies instead of completion.
     *
     * @return list<array{extension: Extension, stat: string, caption: string, bar: null, coverImage: string|null}>
     */
    public function getStrip(): array
    {
        $coverImages = $this->cardRepository->findCoverImageNamesByExtension();

        return array_map(static fn (array $universe): array => [
            'extension' => $universe['extension'],
            'stat' => (string) $universe['recyclable'],
            'caption' => $universe['recyclable'] > 1 ? 'recyclables' : 'recyclable',
            'bar' => null,
            'coverImage' => $coverImages[(string) $universe['extension']->getId()] ?? null,
        ], $this->getUniverses());
    }

    public function hasRows(): bool
    {
        return [] !== $this->getRowMap();
    }

    /**
     * Copies that can leave the collection: all but one, whatever their kind.
     */
    public function recyclableCopies(UserCard $row): int
    {
        return max(0, $row->getQuantity() - 1);
    }

    /**
     * Normal copies selectable at most (quantity is the TOTAL, holo included).
     */
    public function normalCap(UserCard $row): int
    {
        return min($row->getQuantity() - $row->getHoloQuantity(), $this->recyclableCopies($row));
    }

    public function holoCap(UserCard $row): int
    {
        return min($row->getHoloQuantity(), $this->recyclableCopies($row));
    }

    /**
     * Best value of a card's duplicates: the copy kept is the cheapest one.
     */
    public function maxPoints(UserCard $row): int
    {
        $rarity = $row->getCard()->getRarity();
        $keptHolo = $row->getQuantity() === $row->getHoloQuantity() ? 1 : 0;

        return ($row->getQuantity() - $row->getHoloQuantity() - 1 + $keptHolo) * $rarity->recyclePoints()
            + ($row->getHoloQuantity() - $keptHolo) * $rarity->holoRecyclePoints();
    }

    public function getRecyclableTotal(): int
    {
        return array_sum(array_map($this->recyclableCopies(...), array_values($this->getRowMap())));
    }

    public function getMaxPointsTotal(): int
    {
        return array_sum(array_map($this->maxPoints(...), array_values($this->getRowMap())));
    }

    /**
     * Boosters offered in exchange: the retrievable ones — the same set the
     * daily claim and the streak rewards allow, enforced again by RecycleService.
     *
     * @return list<Booster>
     */
    public function getBoosters(): array
    {
        return $this->boosters ??= $this->boosterAvailability->filterRetrievable($this->boosterRepository->findPublished());
    }

    public function getSelectedBooster(): ?Booster
    {
        foreach ($this->getBoosters() as $booster) {
            if ((string) $booster->getId() === $this->boosterId) {
                return $booster;
            }
        }

        return null;
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

    public function getBoosterCount(): int
    {
        return RecycleService::boosterCountFor($this->getPoints());
    }

    /**
     * Points past the last full tranche: lost if confirmed now, or the
     * progress toward the next booster while still selecting.
     */
    public function getLostPoints(): int
    {
        return RecycleService::lostPointsFor($this->getPoints());
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
        if ($selected[self::KIND_NORMAL] + $selected[self::KIND_HOLO] >= $this->recyclableCopies($row)) {
            return;
        }

        $kindCap = self::KIND_NORMAL === $kind ? $this->normalCap($row) : $this->holoCap($row);

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
    public function filterUniverse(#[LiveArg] string $slug): void
    {
        $this->universe = '' === $slug ? null : $slug;
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

        $lost = RecycleService::lostPointsFor($operation->getPoints());
        $copies = $operation->getRecycledCardCount();
        $packs = $operation->getBoosterCount();

        $this->selection = [];
        $this->rows = null; // the memoized inventory is stale after the debit
        $this->success = \sprintf(
            '%d copie%s recyclée%s — %d pack%s « %s » ajouté%s à ton stock !%s',
            $copies,
            $copies > 1 ? 's' : '',
            $copies > 1 ? 's' : '',
            $packs,
            $packs > 1 ? 's' : '',
            $booster->getDisplayName(),
            $packs > 1 ? 's' : '',
            $lost > 0 ? \sprintf(' (%d point%s perdu%s)', $lost, $lost > 1 ? 's' : '', $lost > 1 ? 's' : '') : '',
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
