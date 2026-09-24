<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DiscordUser;
use App\Enum\FeatureEnum;
use App\Repository\TradeOfferRepository;
use App\Service\Feature\FeatureFlags;
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
        private FeatureFlags $featureFlags,
    ) {
    }

    #[AsTwigFunction(name: 'pending_trade_offers')]
    public function pendingTradeOffers(): int
    {
        if (!$this->featureFlags->isEnabled(FeatureEnum::TRADES)) {
            return 0;
        }

        $user = $this->security->getUser();

        // the header renders on anonymous error pages too
        if (!$user instanceof DiscordUser) {
            return 0;
        }

        return $this->tradeOfferRepository->countPendingForReceiver($user);
    }
}
