<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DiscordUser;
use App\Repository\TradeOfferRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * Feeds the header badge with the number of offers waiting for the current
 * player's answer.
 */
final readonly class TradeExtension
{
    public function __construct(
        private TradeOfferRepository $tradeOfferRepository,
        private Security $security,
    ) {
    }

    #[AsTwigFunction(name: 'pending_trade_offers')]
    public function pendingTradeOffers(): int
    {
        $user = $this->security->getUser();

        // the header renders on anonymous error pages too
        if (!$user instanceof DiscordUser) {
            return 0;
        }

        return $this->tradeOfferRepository->countPendingForReceiver($user);
    }
}
