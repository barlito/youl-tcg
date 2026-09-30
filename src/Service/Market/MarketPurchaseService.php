<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Entity\UserCard;
use App\Enum\Coin\CoinPaymentStatusEnum;
use App\Enum\Coin\CoinTransactionTypeEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Market\MarketPurchaseRefusedException;
use App\Repository\CardRepository;
use App\Repository\CoinSettingsRepository;
use App\Repository\MarketListingRepository;
use App\Repository\MarketPurchaseRepository;
use App\Repository\UserCardRepository;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\CoinPayment;
use App\Service\Coin\UniverseCompletionChecker;
use App\Service\Coin\WalletBalances;
use App\Service\Coin\YoulCoinClient;
use App\Service\Notification\NotificationService;
use App\Service\Realtime\UserEventPublisher;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class MarketPurchaseService
{
    private const int ABANDON_AFTER_SECONDS = 600;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketListingRepository $listingRepository,
        private MarketPurchaseRepository $purchaseRepository,
        private UserCardRepository $userCardRepository,
        private CardRepository $cardRepository,
        private CoinSettingsRepository $settingsRepository,
        private YoulCoinClient $coin,
        private WalletBalances $walletBalances,
        private UserEventPublisher $userEventPublisher,
        private NotificationService $notificationService,
        private UniverseCompletionChecker $completionChecker,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** Bank commission in minor units, rounded down; integer math only (no float, no bcmath). */
    public static function feeMinor(int $priceCoins, int $feePercent): int
    {
        return intdiv($priceCoins * 10 ** CoinAmount::SCALE * $feePercent, 100);
    }

    public static function payoutAmount(MarketPurchase $purchase): CoinAmount
    {
        return CoinAmount::fromMinor((string) ($purchase->getPrice() * 10 ** CoinAmount::SCALE - (int) $purchase->getFeeMinor()));
    }

    /**
     * @throws MarketPurchaseRefusedException
     */
    public function purchase(DiscordUser $buyer, MarketListing $listing, string $playerToken): MarketPurchase
    {
        if ($listing->getSeller()->getDiscordId() === $buyer->getDiscordId()) {
            throw new MarketPurchaseRefusedException('Own listing.', 'Tu ne peux pas acheter ta propre annonce.');
        }

        // committed pending BEFORE the debit; the closure returns its refusal, throwing would close the EntityManager
        $started = $this->entityManager->wrapInTransaction(function () use ($buyer, $listing): MarketPurchase | MarketPurchaseRefusedException {
            $locked = $this->listingRepository->findOneForUpdate((string) $listing->getId());

            if (!$locked instanceof MarketListing) {
                return new MarketPurchaseRefusedException('Listing not found.', 'Cette annonce n\'existe plus.');
            }

            // the locked SELECT does not re-hydrate an already-loaded entity
            $this->entityManager->refresh($locked);

            if (!$locked->isActive()) {
                return new MarketPurchaseRefusedException('Listing unavailable.', 'Cette annonce n\'est plus disponible.');
            }

            $purchase = new MarketPurchase(
                $locked,
                $buyer,
                $locked->getSeller(),
                $locked->getPrice(),
                (string) self::feeMinor($locked->getPrice(), $this->settingsRepository->get()->getMarketFeePercent()),
                $this->clock->now(),
            );
            $locked->reserveForPurchase();
            $this->entityManager->persist($purchase);
            $this->entityManager->flush();

            return $purchase;
        });

        if ($started instanceof MarketPurchaseRefusedException) {
            throw $started;
        }

        $payment = $this->coin->debitToBank(
            $buyer->getDiscordId(),
            CoinAmount::fromCoins($started->getPrice()),
            CoinTransactionTypeEnum::MARKET_PAYMENT,
            $started->getPaymentIdentifier(),
            $playerToken,
        );

        switch ($payment->status) {
            case CoinPaymentStatusEnum::PAID:
                $this->transfer($started, (string) $payment->transactionId);

                break;
            case CoinPaymentStatusEnum::REFUSED:
                $this->fail($started, \sprintf('Coin refused the payment (HTTP %d).', $payment->httpStatus));

                throw new MarketPurchaseRefusedException('Coin refused the payment.', $this->refusalMessage($payment));
            case CoinPaymentStatusEnum::UNAVAILABLE:
                $this->fail($started, 'Coin unavailable, nothing was sent.');

                throw new MarketPurchaseRefusedException('Coin unavailable.', 'Youl Coin est indisponible, réessaie dans un instant.');
            default:
                // UNCERTAIN: left pending, resolved by reconcile()
        }

        return $started;
    }

    /**
     * Resumes every purchase the coin has not confirmed yet; each step is idempotent on its externalIdentifier.
     */
    public function reconcile(?DiscordUser $discordUser = null): void
    {
        foreach ($this->purchaseRepository->findUnsettled($discordUser) as $purchase) {
            match ($purchase->getStatus()) {
                MarketPurchaseStatusEnum::PAYMENT_PENDING => $this->reconcilePayment($purchase),
                MarketPurchaseStatusEnum::CARD_TRANSFERRED => $this->payout($purchase, lookupFirst: true),
                MarketPurchaseStatusEnum::REFUND_PENDING => $this->refund($purchase, lookupFirst: true),
                default => null,
            };
        }
    }

    private function reconcilePayment(MarketPurchase $purchase): void
    {
        $found = $this->coin->findTransaction($purchase->getPaymentIdentifier());

        if (CoinPaymentStatusEnum::PAID === $found->status) {
            $this->transfer($purchase, (string) $found->transactionId);
        } elseif (CoinPaymentStatusEnum::NOT_FOUND === $found->status && $this->isAbandoned($purchase)) {
            $this->fail($purchase, 'No coin transaction found after the reconciliation delay.');
        }
    }

    private function isAbandoned(MarketPurchase $purchase): bool
    {
        return $this->clock->now()->getTimestamp() - $purchase->getRequestedAt()->getTimestamp() >= self::ABANDON_AFTER_SECONDS;
    }

    private function refusalMessage(CoinPayment $payment): string
    {
        return match ($payment->httpStatus) {
            422 => 'Solde Youl Coin insuffisant.',
            403 => 'Paiement refusé : reconnecte-toi puis réessaie.',
            409 => 'Ce paiement a déjà été enregistré : recharge la page.',
            default => 'Paiement refusé par Youl Coin.',
        };
    }

    private function transfer(MarketPurchase $purchase, string $paymentTransactionId): void
    {
        $outcome = $this->entityManager->wrapInTransaction(fn (): ?bool => $this->doTransfer($purchase, $paymentTransactionId));

        // null: another process already resolved this purchase
        if (null === $outcome) {
            return;
        }

        if (!$outcome) {
            $this->refund($purchase, lookupFirst: false);

            return;
        }

        $this->afterTransfer($purchase);
        $this->payout($purchase, lookupFirst: false);
    }

    /**
     * @return bool|null true: card moved, false: impossible (refund due), null: purchase no longer pending
     */
    private function doTransfer(MarketPurchase $purchase, string $paymentTransactionId): ?bool
    {
        // the lock + status check make the transfer happen once, whoever resolves the purchase first
        $this->entityManager->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

        if (MarketPurchaseStatusEnum::PAYMENT_PENDING !== $purchase->getStatus()) {
            return null;
        }

        $listing = $purchase->getListing();
        $this->entityManager->refresh($listing, LockMode::PESSIMISTIC_WRITE);
        $card = $listing->getCard();
        $seller = $purchase->getSeller();
        $buyer = $purchase->getBuyer();

        // players in discordId order like trades, so no combination of them can deadlock
        $rows = $this->lockRows($seller, $buyer, $card);
        $sellerRow = $rows[$seller->getDiscordId()] ?? null;
        $buyerRow = $rows[$buyer->getDiscordId()] ?? throw new \LogicException('Locked buyer row vanished.');

        $problem = $this->transferProblem($sellerRow, $listing, $seller);

        // the conditional UPDATE is the last-line guard of a 1/1: it runs before any row moves
        if (null === $problem && $card->isUnique() && !$this->cardRepository->transferUniqueClaim($card, $seller, $buyer)) {
            $problem = 'Lost the unique claim transfer.';
        }

        if (null !== $problem || !$sellerRow instanceof UserCard) {
            $purchase->markRefundPending($paymentTransactionId, $problem ?? 'Seller row vanished.');
            $listing->close(MarketListingStatusEnum::INVALIDATED, $this->clock->now());
            $this->entityManager->flush();

            return false;
        }

        if ($card->isUnique()) {
            $card->setClaimedBy($buyer);
        }

        $holo = $listing->isHolo() ? 1 : 0;
        $sellerRow->setQuantity($sellerRow->getQuantity() - 1)->setHoloQuantity($sellerRow->getHoloQuantity() - $holo);
        // quantity is the total (holos included), holoQuantity a subset of it
        $buyerRow->setQuantity($buyerRow->getQuantity() + 1)->setHoloQuantity($buyerRow->getHoloQuantity() + $holo);

        $listing->close(MarketListingStatusEnum::SOLD, $this->clock->now());
        $purchase->markCardTransferred($paymentTransactionId);
        $this->entityManager->flush();

        return true;
    }

    private function transferProblem(?UserCard $sellerRow, MarketListing $listing, DiscordUser $seller): ?string
    {
        $holo = $sellerRow?->getHoloQuantity() ?? 0;
        $owned = $listing->isHolo() ? $holo : ($sellerRow?->getQuantity() ?? 0) - $holo;

        if ($owned < 1) {
            return 'The seller no longer holds the listed copy.';
        }

        if ($listing->getCard()->isUnique()) {
            // fresh read: the identity-mapped card may carry a stale claimedBy
            $this->entityManager->refresh($listing->getCard());

            if ($listing->getCard()->getClaimedBy()?->getDiscordId() !== $seller->getDiscordId()) {
                return 'The unique card no longer belongs to the seller.';
            }
        }

        return null;
    }

    /**
     * @return array<string, UserCard> discord id => locked row (the seller's is absent when nothing is owned)
     */
    private function lockRows(DiscordUser $seller, DiscordUser $buyer, Card $card): array
    {
        $rows = [];
        $players = [$seller->getDiscordId() => $seller, $buyer->getDiscordId() => $buyer];
        ksort($players, \SORT_STRING);

        foreach ($players as $player) {
            $locked = $player->getDiscordId() === $buyer->getDiscordId()
                ? $this->userCardRepository->lockForCredit($player, [$card])
                : $this->userCardRepository->lockForDebit($player, [$card]);

            if (isset($locked[(string) $card->getId()])) {
                $rows[$player->getDiscordId()] = $locked[(string) $card->getId()];
            }
        }

        return $rows;
    }

    private function afterTransfer(MarketPurchase $purchase): void
    {
        $listing = $purchase->getListing();
        $card = $listing->getCard();
        $buyer = $purchase->getBuyer();
        $seller = $purchase->getSeller();

        // the webhook refreshes the balance too: dropping the cached one covers a late or lost delivery
        $this->walletBalances->forget($buyer->getDiscordId());
        $this->userEventPublisher->publish($buyer, UserEventEnum::INVENTORY_CHANGED);
        $this->userEventPublisher->publish($seller, UserEventEnum::INVENTORY_CHANGED);
        // not alreadyRead: the sale happens while the seller is away
        $this->notificationService->notify($seller, NotificationTypeEnum::MARKET_SOLD, [
            'buyerName' => $buyer->getUsername(),
            'cardName' => $card->getName(),
            'price' => $purchase->getPrice(),
        ]);

        if ($card->getExtension() instanceof Extension) {
            $this->completionChecker->checkAfterCredit($buyer, [$card->getExtension()]);
        }
    }

    // idempotent on the identifier: with $lookupFirst a payout that may already have landed is found, not repeated
    private function payout(MarketPurchase $purchase, bool $lookupFirst): void
    {
        if (MarketPurchaseStatusEnum::CARD_TRANSFERRED !== $purchase->getStatus()) {
            return;
        }

        $amount = self::payoutAmount($purchase);

        if ('0' === $amount->minor) {
            $this->settlePayout($purchase, null);

            return;
        }

        $payment = $this->sendOrLookup($purchase->getPayoutIdentifier(), $lookupFirst, fn (): CoinPayment => $this->coin->creditFromBank(
            $purchase->getSeller()->getDiscordId(),
            $amount,
            CoinTransactionTypeEnum::MARKET_PAYOUT,
            $purchase->getPayoutIdentifier(),
        ));

        if (CoinPaymentStatusEnum::PAID === $payment->status) {
            $this->settlePayout($purchase, (string) $payment->transactionId);
        } elseif (CoinPaymentStatusEnum::REFUSED === $payment->status) {
            $this->logger->error('Youl Coin refused the market payout of purchase {purchase} (HTTP {status}): the seller is still owed, fix it then run app:coin:reconcile-market.', [
                'purchase' => (string) $purchase->getId(),
                'status' => $payment->httpStatus,
            ]);
        }
    }

    private function refund(MarketPurchase $purchase, bool $lookupFirst): void
    {
        if (MarketPurchaseStatusEnum::REFUND_PENDING !== $purchase->getStatus()) {
            return;
        }

        $payment = $this->sendOrLookup($purchase->getRefundIdentifier(), $lookupFirst, fn (): CoinPayment => $this->coin->creditFromBank(
            $purchase->getBuyer()->getDiscordId(),
            CoinAmount::fromCoins($purchase->getPrice()),
            CoinTransactionTypeEnum::MARKET_REFUND,
            $purchase->getRefundIdentifier(),
        ));

        if (CoinPaymentStatusEnum::PAID === $payment->status) {
            $this->settleRefund($purchase, (string) $payment->transactionId);
        } elseif (CoinPaymentStatusEnum::REFUSED === $payment->status) {
            $this->logger->error('Youl Coin refused the market refund of purchase {purchase} (HTTP {status}): the buyer is still owed, fix it then run app:coin:reconcile-market.', [
                'purchase' => (string) $purchase->getId(),
                'status' => $payment->httpStatus,
            ]);
        }
    }

    /**
     * @param \Closure(): CoinPayment $send
     */
    private function sendOrLookup(string $identifier, bool $lookupFirst, \Closure $send): CoinPayment
    {
        if ($lookupFirst) {
            $found = $this->coin->findTransaction($identifier);

            if (CoinPaymentStatusEnum::NOT_FOUND !== $found->status) {
                return $found;
            }
        }

        return $send();
    }

    private function settlePayout(MarketPurchase $purchase, ?string $transactionId): void
    {
        $done = $this->entityManager->wrapInTransaction(function () use ($purchase, $transactionId): bool {
            $this->entityManager->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

            if (MarketPurchaseStatusEnum::CARD_TRANSFERRED !== $purchase->getStatus()) {
                return false;
            }

            $purchase->complete($transactionId, $this->clock->now());
            $this->entityManager->flush();

            return true;
        });

        if ($done) {
            $this->walletBalances->forget($purchase->getSeller()->getDiscordId());
        }
    }

    private function settleRefund(MarketPurchase $purchase, string $transactionId): void
    {
        $done = $this->entityManager->wrapInTransaction(function () use ($purchase, $transactionId): bool {
            $this->entityManager->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

            if (MarketPurchaseStatusEnum::REFUND_PENDING !== $purchase->getStatus()) {
                return false;
            }

            $purchase->refund($transactionId, $this->clock->now());
            $this->entityManager->flush();

            return true;
        });

        if ($done) {
            $this->walletBalances->forget($purchase->getBuyer()->getDiscordId());
        }
    }

    private function fail(MarketPurchase $purchase, string $reason): void
    {
        $this->entityManager->wrapInTransaction(function () use ($purchase, $reason): void {
            $this->entityManager->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

            if (MarketPurchaseStatusEnum::PAYMENT_PENDING !== $purchase->getStatus()) {
                return;
            }

            $listing = $purchase->getListing();
            $this->entityManager->refresh($listing, LockMode::PESSIMISTIC_WRITE);

            if (MarketListingStatusEnum::RESERVED_FOR_PURCHASE === $listing->getStatus()) {
                $listing->reopen();
            }

            $purchase->fail($reason, $this->clock->now());
            $this->entityManager->flush();
        });
    }
}
