<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Realtime\UserEventEnum;
use App\Repository\DiscordUserRepository;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\WalletBalances;
use App\Service\Coin\WebhookVerifier;
use App\Service\Realtime\UserEventPublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class YoulCoinWebhookController extends AbstractController
{
    #[Route('/webhooks/youl-coin', name: 'webhook_youl_coin', methods: ['POST'])]
    public function __invoke(
        Request $request,
        WebhookVerifier $verifier,
        DiscordUserRepository $users,
        WalletBalances $balances,
        UserEventPublisher $publisher,
    ): Response {
        $body = $request->getContent();

        if (!$verifier->isValid($body, $request->headers->get('X-Youl-Timestamp'), $request->headers->get('X-Youl-Signature'))) {
            return new Response(status: Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        if (!\is_array($payload) || !\is_array($payload['wallets'] ?? null)) {
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        foreach ($payload['wallets'] as $wallet) {
            if (!\is_array($wallet) || !\is_string($wallet['discordId'] ?? null) || !\is_string($wallet['balance'] ?? null)) {
                continue;
            }

            $user = $users->find($wallet['discordId']);
            if (null === $user) {
                continue;
            }

            try {
                $balance = CoinAmount::fromMinor($wallet['balance']);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $balances->store($user->getDiscordId(), $balance);
            $publisher->publish($user, UserEventEnum::WALLET_CHANGED, ['balance' => $balance->minor, 'formatted' => $balance->format()]);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
