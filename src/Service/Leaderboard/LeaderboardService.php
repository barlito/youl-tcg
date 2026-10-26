<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Dto\LeaderboardEntry;
use App\Entity\DiscordUser;
use App\Enum\Leaderboard\LeaderboardSortEnum;
use App\Repository\BoosterOpeningRepository;
use App\Repository\CardRepository;
use App\Repository\DiscordUserRepository;
use App\Repository\ExtensionRepository;
use App\Repository\UserCardRepository;

/**
 * Builds the collectors leaderboard: every player ranked by global completion
 * (distinct owned cards vs published catalogue) by default, or by holos / opened
 * packs. Each metric comes from one grouped query — never one query per player.
 */
final readonly class LeaderboardService
{
    public function __construct(
        private DiscordUserRepository $discordUserRepository,
        private UserCardRepository $userCardRepository,
        private CardRepository $cardRepository,
        private BoosterOpeningRepository $boosterOpeningRepository,
        private ExtensionRepository $extensionRepository,
    ) {
    }

    /**
     * @return list<LeaderboardEntry> ranked by $sort, rank = position in that ranking
     */
    public function getLeaderboard(LeaderboardSortEnum $sort = LeaderboardSortEnum::COMPLETION): array
    {
        $ownedStats = $this->userCardRepository->aggregateOwnedByUser();
        $uniqueCounts = $this->cardRepository->countClaimedUniquesByUser();
        $openingCounts = $this->boosterOpeningRepository->countGroupedByUser();
        // same denominator as the collection page: published cards of published extensions
        $totalPublished = array_sum(array_column($this->extensionRepository->findPublishedWithPublishedCardCount(), 'cardCount'));

        $entries = [];
        foreach ($this->discordUserRepository->findAll() as $player) {
            $discordId = $player->getDiscordId();
            $stats = $ownedStats[$discordId] ?? ['distinct' => 0, 'total' => 0, 'holo' => 0];

            $entries[] = new LeaderboardEntry(
                rank: 0,
                user: $player,
                distinctCards: $stats['distinct'],
                totalCards: $stats['total'],
                holoCards: $stats['holo'],
                uniqueCards: $uniqueCounts[$discordId] ?? 0,
                openedBoosters: $openingCounts[$discordId] ?? 0,
                completionPct: $totalPublished > 0 ? (int) floor($stats['distinct'] / $totalPublished * 100) : 0,
                catalogueSize: $totalPublished,
            );
        }

        // the chosen metric desc, then the completion order: distinct desc (shared
        // denominator), total copies, username, discordId — always deterministic
        usort(
            $entries,
            static fn (LeaderboardEntry $a, LeaderboardEntry $b): int => $sort->metric($b) <=> $sort->metric($a)
                ?: $b->distinctCards <=> $a->distinctCards
                ?: $b->totalCards <=> $a->totalCards
                ?: strcasecmp($a->user->getUsername(), $b->user->getUsername())
                ?: $a->user->getDiscordId() <=> $b->user->getDiscordId(),
        );

        return array_map(
            static fn (LeaderboardEntry $entry, int $index): LeaderboardEntry => $entry->withRank($index + 1),
            $entries,
            array_keys($entries),
        );
    }

    /**
     * The leaderboard row of a single player, rank included (profile header).
     */
    public function getEntryFor(DiscordUser $user): LeaderboardEntry
    {
        foreach ($this->getLeaderboard() as $entry) {
            if ($entry->user->getDiscordId() === $user->getDiscordId()) {
                return $entry;
            }
        }

        throw new \LogicException(\sprintf('Player "%s" missing from the leaderboard.', $user->getDiscordId()));
    }
}
