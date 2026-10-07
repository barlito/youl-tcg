<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Dto\VisualConfig;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\CoinSettings;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\ExtensionBanner;
use App\Entity\FeatureFlag;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\FeatureEnum;
use App\Repository\UserCardRepository;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * Full state of the resources returned by the management API.
 */
final readonly class ManageApiPresenter
{
    public function __construct(
        private UploaderHelper $uploader,
        private UserCardRepository $userCardRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function extension(Extension $extension): array
    {
        $cards = $extension->getCards();
        $published = $cards->filter(static fn (Card $card): bool => CardStatusEnum::PUBLISHED === $card->getStatus())->count();

        return [
            'id' => $extension->getId(),
            'name' => $extension->getName(),
            'slug' => $extension->getSlug(),
            'description' => $extension->getDescription(),
            'status' => $extension->getStatus()->name,
            'upcoming' => $extension->isUpcoming(),
            'completionRewardCoins' => $extension->getCompletionRewardCoins(),
            'visualConfig' => $this->config($extension->getVisualConfig()),
            'imageName' => $extension->getImageName(),
            'imageUrl' => $this->uploader->asset($extension, 'imageFile'),
            'logoName' => $extension->getLogoName(),
            'logoUrl' => $this->uploader->asset($extension, 'logoFile'),
            'cardCount' => $cards->count(),
            'publishedCardCount' => $published,
            'draftCardCount' => $cards->count() - $published,
            'boosters' => array_map(
                static fn (Booster $booster): array => ['id' => $booster->getId(), 'name' => $booster->getDisplayName(), 'claimable' => $booster->isClaimable(), 'purchasable' => $booster->isPurchasable()],
                $extension->getBoosters()->toArray(),
            ),
            'banners' => array_map($this->banner(...), $extension->getBanners()->toArray()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Card $card): array
    {
        $extension = $card->getExtension();
        $holder = $card->getClaimedBy();

        return [
            'id' => $card->getId(),
            'name' => $card->getName(),
            'description' => $card->getDescription(),
            'rarity' => $card->getRarity()->value,
            'status' => $card->getStatus()->name,
            'unique' => $card->isUnique(),
            'alwaysHolo' => $card->isAlwaysHolo(),
            'claimedBy' => $holder instanceof DiscordUser ? ['discordId' => $holder->getDiscordId(), 'username' => (string) $holder] : null,
            'holders' => $this->userCardRepository->countHolders($card),
            'extension' => $extension instanceof Extension ? ['id' => $extension->getId(), 'slug' => $extension->getSlug(), 'name' => $extension->getName()] : null,
            'visualConfigOverride' => $this->config($card->getVisualConfigOverride()),
            'imageName' => $card->getImageName(),
            'imageUrl' => $this->uploader->asset($card, 'imageFile'),
            'imageMaskName' => $card->getImageMaskName(),
            'imageMaskUrl' => $this->uploader->asset($card, 'imageMaskFile'),
            'imageFoilName' => $card->getImageFoilName(),
            'imageFoilUrl' => $this->uploader->asset($card, 'imageFoilFile'),
            'createdAt' => $card->getCreatedAt()?->format(\DATE_ATOM),
            'updatedAt' => $card->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function booster(Booster $booster): array
    {
        $extension = $booster->getExtension();

        return [
            'id' => $booster->getId(),
            'name' => $booster->getName(),
            'displayName' => $booster->getDisplayName(),
            'extension' => ['id' => $extension->getId(), 'slug' => $extension->getSlug(), 'name' => $extension->getName()],
            'claimable' => $booster->isClaimable(),
            'purchasable' => $booster->isPurchasable(),
            'purchasePrice' => $booster->getPurchasePrice(),
            'cardCount' => $booster->getCardCount(),
            'rarityRates' => array_map(
                static fn (array $slot): array => [
                    'rarities' => (object) $slot['rarities'],
                    'holoChance' => $slot['holoChance'],
                    'uniqueChance' => $slot['uniqueChance'] ?? 0,
                ],
                $booster->getRarityRates(),
            ),
            'imageName' => $booster->getImageName(),
            'imageUrl' => $this->uploader->asset($booster, 'imageFile'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function banner(ExtensionBanner $banner): array
    {
        return [
            'id' => $banner->getId(),
            'position' => $banner->getPosition(),
            'imageName' => $banner->getImageName(),
            'imageUrl' => $this->uploader->asset($banner, 'imageFile'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(CoinSettings $settings): array
    {
        return [
            'defaultUniverseRewardCoins' => $settings->getDefaultUniverseRewardCoins(),
            'marketFeePercent' => $settings->getMarketFeePercent(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function feature(FeatureEnum $feature, ?FeatureFlag $flag): array
    {
        return [
            'key' => $feature->value,
            'label' => $feature->label(),
            'enabled' => $flag?->isEnabled() ?? false,
            'updatedAt' => $flag?->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }

    private function config(VisualConfig $config): \stdClass
    {
        return (object) $config->toArray();
    }
}
