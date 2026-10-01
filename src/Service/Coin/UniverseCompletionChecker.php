<?php

declare(strict_types=1);

namespace App\Service\Coin;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UniverseCompletionReward;
use App\Enum\FeatureEnum;
use App\Repository\CardRepository;
use App\Repository\CoinSettingsRepository;
use App\Repository\UniverseCompletionRewardRepository;
use App\Repository\UserCardRepository;
use App\Service\Feature\FeatureFlags;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class UniverseCompletionChecker
{
    public function __construct(
        private CardRepository $cardRepository,
        private UserCardRepository $userCardRepository,
        private UniverseCompletionRewardRepository $rewardRepository,
        private CoinSettingsRepository $settingsRepository,
        private UniverseRewardService $rewardService,
        private FeatureFlags $featureFlags,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<Extension> $extensions
     */
    public function checkAfterCredit(DiscordUser $discordUser, iterable $extensions): void
    {
        // single entry point of every card-crediting channel: call it AFTER the commit, best effort
        try {
            if (!$this->featureFlags->isEnabled(FeatureEnum::UNIVERSE_REWARDS)) {
                return;
            }

            $unique = [];
            foreach ($extensions as $extension) {
                $unique[(string) $extension->getId()] = $extension;
            }

            if ([] === $unique) {
                return;
            }

            $ids = array_keys($unique);
            $totals = $this->cardRepository->countPublishedNonUniqueByExtension($ids);
            $owned = $this->userCardRepository->countOwnedNonUniqueByExtension($discordUser, $ids);

            foreach ($unique as $id => $extension) {
                if (($totals[$id] ?? 0) > 0 && ($owned[$id] ?? 0) >= $totals[$id]) {
                    $this->rewardCompleted($discordUser, $extension);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Universe completion check failed: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
        }
    }

    public function rewardAmount(Extension $extension): int
    {
        return $extension->getCompletionRewardCoins() ?? $this->settingsRepository->get()->getDefaultUniverseRewardCoins();
    }

    // null: already rewarded once, for good — or rewards switched off
    public function rewardCompleted(DiscordUser $discordUser, Extension $extension): ?UniverseCompletionReward
    {
        if (!$this->featureFlags->isEnabled(FeatureEnum::UNIVERSE_REWARDS)) {
            return null;
        }

        $amount = $this->rewardAmount($extension);
        $reward = $this->rewardRepository->insertIgnore($discordUser, $extension, $amount, $this->clock->now());

        if ($reward instanceof UniverseCompletionReward && $amount > 0) {
            $this->rewardService->pay($reward);
        }

        return $reward;
    }
}
