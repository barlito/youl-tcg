<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CoinSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CoinSettingsRepository::class)]
class CoinSettings implements \Stringable
{
    public const int ID = 1;
    public const int DEFAULT_UNIVERSE_REWARD_COINS = 500;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    #[Assert\PositiveOrZero]
    #[ORM\Column(options: ['default' => self::DEFAULT_UNIVERSE_REWARD_COINS])]
    private int $defaultUniverseRewardCoins = self::DEFAULT_UNIVERSE_REWARD_COINS;

    public function __toString(): string
    {
        return 'Réglages coin';
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDefaultUniverseRewardCoins(): int
    {
        return $this->defaultUniverseRewardCoins;
    }

    public function setDefaultUniverseRewardCoins(int $defaultUniverseRewardCoins): static
    {
        $this->defaultUniverseRewardCoins = $defaultUniverseRewardCoins;

        return $this;
    }
}
