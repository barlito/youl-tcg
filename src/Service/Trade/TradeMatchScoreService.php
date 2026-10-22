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
        ['owned' => $myOwned, 'freeDoubles' => $myFreeDoubles] = $this->mySide($me);
        $theirOwned = $this->userCardRepository->findPublishedOwnedCardIdsByPlayer($me);
        $scores = [];

        foreach ($this->discordUserRepository->findOthersOrderedByUsername($me) as $player) {
            $scores[] = $this->score($player, $theirOwned[$player->getDiscordId()] ?? [], $myOwned, $myFreeDoubles);
        }

        usort($scores, static fn (TradeMatchScore $a, TradeMatchScore $b): int => $b->total() <=> $a->total()
            ?: strcasecmp($a->player->getUsername(), $b->player->getUsername())
            ?: $a->player->getDiscordId() <=> $b->player->getDiscordId());

        return $scores;
    }

    /**
     * The same score against ONE player (« Entre vous deux » of a profile): a
     * constant number of queries, never the whole player base.
     */
    public function scoreAgainst(DiscordUser $me, DiscordUser $other): TradeMatchScore
    {
        ['owned' => $myOwned, 'freeDoubles' => $myFreeDoubles] = $this->mySide($me);
        $theirOwned = [];

        foreach ($this->userCardRepository->findOwnedWithCards($other, publishedOnly: true) as $row) {
            if ($row->getQuantity() > 0 || $row->getHoloQuantity() > 0) {
                $theirOwned[(string) $row->getCard()->getId()] = true;
            }
        }

        return $this->score($other, $theirOwned, $myOwned, $myFreeDoubles);
    }

    /**
     * @param array<string, true> $theirOwned
     * @param array<string, true> $myOwned
     * @param array<string, true> $myFreeDoubles
     */
    private function score(DiscordUser $player, array $theirOwned, array $myOwned, array $myFreeDoubles): TradeMatchScore
    {
        return new TradeMatchScore(
            $player,
            \count(array_diff_key($theirOwned, $myOwned)),
            \count(array_diff_key($myFreeDoubles, $theirOwned)),
        );
    }

    /**
     * My published cards, and those I hold at least twice with a copy free of
     * any pending offer or listing.
     *
     * @return array{owned: array<string, true>, freeDoubles: array<string, true>}
     */
    private function mySide(DiscordUser $me): array
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

        return ['owned' => $myOwned, 'freeDoubles' => $myFreeDoubles];
    }
}
