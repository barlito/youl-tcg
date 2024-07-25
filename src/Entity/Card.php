<?php

namespace App\Entity;

use App\Repository\CardRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CardRepository::class)]
class Card
{
    use IdUuidTrait;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column]
    private bool $uniqueFlag = false;

//    #[ORM\ManyToOne(inversedBy: 'cards')]
//    private ?Extension $extension;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isUnique(): bool
    {
        return $this->uniqueFlag;
    }

    public function setUnique(bool $uniqueFlag): static
    {
        $this->uniqueFlag = $uniqueFlag;

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
}
