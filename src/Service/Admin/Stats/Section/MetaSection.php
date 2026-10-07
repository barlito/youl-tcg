<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Dto\OpeningStreak;
use App\Entity\Booster;
use App\Entity\CoinSettings;
use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\FeatureEnum;
use App\Service\Admin\EconomyStatsProvider;
use App\Service\Admin\Stats\ApiStatsProvider;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Booster\BoosterPurchaseService;
use App\Service\Coin\CoinAmount;
use App\Service\Feature\FeatureFlags;
use App\Service\Fusion\FusionService;
use App\Service\Market\MarketListingService;
use App\Service\Recycle\RecycleService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MetaSection extends AbstractStatsSection
{
    public function __construct(
        StatsDb $db,
        private FeatureFlags $featureFlags,
        #[Autowire(env: 'APP_VERSION')]
        private string $appVersion,
    ) {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::META;
    }

    public function build(StatsContext $context): array
    {
        $features = [];

        foreach (FeatureEnum::cases() as $feature) {
            $features[$feature->value] = $this->featureFlags->isEnabled($feature);
        }

        $settings = $this->db->one('SELECT default_universe_reward_coins, market_fee_percent FROM coin_settings WHERE id = :id', ['id' => CoinSettings::ID]);
        $rewardCoins = [] === $settings ? CoinSettings::DEFAULT_UNIVERSE_REWARD_COINS : StatsFormat::int($settings, 'default_universe_reward_coins');

        return [
            'generatedAt' => StatsFormat::isoNow($context->now),
            'period' => $context->period->value,
            'periodStart' => $context->days[0],
            'periodEnd' => $context->today,
            'timezone' => EconomyStatsProvider::TIMEZONE,
            'cacheTtlSeconds' => ApiStatsProvider::CACHE_TTL,
            'version' => $this->appVersion,
            'commit' => null,
            'features' => $features,
            'coinSettings' => [
                'defaultUniverseRewardCoins' => StatsFormat::coins($rewardCoins),
                'marketFeePercent' => [] === $settings ? CoinSettings::DEFAULT_MARKET_FEE_PERCENT : StatsFormat::int($settings, 'market_fee_percent'),
            ],
            'gameRules' => [
                'dailyFreeClaims' => BoosterClaimQuotaInterface::DAILY_LIMIT,
                'dailyBoosterPurchases' => BoosterPurchaseService::DAILY_LIMIT,
                'recycleBoosterCostPoints' => RecycleService::BOOSTER_COST,
                'recyclePointsPerRarity' => $this->recyclePoints(),
                'fusionCostCopies' => FusionService::FUSION_COST,
                'fusionMaxPerOperation' => FusionService::MAX_FUSIONS_PER_OPERATION,
                'streakMilestoneStepDays' => OpeningStreak::MILESTONE_STEP,
                'marketMaxActiveListingsPerPlayer' => MarketListingService::MAX_ACTIVE_LISTINGS,
                'marketMaxPriceCoins' => MarketListingService::MAX_PRICE,
                'uniqueChanceScale' => Booster::UNIQUE_CHANCE_SCALE,
                'coinMinorUnitsPerCoin' => 10 ** CoinAmount::SCALE,
            ],
            'sections' => StatsSectionEnum::values(),
        ];
    }

    /**
     * @return array<string, array{normal: int, holo: int}>
     */
    private function recyclePoints(): array
    {
        $points = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            $points[$rarity->value] = ['normal' => $rarity->recyclePoints(), 'holo' => $rarity->holoRecyclePoints()];
        }

        return $points;
    }
}
