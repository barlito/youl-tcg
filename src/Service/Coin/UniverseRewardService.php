<?php

declare(strict_types=1);

namespace App\Service\Coin;

use App\Entity\DiscordUser;
use App\Entity\UniverseCompletionReward;
use App\Enum\Coin\CoinPaymentStatusEnum;
use App\Enum\Coin\CoinTransactionTypeEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Repository\UniverseCompletionRewardRepository;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class UniverseRewardService
{
    private const int RETRY_AFTER_SECONDS = 60;

    public function __construct(
        private YoulCoinClient $coin,
        private EntityManagerInterface $entityManager,
        private UniverseCompletionRewardRepository $rewardRepository,
        private NotificationService $notificationService,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    // the coin credit is idempotent on externalIdentifier: paying again a reward whose outcome is unknown is safe
    public function pay(UniverseCompletionReward $reward): void
    {
        if ($reward->isPaid()) {
            return;
        }

        $payment = $this->coin->creditFromBank(
            $reward->getDiscordUser()->getDiscordId(),
            CoinAmount::fromCoins($reward->getAmount()),
            CoinTransactionTypeEnum::REWARD,
            $reward->getExternalIdentifier(),
        );

        if (CoinPaymentStatusEnum::PAID === $payment->status) {
            $this->settle($reward, (string) $payment->transactionId);
        } elseif (CoinPaymentStatusEnum::REFUSED === $payment->status) {
            $this->logger->error('Youl Coin refused the universe reward {reward} (HTTP {status}): fix it, then run app:coin:pay-pending-rewards --retry-failed.', [
                'reward' => (string) $reward->getId(),
                'status' => $payment->httpStatus,
            ]);
            $this->markFailed($reward);
        }
    }

    // scoped to a player = page-view retry: only rewards older than a minute, one still being paid is left alone
    public function payPending(?DiscordUser $discordUser = null, bool $includeFailed = false): void
    {
        $before = $discordUser instanceof DiscordUser ? $this->clock->now()->modify('-' . self::RETRY_AFTER_SECONDS . ' seconds') : null;

        foreach ($this->rewardRepository->findToPay($discordUser, $before, $includeFailed) as $reward) {
            $this->pay($reward);
        }
    }

    private function settle(UniverseCompletionReward $reward, string $transactionId): void
    {
        $settled = $this->entityManager->wrapInTransaction(function () use ($reward, $transactionId): bool {
            $this->entityManager->refresh($reward, LockMode::PESSIMISTIC_WRITE);

            if ($reward->isPaid()) {
                return false;
            }

            $reward->markPaid($transactionId, $this->clock->now());
            $this->entityManager->flush();

            return true;
        });

        if ($settled) {
            $extension = $reward->getExtension();
            // not alreadyRead: the coins land after the fact (webhook, retry), not as a result the player watched
            $this->notificationService->notify($reward->getDiscordUser(), NotificationTypeEnum::UNIVERSE_COMPLETED, [
                'universe' => $extension->getName(),
                'slug' => $extension->getSlug(),
                'amount' => $reward->getAmount(),
            ]);
        }
    }

    private function markFailed(UniverseCompletionReward $reward): void
    {
        $this->entityManager->wrapInTransaction(function () use ($reward): void {
            $this->entityManager->refresh($reward, LockMode::PESSIMISTIC_WRITE);

            if (!$reward->isPaid()) {
                $reward->markFailed();
                $this->entityManager->flush();
            }
        });
    }
}
