<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Attribute\RequiresFeature;
use App\Dto\TradeLineView;
use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Enum\FeatureEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Exception\Trade\TradeException;
use App\Repository\TradeOfferRepository;
use App\Repository\UserCardRepository;
use App\Service\Trade\TradeOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[RequiresFeature(FeatureEnum::TRADES)]
#[AsLiveComponent]
final class TradeInbox extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public const int HISTORY_PER_PAGE = 20;

    public const array TABS = ['received', 'sent', 'history'];

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $success = null;

    /** Received offer awaiting the second click of a two-step acceptance. */
    #[LiveProp]
    public ?string $confirming = null;

    /** 1-based page of the history, clamped at render time. */
    #[LiveProp]
    public int $historyPage = 1;

    /** Resolved offer of the reader whose history entry is opened (notification link, fresh acceptance). */
    #[LiveProp]
    public ?string $focus = null;

    /** Whether the received cards of the focused accepted offer are still to be revealed. */
    #[LiveProp]
    public bool $revealPending = false;

    /** Shown tab: received|sent|history (the three lists are rendered, only one is visible). */
    #[LiveProp]
    public string $tab = 'received';

    /**
     * Cards the reader owns (hence may see) with their copies. Memoized: one
     * query per render, never one per offer.
     *
     * @var array<string, int>|null
     */
    private ?array $owned = null;

    /**
     * Cards the reader owns or once held: the visibility set of the resolved
     * offers that moved nothing.
     *
     * @var array<string, true>|null
     */
    private ?array $everOwned = null;

    private ?int $historyCount = null;

    private ?TradeOffer $focusedOffer = null;

    public function __construct(
        private readonly TradeOfferRepository $tradeOfferRepository,
        private readonly TradeOfferService $tradeOfferService,
        private readonly UserCardRepository $userCardRepository,
    ) {
    }

    /**
     * `?offre=` of the notification link: an unknown, pending or foreign offer is ignored silently.
     */
    public function mount(?string $focus = null): void
    {
        $offer = null !== $focus ? $this->findOwnResolvedOffer($focus) : null;

        if ($offer instanceof TradeOffer) {
            $this->focusOn($offer);
        }
    }

    public function getFocusedOffer(): ?TradeOffer
    {
        if (null === $this->focus) {
            return null;
        }

        return $this->focusedOffer ??= $this->findOwnResolvedOffer($this->focus);
    }

    /**
     * Received cards of the focused accepted offer, rarest (then holo, then new) last.
     *
     * @return list<TradeLineView>
     */
    public function getRevealLines(): array
    {
        $offer = $this->getFocusedOffer();

        if (!$this->revealPending || !$offer instanceof TradeOffer || TradeOfferStatusEnum::ACCEPTED !== $offer->getStatus()) {
            return [];
        }

        $lines = $this->historyReceivedView($offer);
        usort($lines, static fn (TradeLineView $a, TradeLineView $b): int => [$a->rarity->rank(), $a->holoQuantity > 0, $a->new]
            <=> [$b->rarity->rank(), $b->holoQuantity > 0, $b->new]);

        return $lines;
    }

    /**
     * @return list<TradeLineView>
     */
    public function offeredView(TradeOffer $offer): array
    {
        return $this->view($offer->getOfferedLines());
    }

    /**
     * @return list<TradeLineView>
     */
    public function requestedView(TradeOffer $offer): array
    {
        return $this->view($offer->getRequestedLines());
    }

    /**
     * @return list<TradeOffer>
     */
    public function getReceived(): array
    {
        $offers = $this->tradeOfferRepository->findPendingForReceiver($this->getDiscordUser());

        // dead offers are swept at display time; the ones flipped here drop out
        return $this->tradeOfferService->invalidateObviouslyInfeasible($offers)
            ? array_values(array_filter($offers, static fn (TradeOffer $offer): bool => $offer->isPending()))
            : $offers;
    }

    /**
     * @return list<TradeOffer>
     */
    public function getSent(): array
    {
        return $this->tradeOfferRepository->findPendingForProposer($this->getDiscordUser());
    }

    /**
     * @return list<TradeOffer>
     */
    public function getHistory(): array
    {
        return $this->tradeOfferRepository->findHistoryPageFor($this->getDiscordUser(), $this->getCurrentHistoryPage(), self::HISTORY_PER_PAGE);
    }

    public function getHistoryCount(): int
    {
        return $this->historyCount ??= $this->tradeOfferRepository->countHistoryFor($this->getDiscordUser());
    }

    public function getHistoryPageCount(): int
    {
        return max(1, (int) ceil($this->getHistoryCount() / self::HISTORY_PER_PAGE));
    }

    public function getCurrentTab(): string
    {
        return \in_array($this->tab, self::TABS, true) ? $this->tab : self::TABS[0];
    }

    public function getCurrentHistoryPage(): int
    {
        return min(max(1, $this->historyPage), $this->getHistoryPageCount());
    }

    /**
     * What the reader gave (or would have given) in a resolved offer.
     *
     * @return list<TradeLineView>
     */
    public function historyGivenView(TradeOffer $offer): array
    {
        return $this->historyView($offer, given: true);
    }

    /**
     * What the reader received (or would have received) in a resolved offer.
     *
     * @return list<TradeLineView>
     */
    public function historyReceivedView(TradeOffer $offer): array
    {
        return $this->historyView($offer, given: false);
    }

    #[LiveAction]
    public function showTab(#[LiveArg] string $tab): void
    {
        $this->tab = \in_array($tab, self::TABS, true) ? $tab : self::TABS[0];
    }

    #[LiveAction]
    public function goToHistoryPage(#[LiveArg] int $page): void
    {
        $this->historyPage = max(1, $page);
    }

    #[LiveAction]
    public function askAccept(#[LiveArg] string $offerId): void
    {
        $this->error = null;
        $this->success = null;
        $this->confirming = $offerId;
    }

    #[LiveAction]
    public function abortAccept(): void
    {
        $this->confirming = null;
    }

    #[LiveAction]
    public function accept(#[LiveArg] string $offerId): void
    {
        $this->run($offerId, function (TradeOffer $offer, DiscordUser $user): string {
            $this->tradeOfferService->accept($offer, $user);
            $this->focusOn($offer);

            return 'Échange conclu ! Les cartes ont changé de collection.';
        });
    }

    #[LiveAction]
    public function closeReveal(): void
    {
        $this->revealPending = false;
    }

    #[LiveAction]
    public function refuse(#[LiveArg] string $offerId): void
    {
        $this->run($offerId, function (TradeOffer $offer, DiscordUser $user): string {
            $this->tradeOfferService->refuse($offer, $user);

            return 'Offre refusée.';
        });
    }

    #[LiveAction]
    public function cancel(#[LiveArg] string $offerId): void
    {
        $this->run($offerId, function (TradeOffer $offer, DiscordUser $user): string {
            $this->tradeOfferService->cancel($offer, $user);

            return 'Offre annulée, tes cartes sont de nouveau disponibles.';
        });
    }

    /**
     * Uniform rule of the whole trade screen, whatever the side: a card the
     * reader does not own is never handed to the template, only its rarity is.
     *
     * @param list<TradeOfferLine> $lines
     *
     * @return list<TradeLineView>
     */
    private function view(array $lines): array
    {
        $owned = $this->ownedQuantities();

        return array_map(
            static fn (TradeOfferLine $line): TradeLineView => new TradeLineView(
                isset($owned[(string) $line->getCard()->getId()]) ? $line->getCard() : null,
                $line->getCard()->getRarity(),
                $line->getTotalQuantity(),
                $line->getHoloQuantity(),
                owned: $owned[(string) $line->getCard()->getId()] ?? 0,
            ),
            $lines,
        );
    }

    /**
     * Masking of the history: an ACCEPTED offer moved every card through the
     * reader's hands, so all of it shows. Any other outcome moved nothing, and
     * propose-then-cancel must not become a way to peek at cards: only the
     * cards the reader owns or once held show there.
     *
     * @return list<TradeLineView>
     */
    private function historyView(TradeOffer $offer, bool $given): array
    {
        $reader = $this->getDiscordUser()->getDiscordId();
        $lines = array_values(array_filter(
            $offer->getLines()->toArray(),
            static fn (TradeOfferLine $line): bool => $given === ($offer->getGiverOf($line)->getDiscordId() === $reader),
        ));
        $accepted = TradeOfferStatusEnum::ACCEPTED === $offer->getStatus();
        $everOwned = $accepted ? null : $this->everOwnedCardIds();

        return array_map(
            static fn (TradeOfferLine $line): TradeLineView => new TradeLineView(
                null === $everOwned || isset($everOwned[(string) $line->getCard()->getId()]) ? $line->getCard() : null,
                $line->getCard()->getRarity(),
                $line->getTotalQuantity(),
                $line->getHoloQuantity(),
                $accepted && !$given && true === $line->isNewForTaker(),
            ),
            $lines,
        );
    }

    private function focusOn(TradeOffer $offer): void
    {
        $this->focus = (string) $offer->getId();
        $this->focusedOffer = $offer;
        $this->revealPending = TradeOfferStatusEnum::ACCEPTED === $offer->getStatus();
        $this->tab = 'history';
        $this->historyPage = intdiv($this->tradeOfferRepository->countHistoryBefore($this->getDiscordUser(), $offer), self::HISTORY_PER_PAGE) + 1;
    }

    private function findOwnResolvedOffer(string $offerId): ?TradeOffer
    {
        $offer = Uuid::isValid($offerId) ? $this->tradeOfferRepository->find($offerId) : null;

        return $offer instanceof TradeOffer && $offer->involves($this->getDiscordUser()) && !$offer->isPending() ? $offer : null;
    }

    /**
     * @return array<string, true>
     */
    private function everOwnedCardIds(): array
    {
        return $this->everOwned ??= $this->userCardRepository->findEverOwnedCardIds($this->getDiscordUser());
    }

    /**
     * @return array<string, int>
     */
    private function ownedQuantities(): array
    {
        return $this->owned ??= $this->userCardRepository->findOwnedQuantities($this->getDiscordUser());
    }

    /**
     * @param callable(TradeOffer, DiscordUser): string $action
     */
    private function run(string $offerId, callable $action): void
    {
        $this->error = null;
        $this->success = null;
        $this->confirming = null;

        $user = $this->getDiscordUser();
        // client-provided: a malformed id is "not found", never a conversion error
        $offer = Uuid::isValid($offerId) ? $this->tradeOfferRepository->find($offerId) : null;

        // membership is re-checked by the service, but an offer of someone
        // else must not even be readable through this component
        if (!$offer instanceof TradeOffer || !$offer->involves($user)) {
            $this->error = 'Cette offre n\'existe plus.';

            return;
        }

        try {
            $this->success = $action($offer, $user);
        } catch (TradeException $exception) {
            $this->error = $exception->getUserMessage();
        }

        // local echo for the header badge, even without a realtime connection
        $this->dispatchBrowserEvent('trades:changed');
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
