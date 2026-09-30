<?php

declare(strict_types=1);

namespace App\Service\Coin;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\CardRepository;
use App\Repository\DiscordUserRepository;
use App\Repository\ExtensionRepository;
use App\Repository\UniverseCompletionRewardRepository;
use App\Repository\UserCardRepository;

final readonly class UniverseCompletionCatchUp
{
    public function __construct(
        private ExtensionRepository $extensionRepository,
        private CardRepository $cardRepository,
        private UserCardRepository $userCardRepository,
        private DiscordUserRepository $discordUserRepository,
        private UniverseCompletionRewardRepository $rewardRepository,
        private UniverseCompletionChecker $checker,
    ) {
    }

    /**
     * @return list<array{user: DiscordUser, extension: Extension, amount: int}>
     */
    public function findMissing(?string $discordId = null): array
    {
        /** @var list<Extension> $extensions */
        $extensions = $this->extensionRepository->findBy(['status' => ExtensionStatusEnum::PUBLISHED], ['name' => 'ASC']);
        $byId = [];
        foreach ($extensions as $extension) {
            $byId[(string) $extension->getId()] = $extension;
        }

        if ([] === $byId) {
            return [];
        }

        $ids = array_keys($byId);
        $totals = $this->cardRepository->countPublishedNonUniqueByExtension($ids);
        $rewarded = $this->rewardRepository->findRewardedPairs();

        $missing = [];
        foreach ($this->userCardRepository->countOwnedNonUniqueByPlayerAndExtension($ids, $discordId) as $playerId => $owned) {
            foreach ($owned as $extensionId => $count) {
                if (($totals[$extensionId] ?? 0) > 0 && $count >= $totals[$extensionId] && !isset($rewarded[$playerId . '|' . $extensionId])) {
                    $missing[] = [$playerId, $byId[$extensionId]];
                }
            }
        }

        $users = [];
        foreach ($this->discordUserRepository->findBy(['discordId' => array_values(array_unique(array_column($missing, 0)))]) as $user) {
            $users[$user->getDiscordId()] = $user;
        }

        $rows = [];
        foreach ($missing as [$playerId, $extension]) {
            if (isset($users[$playerId])) {
                $rows[] = ['user' => $users[$playerId], 'extension' => $extension, 'amount' => $this->checker->rewardAmount($extension)];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['user']->getUsername(), $a['extension']->getName()] <=> [$b['user']->getUsername(), $b['extension']->getName()]);

        return $rows;
    }
}
