<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Entity\BoosterPurchase;
use App\Entity\DiscordUser;
use App\Enum\Coin\CoinPaymentStatusEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Booster\BoosterPurchaseRefusedException;
use App\Repository\BoosterPurchaseRepository;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\CoinPayment;
use App\Service\Coin\WalletBalances;
use App\Service\Coin\YoulCoinClient;
use App\Service\Realtime\UserEventPublisher;
use App\Service\Time\ParisDay;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class BoosterPurchaseService
{
    public const int DAILY_LIMIT = 1;

    private const int ABANDON_AFTER_SECONDS = 600;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BoosterPurchaseRepository $purchaseRepository,
        private BoosterAvailabilityService $boosterAvailability,
        private UserInventoryService $userInventoryService,
        private YoulCoinClient $coin,
        private WalletBalances $walletBalances,
        private UserEventPublisher $userEventPublisher,
        private ParisDay $parisDay,
        private ClockInterface $clock,
    ) {
    }

    public function getRemainingPurchases(DiscordUser $discordUser): int
    {
        return max(0, self::DAILY_LIMIT - $this->purchaseRepository->countSince($discordUser, $this->parisDay->start()));
    }

    /**
     * @throws BoosterPurchaseRefusedException
     */
    public function purchase(DiscordUser $discordUser, Booster $booster, string $playerToken): BoosterPurchase
    {
        // committed pending BEFORE the debit: an uncertain outcome is reconciled later by externalIdentifier
        $purchase = $this->entityManager->wrapInTransaction(function () use ($discordUser, $booster): BoosterPurchase {
            // the quota is a COUNT then an INSERT: serialize the player's purchases on their row, like claims
            $this->entityManager->find(DiscordUser::class, $discordUser->getDiscordId(), LockMode::PESSIMISTIC_WRITE);

            if (!$this->boosterAvailability->isPurchasable($booster)) {
                throw new BoosterPurchaseRefusedException(
                    \sprintf('Booster "%s" is not purchasable.', $booster->getDisplayName()),
                    'Ce pack n\'est pas en vente pour le moment.',
                );
            }

            if ($this->getRemainingPurchases($discordUser) < 1) {
                throw new BoosterPurchaseRefusedException('Daily purchase limit reached.', 'Tu as déjà acheté un pack aujourd\'hui, reviens demain !');
            }

            $purchase = new BoosterPurchase($discordUser, $booster, (int) $booster->getPurchasePrice(), $this->clock->now());
            $this->entityManager->persist($purchase);
            $this->entityManager->flush();

            return $purchase;
        });

        $payment = $this->coin->debitToBank(
            $discordUser->getDiscordId(),
            CoinAmount::fromCoins($purchase->getPrice()),
            $purchase->getExternalIdentifier(),
            $playerToken,
        );

        switch ($payment->status) {
            case CoinPaymentStatusEnum::PAID:
                $this->complete($purchase, (string) $payment->transactionId);

                break;
            case CoinPaymentStatusEnum::REFUSED:
                $this->fail($purchase, \sprintf('Coin refused the payment (HTTP %d).', $payment->httpStatus));

                throw new BoosterPurchaseRefusedException('Coin refused the payment.', $this->refusalMessage($payment));
            case CoinPaymentStatusEnum::UNAVAILABLE:
                $this->fail($purchase, 'Coin unavailable, nothing was sent.');

                throw new BoosterPurchaseRefusedException('Coin unavailable.', 'Youl Coin est indisponible, réessaie dans un instant.');
            default:
                // UNCERTAIN: left pending, resolved by reconcile()
        }

        return $purchase;
    }

    public function getPendingPurchase(DiscordUser $discordUser): ?BoosterPurchase
    {
        $this->reconcilePending($discordUser);

        return $this->purchaseRepository->findPending($discordUser)[0] ?? null;
    }

    public function reconcilePending(?DiscordUser $discordUser = null): void
    {
        foreach ($this->purchaseRepository->findPending($discordUser) as $purchase) {
            $found = $this->coin->findTransaction($purchase->getExternalIdentifier());

            if (CoinPaymentStatusEnum::PAID === $found->status) {
                $this->complete($purchase, (string) $found->transactionId);
            } elseif (CoinPaymentStatusEnum::NOT_FOUND === $found->status && $this->isAbandoned($purchase)) {
                $this->fail($purchase, 'No coin transaction found after the reconciliation delay.');
            }
        }
    }

    private function isAbandoned(BoosterPurchase $purchase): bool
    {
        return $this->clock->now()->getTimestamp() - $purchase->getRequestedAt()->getTimestamp() >= self::ABANDON_AFTER_SECONDS;
    }

    private function refusalMessage(CoinPayment $payment): string
    {
        return match ($payment->httpStatus) {
            422 => 'Solde Youl Coin insuffisant.',
            403 => 'Paiement refusé : reconnecte-toi puis réessaie.',
            default => 'Paiement refusé par Youl Coin.',
        };
    }

    private function complete(BoosterPurchase $purchase, string $transactionId): void
    {
        $credited = $this->entityManager->wrapInTransaction(function () use ($purchase, $transactionId): bool {
            // the lock + status check make the credit happen once, whoever resolves the purchase first
            $this->entityManager->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

            if (!$purchase->isPending()) {
                return false;
            }

            $this->userInventoryService->creditBooster($purchase->getDiscordUser(), $purchase->getBooster());
            $purchase->complete($transactionId, $this->clock->now());
            $this->entityManager->flush();

            return true;
        });

        if ($credited) {
            // the webhook refreshes the balance too: dropping the cached one covers a late or lost delivery
            $this->walletBalances->forget($purchase->getDiscordUser()->getDiscordId());
            $this->userEventPublisher->publish($purchase->getDiscordUser(), UserEventEnum::INVENTORY_CHANGED);
        }
    }

    private function fail(BoosterPurchase $purchase, string $reason): void
    {
        $this->entityManager->wrapInTransaction(function () use ($purchase, $reason): void {
            $this->entityManager->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

            if ($purchase->isPending()) {
                $purchase->fail($reason, $this->clock->now());
                $this->entityManager->flush();
            }
        });
    }
}
