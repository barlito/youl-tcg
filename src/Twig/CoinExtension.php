<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DiscordUser;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\WalletBalances;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

final readonly class CoinExtension
{
    public function __construct(
        private WalletBalances $balances,
        private Security $security,
    ) {
    }

    /** Null when the coin is unavailable or nobody is logged in. */
    #[AsTwigFunction(name: 'wallet_balance')]
    public function walletBalance(): ?CoinAmount
    {
        $user = $this->security->getUser();

        return $user instanceof DiscordUser ? $this->balances->get($user->getDiscordId()) : null;
    }
}
