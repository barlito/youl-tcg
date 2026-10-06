<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Attribute\RequiresFeature;
use App\Dto\TradeLineRequest;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\FeatureEnum;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Exception\Trade\TradeException;
use App\Repository\CardRepository;
use App\Repository\DiscordUserRepository;
use App\Repository\MarketListingRepository;
use App\Repository\UserCardRepository;
use App\Service\Trade\TradeOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * Offer composer. Cards are addressed by an opaque per-viewer token (HMAC of
 * the card id), never by their uuid: a masked card's uuid in the DOM would
 * unmask it wherever CardComponent renders `id="{{ card.id }}"`. The selection
 * lives in NON-writable LiveProps mutated by actions only (checksummed), and
 * the domain re-validates everything anyway.
 *
 * @phpstan-type Entry array{token: string, card: ?Card, rarity: CardRarityEnum, extension: Extension, normal: int, holo: int, listed: int, mine: int, theirs: int}
 */
#[RequiresFeature(FeatureEnum::TRADES)]
#[AsLiveComponent]
final class TradeComposer extends AbstractController
{
    use DefaultActionTrait;

    private const array FINISHES = ['normal', 'holo'];

    private const string FILTER_ALL = 'all';

    private const string FILTER_LACKS = 'lacks';

    private const string FILTER_DOUBLES = 'doubles';

    #[LiveProp]
    public string $counterpartId = '';

    /**
     * token => copies taken from my side.
     *
     * @var array<string, array{normal: int, holo: int}>
     */
    #[LiveProp]
    public array $offered = [];

    /**
     * @var array<string, array{normal: int, holo: int}>
     */
    #[LiveProp]
    public array $requested = [];

    #[LiveProp]
    public ?string $error = null;

    /** Name filter, both sides; masked cards ignore it. */
    #[LiveProp(writable: true)]
    public string $search = '';

    /** Universe filter (extension slug), both sides, mirrored in the url. */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'univers'))]
    public ?string $universe = null;

    /** Chip filter of the left column: all|lacks|doubles (what he lacks / my duplicates). */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'proposes'))]
    public string $offeredFilter = self::FILTER_ALL;

    /** Chip filter of the right column: all|lacks|doubles (what I lack / his duplicates). */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'demandes'))]
    public string $requestedFilter = self::FILTER_ALL;

    /** Side shown on narrow screens (both are shown side by side on desktop). */
    #[LiveProp]
    public string $tab = 'offered';

    /**
     * @var array<string, array<string, Entry>> side => token => entry
     */
    private array $entries = [];

    private ?DiscordUser $counterpart = null;

    /** @var array<string, int>|null card id => total copies I own (published) */
    private ?array $myOwned = null;

    public function __construct(
        private readonly TradeOfferService $tradeOfferService,
        private readonly DiscordUserRepository $discordUserRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly CardRepository $cardRepository,
        private readonly MarketListingRepository $marketListingRepository,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    public function getCounterpart(): DiscordUser
    {
        if ($this->counterpart instanceof DiscordUser) {
            return $this->counterpart;
        }

        $counterpart = $this->discordUserRepository->find($this->counterpartId);

        if (!$counterpart instanceof DiscordUser) {
            throw $this->createNotFoundException('Unknown trade counterpart.');
        }

        return $this->counterpart = $counterpart;
    }

    /**
     * My engageable copies, filtered and grouped by universe.
     *
     * @return list<array{extension: Extension, entries: list<Entry>}>
     */
    public function getMyGroups(): array
    {
        return $this->group($this->visible(TradeOfferSideEnum::OFFERED), TradeOfferSideEnum::OFFERED);
    }

    /**
     * Their requestable copies, the ones I do not own masked (no Card at all
     * in the entry, only its rarity), filtered and grouped by universe.
     *
     * @return list<array{extension: Extension, entries: list<Entry>}>
     */
    public function getTheirGroups(): array
    {
        return $this->group($this->visible(TradeOfferSideEnum::REQUESTED), TradeOfferSideEnum::REQUESTED);
    }

    public function getMyVisibleCount(): int
    {
        return \count($this->visible(TradeOfferSideEnum::OFFERED));
    }

    public function getTheirVisibleCount(): int
    {
        return \count($this->visible(TradeOfferSideEnum::REQUESTED));
    }

    /**
     * Chips of a column with their counts, under the universe and name filters but ignoring the chip itself.
     *
     * @return list<array{value: string, label: string, count: int, active: bool}>
     */
    public function chips(string $side): array
    {
        $sideEnum = TradeOfferSideEnum::tryFrom($side) ?? TradeOfferSideEnum::OFFERED;
        $base = $this->visible($sideEnum, applyChip: false);
        $labels = TradeOfferSideEnum::OFFERED === $sideEnum
            ? [self::FILTER_ALL => 'Tout', self::FILTER_LACKS => 'Lui manque', self::FILTER_DOUBLES => 'Mes doublons']
            : [self::FILTER_ALL => 'Tout', self::FILTER_LACKS => 'Me manque', self::FILTER_DOUBLES => 'Ses doublons'];
        $current = $this->filterOf($sideEnum);

        $chips = [];
        foreach ($labels as $value => $label) {
            $chips[] = [
                'value' => $value,
                'label' => $label,
                'count' => \count(array_filter($base, fn (array $entry): bool => $this->matchesChip($sideEnum, $value, $entry))),
                'active' => $value === $current,
            ];
        }

        return $chips;
    }

    public function hasMaskedCards(): bool
    {
        return array_any($this->entries(TradeOfferSideEnum::REQUESTED), fn (array $entry): bool => !$entry['card'] instanceof Card);
    }

    /**
     * Tiles of the shared universe strip: cards on each side per universe.
     *
     * @return list<array{extension: Extension, stat: string, caption: string, bar: null, coverImage: string|null}>
     */
    public function getStrip(): array
    {
        $counts = $this->countByUniverse();
        $coverImages = [] === $counts ? [] : $this->cardRepository->findCoverImageNamesByExtension();
        $theirName = $this->getCounterpart()->getUsername();

        return array_map(static fn (array $universe): array => [
            'extension' => $universe['extension'],
            'stat' => \sprintf('%d ⇄ %d', $universe['mine'], $universe['theirs']),
            'caption' => \sprintf('à toi ⇄ à %s', $theirName),
            'bar' => null,
            'coverImage' => $coverImages[(string) $universe['extension']->getId()] ?? null,
        ], $counts);
    }

    public function getActiveExtension(): ?Extension
    {
        foreach ($this->countByUniverse() as $universe) {
            if ($universe['extension']->getSlug() === $this->universe) {
                return $universe['extension'];
            }
        }

        return null;
    }

    public function getMyTotal(): int
    {
        return \count($this->entries(TradeOfferSideEnum::OFFERED));
    }

    public function getTheirTotal(): int
    {
        return \count($this->entries(TradeOfferSideEnum::REQUESTED));
    }

    /**
     * @return array{normal: int, holo: int}
     */
    public function selectionFor(string $side, string $token): array
    {
        $sideEnum = TradeOfferSideEnum::tryFrom($side);

        return null === $sideEnum ? ['normal' => 0, 'holo' => 0] : $this->selectionOf($sideEnum)[$token] ?? ['normal' => 0, 'holo' => 0];
    }

    /**
     * Readable digest of one side of the offer for the sticky recap.
     *
     * @return array{copies: int, byRarity: list<array{rarity: CardRarityEnum, copies: int}>, items: list<array{entry: Entry, normal: int, holo: int}>, lastCopies: int}
     */
    public function summary(string $side): array
    {
        $sideEnum = TradeOfferSideEnum::tryFrom($side) ?? TradeOfferSideEnum::OFFERED;
        $entries = $this->entries($sideEnum);
        $copies = 0;
        $byRarity = [];
        $items = [];
        $lastCopies = 0;

        foreach ($this->selectionOf($sideEnum) as $token => $line) {
            $entry = $entries[$token] ?? null;
            if (null === $entry) {
                continue;
            }

            $count = $line['normal'] + $line['holo'];
            $copies += $count;
            $byRarity[$entry['rarity']->value] = ($byRarity[$entry['rarity']->value] ?? 0) + $count;
            $items[] = ['entry' => $entry, 'normal' => $line['normal'], 'holo' => $line['holo']];
            if (TradeOfferSideEnum::OFFERED === $sideEnum && 1 === $entry['mine']) {
                ++$lastCopies;
            }
        }

        $rarities = [];
        foreach (array_reverse(CardRarityEnum::ascending()) as $rarity) {
            if (isset($byRarity[$rarity->value])) {
                $rarities[] = ['rarity' => $rarity, 'copies' => $byRarity[$rarity->value]];
            }
        }

        return ['copies' => $copies, 'byRarity' => $rarities, 'items' => $items, 'lastCopies' => $lastCopies];
    }

    public function getOfferedCount(): int
    {
        return $this->countOf(TradeOfferSideEnum::OFFERED);
    }

    public function getRequestedCount(): int
    {
        return $this->countOf(TradeOfferSideEnum::REQUESTED);
    }

    public function isComplete(): bool
    {
        return $this->getOfferedCount() > 0 && $this->getRequestedCount() > 0;
    }

    /**
     * Steppers: every bound is enforced here, never trusted from the client.
     */
    #[LiveAction]
    public function adjust(
        #[LiveArg] string $side,
        #[LiveArg] string $token,
        #[LiveArg] string $finish,
        #[LiveArg] int $delta,
    ): void {
        $this->error = null;

        $sideEnum = TradeOfferSideEnum::tryFrom($side);

        if (!$sideEnum instanceof TradeOfferSideEnum || !\in_array($finish, self::FINISHES, true)) {
            return;
        }

        $entry = $this->entries($sideEnum)[$token] ?? null;

        if (null === $entry) {
            return;
        }

        $selection = $this->selectionOf($sideEnum);
        $line = $selection[$token] ?? ['normal' => 0, 'holo' => 0];
        $line[$finish] = max(0, min($entry[$finish], $line[$finish] + $delta));

        if (0 === $line['normal'] + $line['holo']) {
            unset($selection[$token]);
        } else {
            $selection[$token] = $line;
        }

        $this->writeSelection($sideEnum, $selection);
    }

    #[LiveAction]
    public function filterUniverse(#[LiveArg] string $slug): void
    {
        $this->universe = '' === $slug ? null : $slug;
    }

    #[LiveAction]
    public function filterSide(#[LiveArg] string $side, #[LiveArg] string $filter): void
    {
        $filter = \in_array($filter, [self::FILTER_LACKS, self::FILTER_DOUBLES], true) ? $filter : self::FILTER_ALL;

        if (TradeOfferSideEnum::REQUESTED->value === $side) {
            $this->requestedFilter = $filter;

            return;
        }

        $this->offeredFilter = $filter;
    }

    #[LiveAction]
    public function showSide(#[LiveArg] string $side): void
    {
        $this->tab = TradeOfferSideEnum::REQUESTED->value === $side ? $side : TradeOfferSideEnum::OFFERED->value;
    }

    #[LiveAction]
    public function submit(): ?RedirectResponse
    {
        $this->error = null;

        try {
            $this->tradeOfferService->create(
                $this->getDiscordUser(),
                $this->getCounterpart(),
                $this->toLines(TradeOfferSideEnum::OFFERED, $this->tradeOfferService->getEngageableCopies($this->getDiscordUser())),
                $this->toLines(TradeOfferSideEnum::REQUESTED, $this->tradeOfferService->getRequestableCopies($this->getCounterpart())),
            );
        } catch (TradeException $exception) {
            $this->error = $exception->getUserMessage();

            return null;
        }

        return $this->redirectToRoute('trades');
    }

    /**
     * @param array<string, array{card: Card, normal: int, holo: int}> $available card id => copies
     *
     * @return list<TradeLineRequest>
     */
    private function toLines(TradeOfferSideEnum $side, array $available): array
    {
        $byToken = [];
        foreach ($available as $cardId => $copies) {
            $byToken[$this->tokenFor($cardId)] = $copies['card'];
        }

        $lines = [];
        foreach ($this->selectionOf($side) as $token => $line) {
            if (isset($byToken[$token])) {
                $lines[] = new TradeLineRequest($byToken[$token], $line['normal'], $line['holo']);
            }
        }

        return $lines;
    }

    /**
     * Every entry of a side, sorted rarest first; within a rarity the visible
     * cards by name then the masked ones by token (sorting them by name would
     * leak where their name falls).
     *
     * @return array<string, Entry>
     */
    private function entries(TradeOfferSideEnum $side): array
    {
        if (isset($this->entries[$side->value])) {
            return $this->entries[$side->value];
        }

        $isMine = TradeOfferSideEnum::OFFERED === $side;
        $copies = $isMine
            ? $this->tradeOfferService->getEngageableCopies($this->getDiscordUser())
            : $this->tradeOfferService->getRequestableCopies($this->getCounterpart());
        $known = $isMine ? null : array_fill_keys($this->userCardRepository->findOwnedCardIds($this->getDiscordUser()), true);
        $listed = $isMine ? $this->listedCopies() : [];
        $mine = $this->myOwned();
        $theirs = $isMine ? $this->theirOwned() : [];

        $entries = [];
        foreach ($copies as $cardId => $copy) {
            $extension = $copy['card']->getExtension();
            if (!$extension instanceof Extension) {
                continue; // NOT NULL column: never happens on a persisted card
            }

            $token = $this->tokenFor((string) $cardId);
            $entries[$token] = [
                'token' => $token,
                'card' => null === $known || isset($known[$cardId]) ? $copy['card'] : null,
                'rarity' => $copy['card']->getRarity(),
                'extension' => $extension,
                'normal' => $copy['normal'],
                'holo' => $copy['holo'],
                'listed' => $listed[$cardId]['count'] ?? 0,
                'mine' => $mine[$cardId] ?? 0,
                'theirs' => $isMine ? $theirs[$cardId] ?? 0 : $copy['normal'] + $copy['holo'],
            ];
        }

        // copies on sale stay visible, locked: the player sees where they went
        foreach ($listed as $cardId => ['card' => $card, 'count' => $count]) {
            $token = $this->tokenFor($cardId);
            $extension = $card->getExtension();

            if (!isset($entries[$token]) && $extension instanceof Extension) {
                $entries[$token] = ['token' => $token, 'card' => $card, 'rarity' => $card->getRarity(), 'extension' => $extension, 'normal' => 0, 'holo' => 0, 'listed' => $count, 'mine' => $mine[$cardId] ?? 0, 'theirs' => $theirs[$cardId] ?? 0];
            }
        }

        uasort($entries, static fn (array $a, array $b): int => CardRarityEnum::compareRarestFirst($a['rarity'], $b['rarity'])
            ?: (null === $a['card']) <=> (null === $b['card'])
            ?: ($a['card']?->getName() ?? $a['token']) <=> ($b['card']?->getName() ?? $b['token']));

        return $this->entries[$side->value] = $entries;
    }

    /**
     * @return array<string, array{card: Card, count: int}> card id => copies in the player's engaged listings
     */
    private function listedCopies(): array
    {
        $listed = [];
        foreach ($this->marketListingRepository->findEngagedBySeller($this->getDiscordUser()) as $listing) {
            $card = $listing->getCard();
            $cardId = (string) $card->getId();
            $listed[$cardId] = ['card' => $card, 'count' => ($listed[$cardId]['count'] ?? 0) + 1];
        }

        return $listed;
    }

    /**
     * Entries under the current filters. The name search never hides an
     * already-selected card, and masked cards ignore it (matching on a name
     * the visitor may not read would give that name away).
     *
     * @return list<Entry>
     */
    private function visible(TradeOfferSideEnum $side, bool $applyChip = true): array
    {
        $active = $this->getActiveExtension();
        $needle = mb_trim(mb_strtolower($this->search));
        $selection = $this->selectionOf($side);
        $chip = $applyChip ? $this->filterOf($side) : self::FILTER_ALL;

        return array_values(array_filter(
            $this->entries($side),
            fn (array $entry): bool => (!$active instanceof Extension || $entry['extension'] === $active)
                && (
                    '' === $needle
                    || !$entry['card'] instanceof Card
                    || isset($selection[$entry['token']])
                    || str_contains(mb_strtolower($entry['card']->getName()), $needle)
                )
                && (isset($selection[$entry['token']]) || $this->matchesChip($side, $chip, $entry)),
        ));
    }

    private function filterOf(TradeOfferSideEnum $side): string
    {
        $filter = TradeOfferSideEnum::OFFERED === $side ? $this->offeredFilter : $this->requestedFilter;

        return \in_array($filter, [self::FILTER_LACKS, self::FILTER_DOUBLES], true) ? $filter : self::FILTER_ALL;
    }

    /**
     * @param Entry $entry
     */
    private function matchesChip(TradeOfferSideEnum $side, string $chip, array $entry): bool
    {
        $offered = TradeOfferSideEnum::OFFERED === $side;

        return match ($chip) {
            self::FILTER_LACKS => $this->lacksOnOtherSide($side, $entry),
            self::FILTER_DOUBLES => ($offered ? $entry['mine'] : $entry['theirs']) >= 2,
            default => true,
        };
    }

    /**
     * Left: he owns none of it. Right: I do not own it (exactly the masked tiles).
     *
     * @param Entry $entry
     */
    private function lacksOnOtherSide(TradeOfferSideEnum $side, array $entry): bool
    {
        return TradeOfferSideEnum::OFFERED === $side ? 0 === $entry['theirs'] : !$entry['card'] instanceof Card;
    }

    /**
     * @return array<string, int> card id => total copies I own, holo included
     */
    private function myOwned(): array
    {
        if (null !== $this->myOwned) {
            return $this->myOwned;
        }

        $owned = [];
        foreach ($this->userCardRepository->findOwnedWithCards($this->getDiscordUser(), publishedOnly: true) as $row) {
            $owned[(string) $row->getCard()->getId()] = $row->getQuantity();
        }

        return $this->myOwned = $owned;
    }

    /**
     * @return array<string, int> card id => total copies he owns
     */
    private function theirOwned(): array
    {
        return array_map(
            static fn (array $copy): int => $copy['normal'] + $copy['holo'],
            $this->tradeOfferService->getRequestableCopies($this->getCounterpart()),
        );
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<array{extension: Extension, entries: list<Entry>}>
     */
    private function group(array $entries, TradeOfferSideEnum $side): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = (string) $entry['extension']->getId();
            $groups[$key] ??= ['extension' => $entry['extension'], 'entries' => []];
            $groups[$key]['entries'][] = $entry;
        }

        foreach ($groups as &$group) {
            // stable partition: cards missing on the other side first, current order kept inside each bucket
            $useful = array_values(array_filter($group['entries'], fn (array $entry): bool => $this->isUseful($side, $entry)));
            $rest = array_values(array_filter($group['entries'], fn (array $entry): bool => !$this->isUseful($side, $entry)));
            $group['entries'] = [...$useful, ...$rest];
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $a['extension']->getName() <=> $b['extension']->getName());

        return $groups;
    }

    /**
     * @param Entry $entry
     */
    private function isUseful(TradeOfferSideEnum $side, array $entry): bool
    {
        return $this->lacksOnOtherSide($side, $entry) && (TradeOfferSideEnum::REQUESTED === $side || $entry['normal'] + $entry['holo'] > 0);
    }

    /**
     * @return list<array{extension: Extension, mine: int, theirs: int}>
     */
    private function countByUniverse(): array
    {
        $universes = [];
        foreach ([TradeOfferSideEnum::OFFERED->value => 'mine', TradeOfferSideEnum::REQUESTED->value => 'theirs'] as $side => $key) {
            foreach ($this->entries(TradeOfferSideEnum::from($side)) as $entry) {
                $id = (string) $entry['extension']->getId();
                $universes[$id] ??= ['extension' => $entry['extension'], 'mine' => 0, 'theirs' => 0];
                ++$universes[$id][$key];
            }
        }

        $universes = array_values($universes);
        usort($universes, static fn (array $a, array $b): int => $a['extension']->getName() <=> $b['extension']->getName());

        return $universes;
    }

    /**
     * Opaque, stable for this viewer/counterpart pair, useless elsewhere.
     */
    private function tokenFor(string $cardId): string
    {
        return substr(hash_hmac('sha256', \sprintf('trade|%s|%s|%s', $this->getDiscordUser()->getDiscordId(), $this->counterpartId, $cardId), $this->secret), 0, 24);
    }

    private function countOf(TradeOfferSideEnum $side): int
    {
        $total = 0;

        foreach ($this->selectionOf($side) as $line) {
            $total += $line['normal'] + $line['holo'];
        }

        return $total;
    }

    /**
     * @return array<string, array{normal: int, holo: int}>
     */
    private function selectionOf(TradeOfferSideEnum $side): array
    {
        return TradeOfferSideEnum::OFFERED === $side ? $this->offered : $this->requested;
    }

    /**
     * @param array<string, array{normal: int, holo: int}> $selection
     */
    private function writeSelection(TradeOfferSideEnum $side, array $selection): void
    {
        if (TradeOfferSideEnum::OFFERED === $side) {
            $this->offered = $selection;

            return;
        }

        $this->requested = $selection;
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
