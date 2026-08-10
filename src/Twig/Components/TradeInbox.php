<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Exception\Trade\TradeException;
use App\Repository\TradeOfferRepository;
use App\Service\Trade\TradeOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class TradeInbox extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $success = null;

    public function __construct(
        private readonly TradeOfferRepository $tradeOfferRepository,
        private readonly TradeOfferService $tradeOfferService,
    ) {
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
        return $this->tradeOfferRepository->findHistoryFor($this->getDiscordUser());
    }

    #[LiveAction]
    public function accept(#[LiveArg] string $offerId): void
    {
        $this->run($offerId, function (TradeOffer $offer, DiscordUser $user): string {
            $this->tradeOfferService->accept($offer, $user);

            return 'Échange conclu ! Les cartes ont changé de collection.';
        });
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
     * @param callable(TradeOffer, DiscordUser): string $action
     */
    private function run(string $offerId, callable $action): void
    {
        $this->error = null;
        $this->success = null;

        $user = $this->getDiscordUser();
        $offer = $this->tradeOfferRepository->find($offerId);

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
