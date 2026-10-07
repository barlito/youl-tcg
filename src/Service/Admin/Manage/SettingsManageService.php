<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Entity\CoinSettings;
use App\Entity\FeatureFlag;
use App\Enum\FeatureEnum;
use App\Repository\CoinSettingsRepository;
use App\Repository\FeatureFlagRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SettingsManageService
{
    private const array SETTINGS_FIELDS = ['defaultUniverseRewardCoins', 'marketFeePercent'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CoinSettingsRepository $settingsRepository,
        private FeatureFlagRepository $featureFlagRepository,
        private ManageApiValidator $validator,
        private ManageApiAudit $audit,
        private AdminApiCacheInvalidator $caches,
    ) {
    }

    public function settings(): CoinSettings
    {
        return $this->settingsRepository->get();
    }

    public function updateSettings(ApiInput $input): CoinSettings
    {
        $input->allowOnly(self::SETTINGS_FIELDS);
        if ($input->isEmpty()) {
            $input->error('body', 'Aucun champ à modifier.');
        }
        $reward = $input->has('defaultUniverseRewardCoins') ? $input->int('defaultUniverseRewardCoins') : null;
        $fee = $input->has('marketFeePercent') ? $input->int('marketFeePercent') : null;
        $this->validator->assertInput($input);

        $settings = $this->settings();
        $before = $this->snapshot($settings);
        if (null !== $reward) {
            $settings->setDefaultUniverseRewardCoins($reward);
        }
        if (null !== $fee) {
            $settings->setMarketFeePercent($fee);
        }
        $this->validator->assertEntity($settings);

        // the singleton row may be missing: the defaults apply until the first save
        if (!$this->entityManager->contains($settings)) {
            $this->entityManager->persist($settings);
        }
        $this->entityManager->flush();

        $this->audit->record('settings', ManageApiAudit::diff($before, $this->snapshot($settings)));
        $this->caches->afterWrite();

        return $settings;
    }

    /**
     * @return array<string, FeatureFlag|null> feature key => its row (null while never saved = OFF)
     */
    public function features(): array
    {
        $flags = [];
        foreach ($this->featureFlagRepository->findAll() as $flag) {
            $flags[$flag->getName()] = $flag;
        }

        $result = [];
        foreach (FeatureEnum::cases() as $feature) {
            $result[$feature->value] = $flags[$feature->value] ?? null;
        }

        return $result;
    }

    public function setFeature(FeatureEnum $feature, ApiInput $input): FeatureFlag
    {
        $input->allowOnly(['enabled']);
        if (!$input->has('enabled')) {
            $input->error('enabled', 'Ce champ est requis.');
        }
        $enabled = $input->has('enabled') ? $input->bool('enabled') : null;
        $this->validator->assertInput($input);

        $flag = $this->featureFlagRepository->find($feature->value);
        $was = $flag?->isEnabled() ?? false;
        if (!$flag instanceof FeatureFlag) {
            $flag = new FeatureFlag($feature);
            $this->entityManager->persist($flag);
        }
        $flag->setEnabled((bool) $enabled);
        $this->entityManager->flush();

        $this->audit->record('feature:' . $feature->value, ManageApiAudit::diff(['enabled' => $was], ['enabled' => $flag->isEnabled()]));
        $this->caches->afterWrite();

        return $flag;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CoinSettings $settings): array
    {
        return [
            'defaultUniverseRewardCoins' => $settings->getDefaultUniverseRewardCoins(),
            'marketFeePercent' => $settings->getMarketFeePercent(),
        ];
    }
}
