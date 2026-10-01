<?php

declare(strict_types=1);

namespace App\Service\Trade;

use App\Dto\TradeMatchScore;
use App\Entity\DiscordUser;
use App\Repository\DiscordUserRepository;
use App\Repository\UserCardRepository;

/**
 * Match scores of the player picker. Same rules as the composer: my side is
 * my FREE copies (ledger via EngagedCopies), their side is their plain
 * possession (the requested side is never reserved). One grouped query for
 * every other player, never one per player.
 */
final readonly class TradeMatchScoreService
{
    public function __construct(
        private DiscordUserRepository $discordUserRepository,
        private UserCardRepository $userCardRepository,
        private EngagedCopies $engagedCopies,
    ) {
    }

    /**
     * @return list<TradeMatchScore> every other player, best match first
     */
    public function scoresFor(DiscordUser $me): array
    {
        $reserved = $this->engagedCopies->reservedQuantities($me);
        $myOwned = [];
        $myFreeDoubles = [];

        foreach ($this->userCardRepository->findOwnedWithCards($me, publishedOnly: true) as $row) {
            $cardId = (string) $row->getCard()->getId();
            $quantity = $row->getQuantity();
            if ($quantity <= 0 && $row->getHoloQuantity() <= 0) {
                continue;
            }

            $myOwned[$cardId] = true;
            $held = $reserved[$cardId] ?? ['normal' => 0, 'holo' => 0];
            $hasFreeCopy = $row->getHoloQuantity() - $held['holo'] > 0 || $quantity - $row->getHoloQuantity() - $held['normal'] > 0;

            if ($quantity >= 2 && $hasFreeCopy) {
                $myFreeDoubles[$cardId] = true;
            }
        }

        $theirOwned = $this->userCardRepository->findPublishedOwnedCardIdsByPlayer($me);
        $scores = [];

        foreach ($this->discordUserRepository->findOthersOrderedByUsername($me) as $player) {
            $owned = $theirOwned[$player->getDiscordId()] ?? [];

            $scores[] = new TradeMatchScore(
                $player,
                \count(array_diff_key($owned, $myOwned)),
                \count(array_diff_key($myFreeDoubles, $owned)),
            );
        }

        usort($scores, static fn (TradeMatchScore $a, TradeMatchScore $b): int => $b->total() <=> $a->total()
            ?: strcasecmp($a->player->getUsername(), $b->player->getUsername())
            ?: $a->player->getDiscordId() <=> $b->player->getDiscordId());

        return $scores;
    }
}
