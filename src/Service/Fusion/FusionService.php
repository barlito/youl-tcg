<?php

declare(strict_types=1);

namespace App\Service\Fusion;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\FusionOperation;
use App\Entity\UserCard;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Fusion\FusionClosedException;
use App\Exception\Fusion\FusionException;
use App\Exception\Fusion\InvalidFusionCountException;
use App\Exception\Fusion\NotEnoughFusableCopiesException;
use App\Exception\Fusion\NotFusableCardException;
use App\Repository\UserCardRepository;
use App\Service\Feature\FeatureFlags;
use App\Service\Realtime\UserEventPublisher;
use App\Service\Trade\EngagedCopies;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Turns FUSION_COST free normal copies of one card into one holo copy of it, as
 * one atomic transaction: lock the user_card row, read the engaged copies under
 * that lock, debit, credit the holo and persist the audit row.
 */
final readonly class FusionService
{
    /**
     * Normal copies consumed by one fusion.
     */
    public const int FUSION_COST = 10;

    /**
     * Fusions of one card per operation.
     */
    public const int MAX_FUSIONS_PER_OPERATION = 10;

    public function __construct(
        private UserCardRepository $userCardRepository,
        private EngagedCopies $engagedCopies,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private FeatureFlags $featureFlags,
        private UserEventPublisher $userEventPublisher,
    ) {
    }

    /**
     * Normal copies not engaged in a pending trade offer or a market listing.
     * quantity is the TOTAL (holo included): normal copies = quantity - holoQuantity.
     *
     * @param array{normal: int, holo: int}|null $reserved
     */
    public static function freeNormalCopies(UserCard $userCard, ?array $reserved): int
    {
        return max(0, $userCard->getQuantity() - $userCard->getHoloQuantity() - ($reserved['normal'] ?? 0));
    }

    public static function maxFusions(int $freeNormalCopies): int
    {
        return min(self::MAX_FUSIONS_PER_OPERATION, intdiv($freeNormalCopies, self::FUSION_COST));
    }

    /**
     * Published catalogue only, and never a 1/1 (the single copy is the whole point of a unique).
     */
    public static function isFusable(Card $card): bool
    {
        return !$card->isUnique()
            && CardStatusEnum::PUBLISHED === $card->getStatus()
            && ExtensionStatusEnum::PUBLISHED === $card->getExtension()?->getStatus();
    }

    /**
     * @throws FusionClosedException
     * @throws NotFusableCardException
     * @throws InvalidFusionCountException
     * @throws NotEnoughFusableCopiesException
     */
    public function fuse(DiscordUser $discordUser, Card $card, int $fusions): FusionOperation
    {
        if (!$this->featureFlags->isEnabled(FeatureEnum::FUSION)) {
            throw new FusionClosedException('Fusion feature is disabled.', 'La fusion est momentanément fermée.');
        }

        if ($fusions < 1 || $fusions > self::MAX_FUSIONS_PER_OPERATION) {
            throw new InvalidFusionCountException(
                \sprintf('Invalid fusion count %d (1 to %d).', $fusions, self::MAX_FUSIONS_PER_OPERATION),
                \sprintf('Tu peux lancer de 1 à %d fusions à la fois.', self::MAX_FUSIONS_PER_OPERATION),
            );
        }

        if (!self::isFusable($card)) {
            throw new NotFusableCardException(
                \sprintf('Card "%s" cannot be fused (unique, draft or unpublished universe).', $card->getName()),
                \sprintf('« %s » ne peut pas être fusionnée.', $card->getName()),
            );
        }

        // refusals leave the closure as values: throwing inside would close the EntityManager
        $result = $this->entityManager->wrapInTransaction(function () use ($discordUser, $card, $fusions): FusionOperation | FusionException {
            // the same row locks as an opening or a recycling; the holo credit lands on the row locked for the debit
            $lockedRows = $this->userCardRepository->lockForDebit($discordUser, [$card]);
            $userCard = $lockedRows[(string) $card->getId()] ?? null;

            // read under the row lock: an offer or a listing created meanwhile has committed
            $reserved = $this->engagedCopies->reservedQuantities($discordUser)[(string) $card->getId()] ?? null;
            $needed = $fusions * self::FUSION_COST;
            $free = $userCard instanceof UserCard ? self::freeNormalCopies($userCard, $reserved) : 0;

            if (!$userCard instanceof UserCard || $free < $needed) {
                return new NotEnoughFusableCopiesException(
                    \sprintf('Fusing %d time(s) "%s" needs %d free normal copies (free: %d).', $fusions, $card->getName(), $needed, $free),
                    \sprintf('Il te faut %d copies normales libres de « %s » (tu en as %d). Les copies en vente ou proposées dans un échange ne comptent pas.', $needed, $card->getName(), $free),
                );
            }

            $userCard
                ->setQuantity($userCard->getQuantity() - $needed + $fusions)
                ->setHoloQuantity($userCard->getHoloQuantity() + $fusions)
            ;

            $operation = new FusionOperation($discordUser, $card, $fusions, $needed, $fusions, $this->clock->now());
            $this->entityManager->persist($operation);
            $this->entityManager->flush();

            return $operation;
        });

        if ($result instanceof FusionException) {
            throw $result;
        }

        // post-commit: a rolled back action never reaches the browser
        $this->userEventPublisher->publish($discordUser, UserEventEnum::INVENTORY_CHANGED);

        return $result;
    }
}
