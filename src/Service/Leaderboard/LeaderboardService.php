<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Dto\LeaderboardEntry;
use App\Entity\DiscordUser;
use App\Repository\BoosterOpeningRepository;
use App\Repository\CardRepository;
use App\Repository\DiscordUserRepository;
use App\Repository\ExtensionRepository;
use App\Repository\UserCardRepository;

/**
 * Builds the collectors leaderboard: every player ranked by global completion
 * (distinct owned cards vs published catalogue). Each metric comes from one
 * grouped query — never one query per player.
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
     * @return list<LeaderboardEntry>
     */
    public function getLeaderboard(): array
    {
        $ownedStats = $this->userCardRepository->aggregateOwnedByUser();
        $uniqueCounts = $this->cardRepository->countClaimedUniquesByUser();
        $openingCounts = $this->boosterOpeningRepository->countGroupedByUser();
        // same denominator as the collection page: published cards of published extensions
        $totalPublished = array_sum(array_column($this->extensionRepository->findPublishedWithPublishedCardCount(), 'cardCount'));

        $players = $this->discordUserRepository->findAll();

        usort(
            $players,
            static function (DiscordUser $a, DiscordUser $b) use ($ownedStats): int {
                $statsA = $ownedStats[$a->getDiscordId()] ?? ['distinct' => 0, 'total' => 0];
                $statsB = $ownedStats[$b->getDiscordId()] ?? ['distinct' => 0, 'total' => 0];

                // completion desc == distinct desc (shared denominator), total copies
                // then username/discordId as deterministic tiebreaks
                return $statsB['distinct'] <=> $statsA['distinct']
                    ?: $statsB['total'] <=> $statsA['total']
                    ?: strcasecmp($a->getUsername(), $b->getUsername())
                    ?: $a->getDiscordId() <=> $b->getDiscordId();
            },
        );

        $entries = [];
        foreach ($players as $index => $player) {
            $discordId = $player->getDiscordId();
            $stats = $ownedStats[$discordId] ?? ['distinct' => 0, 'total' => 0, 'holo' => 0];

            $entries[] = new LeaderboardEntry(
                rank: $index + 1,
                user: $player,
                distinctCards: $stats['distinct'],
                totalCards: $stats['total'],
                holoCards: $stats['holo'],
                uniqueCards: $uniqueCounts[$discordId] ?? 0,
                openedBoosters: $openingCounts[$discordId] ?? 0,
                completionPct: $totalPublished > 0 ? (int) round($stats['distinct'] / $totalPublished * 100) : 0,
            );
        }

        return $entries;
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
