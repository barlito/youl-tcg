<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\TradeLineRequest;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Exception\Trade\TradeException;
use App\Repository\DiscordUserRepository;
use App\Repository\UserCardRepository;
use App\Service\Trade\TradeOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Offer composer. The selection lives in a NON-writable LiveProp mutated by
 * actions only: it travels checksummed, so a tampered payload is rejected
 * before it reaches the domain — which re-validates everything anyway.
 */
#[AsLiveComponent]
final class TradeComposer extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $counterpartId = '';

    /**
     * card id => ['normal' => int, 'holo' => int], copies taken from my side.
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

    /** Name filters: a full collection is far too long to browse flat. */
    #[LiveProp(writable: true)]
    public string $searchMine = '';

    #[LiveProp(writable: true)]
    public string $searchTheirs = '';

    public function __construct(
        private readonly TradeOfferService $tradeOfferService,
        private readonly DiscordUserRepository $discordUserRepository,
        private readonly UserCardRepository $userCardRepository,
    ) {
    }

    public function getCounterpart(): DiscordUser
    {
        $counterpart = $this->discordUserRepository->find($this->counterpartId);

        if (!$counterpart instanceof DiscordUser) {
            throw $this->createNotFoundException('Unknown trade counterpart.');
        }

        return $counterpart;
    }

    /**
     * @return array<string, array{card: Card, normal: int, holo: int}>
     */
    public function getMyCopies(): array
    {
        return $this->tradeOfferService->getEngageableCopies($this->getDiscordUser());
    }

    /**
     * @return array<string, array{card: Card, normal: int, holo: int}>
     */
    public function getTheirCopies(): array
    {
        return $this->tradeOfferService->getRequestableCopies($this->getCounterpart());
    }

    /**
     * Displayed subset: the search filter never hides an already-selected
     * card, otherwise its steppers would vanish mid-composition.
     *
     * @return array<string, array{card: Card, normal: int, holo: int}>
     */
    public function getVisibleMyCopies(): array
    {
        return $this->filter($this->getMyCopies(), $this->searchMine, $this->offered);
    }

    /**
     * Their side, masked: a card the visitor does not own is still requestable
     * (quantities and steppers stay live) but its entry carries NO Card at all,
     * so the template cannot leak a name it never receives.
     *
     * @return array<string, array{card: ?Card, normal: int, holo: int}>
     */
    public function getVisibleTheirCopies(): array
    {
        $copies = $this->getTheirCopies();
        $known = array_fill_keys($this->userCardRepository->findOwnedCardIds($this->getDiscordUser()), true);
        $revealed = $this->filter(array_intersect_key($copies, $known), $this->searchTheirs, $this->requested);

        $visible = [];

        foreach ($copies as $cardId => $entry) {
            if (!isset($known[$cardId])) {
                // masked cards ignore the search: filtering them on a name the
                // visitor is not allowed to read would give that name away
                $visible[$cardId] = ['card' => null, 'normal' => $entry['normal'], 'holo' => $entry['holo']];
            } elseif (isset($revealed[$cardId])) {
                $visible[$cardId] = $entry;
            }
        }

        return $visible;
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
        #[LiveArg] string $cardId,
        #[LiveArg] string $finish,
        #[LiveArg] int $delta,
    ): void {
        $this->error = null;

        $sideEnum = TradeOfferSideEnum::tryFrom($side);
        $finishKey = \in_array($finish, ['normal', 'holo'], true) ? $finish : null;

        if (!$sideEnum instanceof TradeOfferSideEnum || null === $finishKey) {
            return;
        }

        $available = TradeOfferSideEnum::OFFERED === $sideEnum ? $this->getMyCopies() : $this->getTheirCopies();

        if (!isset($available[$cardId])) {
            return;
        }

        $selection = $this->selectionOf($sideEnum);
        $line = $selection[$cardId] ?? ['normal' => 0, 'holo' => 0];
        $line[$finishKey] = max(0, min($available[$cardId][$finishKey], $line[$finishKey] + $delta));

        if (0 === $line['normal'] + $line['holo']) {
            unset($selection[$cardId]);
        } else {
            $selection[$cardId] = $line;
        }

        $this->writeSelection($sideEnum, $selection);
    }

    #[LiveAction]
    public function submit(): ?RedirectResponse
    {
        $this->error = null;

        try {
            $this->tradeOfferService->create(
                $this->getDiscordUser(),
                $this->getCounterpart(),
                $this->toLines(TradeOfferSideEnum::OFFERED, $this->getMyCopies()),
                $this->toLines(TradeOfferSideEnum::REQUESTED, $this->getTheirCopies()),
            );
        } catch (TradeException $exception) {
            $this->error = $exception->getUserMessage();

            return null;
        }

        return $this->redirectToRoute('trades');
    }

    /**
     * @param array<string, array{card: Card, normal: int, holo: int}> $available
     *
     * @return list<TradeLineRequest>
     */
    private function toLines(TradeOfferSideEnum $side, array $available): array
    {
        $lines = [];

        foreach ($this->selectionOf($side) as $cardId => $line) {
            if (isset($available[$cardId])) {
                $lines[] = new TradeLineRequest($available[$cardId]['card'], $line['normal'], $line['holo']);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, array{card: Card, normal: int, holo: int}> $copies
     * @param array<string, array{normal: int, holo: int}>             $selection
     *
     * @return array<string, array{card: Card, normal: int, holo: int}>
     */
    private function filter(array $copies, string $search, array $selection): array
    {
        $needle = mb_trim(mb_strtolower($search));

        if ('' === $needle) {
            return $copies;
        }

        return array_filter(
            $copies,
            static fn (array $entry, string $cardId): bool => isset($selection[$cardId])
                || str_contains(mb_strtolower($entry['card']->getName()), $needle),
            \ARRAY_FILTER_USE_BOTH,
        );
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
