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
 * @phpstan-type Entry array{token: string, card: ?Card, rarity: CardRarityEnum, extension: Extension, normal: int, holo: int}
 */
#[RequiresFeature(FeatureEnum::TRADES)]
#[AsLiveComponent]
final class TradeComposer extends AbstractController
{
    use DefaultActionTrait;

    private const array FINISHES = ['normal', 'holo'];

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

    /** Side shown on narrow screens (both are shown side by side on desktop). */
    #[LiveProp]
    public string $tab = 'offered';

    /**
     * @var array<string, array<string, Entry>> side => token => entry
     */
    private array $entries = [];

    private ?DiscordUser $counterpart = null;

    public function __construct(
        private readonly TradeOfferService $tradeOfferService,
        private readonly DiscordUserRepository $discordUserRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly CardRepository $cardRepository,
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
        return $this->group($this->visible(TradeOfferSideEnum::OFFERED));
    }

    /**
     * Their requestable copies, the ones I do not own masked (no Card at all
     * in the entry, only its rarity), filtered and grouped by universe.
     *
     * @return list<array{extension: Extension, entries: list<Entry>}>
     */
    public function getTheirGroups(): array
    {
        return $this->group($this->visible(TradeOfferSideEnum::REQUESTED));
    }

    public function getMyVisibleCount(): int
    {
        return \count($this->visible(TradeOfferSideEnum::OFFERED));
    }

    public function getTheirVisibleCount(): int
    {
        return \count($this->visible(TradeOfferSideEnum::REQUESTED));
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
     * @return array{copies: int, byRarity: list<array{rarity: CardRarityEnum, copies: int}>, items: list<array{entry: Entry, normal: int, holo: int}>}
     */
    public function summary(string $side): array
    {
        $sideEnum = TradeOfferSideEnum::tryFrom($side) ?? TradeOfferSideEnum::OFFERED;
        $entries = $this->entries($sideEnum);
        $copies = 0;
        $byRarity = [];
        $items = [];

        foreach ($this->selectionOf($sideEnum) as $token => $line) {
            $entry = $entries[$token] ?? null;
            if (null === $entry) {
                continue;
            }

            $count = $line['normal'] + $line['holo'];
            $copies += $count;
            $byRarity[$entry['rarity']->value] = ($byRarity[$entry['rarity']->value] ?? 0) + $count;
            $items[] = ['entry' => $entry, 'normal' => $line['normal'], 'holo' => $line['holo']];
        }

        $rarities = [];
        foreach (array_reverse(CardRarityEnum::ascending()) as $rarity) {
            if (isset($byRarity[$rarity->value])) {
                $rarities[] = ['rarity' => $rarity, 'copies' => $byRarity[$rarity->value]];
            }
        }

        return ['copies' => $copies, 'byRarity' => $rarities, 'items' => $items];
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
            ];
        }

        uasort($entries, static fn (array $a, array $b): int => CardRarityEnum::compareRarestFirst($a['rarity'], $b['rarity'])
            ?: (null === $a['card']) <=> (null === $b['card'])
            ?: ($a['card']?->getName() ?? $a['token']) <=> ($b['card']?->getName() ?? $b['token']));

        return $this->entries[$side->value] = $entries;
    }

    /**
     * Entries under the current filters. The name search never hides an
     * already-selected card, and masked cards ignore it (matching on a name
     * the visitor may not read would give that name away).
     *
     * @return list<Entry>
     */
    private function visible(TradeOfferSideEnum $side): array
    {
        $active = $this->getActiveExtension();
        $needle = mb_trim(mb_strtolower($this->search));
        $selection = $this->selectionOf($side);

        return array_values(array_filter(
            $this->entries($side),
            static fn (array $entry): bool => (!$active instanceof Extension || $entry['extension'] === $active)
                && (
                    '' === $needle
                    || !$entry['card'] instanceof Card
                    || isset($selection[$entry['token']])
                    || str_contains(mb_strtolower($entry['card']->getName()), $needle)
                ),
        ));
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<array{extension: Extension, entries: list<Entry>}>
     */
    private function group(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = (string) $entry['extension']->getId();
            $groups[$key] ??= ['extension' => $entry['extension'], 'entries' => []];
            $groups[$key]['entries'][] = $entry;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $a['extension']->getName() <=> $b['extension']->getName());

        return $groups;
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
