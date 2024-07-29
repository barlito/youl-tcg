<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: BoosterRepository::class)]
class Booster
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\Column]
    private int $price;

    #[ORM\Column]
    private int $quantity;

    #[Vich\UploadableField(mapping: 'cards', fileNameProperty: 'imageName')]
    private File $imageFile;

    #[ORM\Column(type: 'string', length: 255)]
    private string $imageName;

    #[ORM\ManyToOne(inversedBy: 'boosters')]
    #[ORM\JoinColumn(nullable: false)]
    private Extension $extension;

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

    /**
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     */
    public function setImageFile(File $imageFile): void
    {
        $this->imageFile = $imageFile;

        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageName(?string $imageName): void
    {
        $this->imageName = $imageName;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
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
