<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoosterRepository::class)]
class Booster
{
    use IdUuidTrait;

    #[ORM\Column]
    private int $price;

    #[ORM\Column]
    private int $quantity;

//    #[ORM\ManyToOne(inversedBy: 'boosters')]
//    #[ORM\JoinColumn(nullable: false)]
//    private Extension $extension;

    private array $rarityRate = [];

    private array $holoRate = [];

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function setExtension(Extension $extension): static
    {
        $this->extension = $extension;

        return $this;
    }

    public function getRarityRate(): array
    {
        return $this->rarityRate;
    }

    public function setRarityRate(array $rarityRate): static
    {
        $this->rarityRate = $rarityRate;

        return $this;
    }

    public function getHoloRate(): array
    {
        return $this->holoRate;
    }

    public function setHoloRate(array $holoRate): static
    {
        $this->holoRate = $holoRate;

        return $this;
    }
}
