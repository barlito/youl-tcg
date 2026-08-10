<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Repository\DiscordUserRepository;
use App\Service\Trade\TradeOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Asynchronous P2P trades: the inbox of offers and the composer.
 */
class TradeController extends AbstractController
{
    #[Route('/echanges', name: 'trades')]
    public function inbox(): Response
    {
        return $this->render('pages/trades.html.twig');
    }

    /**
     * Player picker: trading starts by choosing who you trade with.
     */
    #[Route('/echanges/nouveau', name: 'trades_new')]
    public function chooseCounterpart(
        #[CurrentUser] DiscordUser $user,
        DiscordUserRepository $discordUserRepository,
    ): Response {
        return $this->render('pages/trade_new.html.twig', [
            'players' => $discordUserRepository->findOthersOrderedByUsername($user),
        ]);
    }

    #[Route('/echanges/nouveau/{discordId}', name: 'trades_compose')]
    public function compose(
        #[CurrentUser] DiscordUser $user,
        DiscordUserRepository $discordUserRepository,
        TradeOfferService $tradeOfferService,
        string $discordId,
    ): Response {
        $counterpart = $discordUserRepository->find($discordId);

        if (!$counterpart instanceof DiscordUser || $counterpart->getDiscordId() === $user->getDiscordId()) {
            throw $this->createNotFoundException('Unknown trade counterpart.');
        }

        return $this->render('pages/trade_compose.html.twig', [
            'counterpart' => $counterpart,
            'hasSomethingToOffer' => [] !== $tradeOfferService->getEngageableCopies($user),
            'counterpartHasCards' => [] !== $tradeOfferService->getRequestableCopies($counterpart),
        ]);
    }
}
