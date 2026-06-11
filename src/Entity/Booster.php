<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterRepository;
use App\Validator\ValidRarityRates;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: BoosterRepository::class)]
class Booster implements \Stringable
{
    use IdUuidTrait;
    use TimestampableEntity;

    /**
     * One weight map per card slot, keys are CardRarityEnum values.
     * Example for a 3-card booster: [{common: 100}, {common: 100}, {common: 60, rare: 30, legendary: 10}].
     *
     * @var list<array<string, int>>
     */
    #[ValidRarityRates]
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $rarityRates = [];

    /**
     * Chance (0-100 %) for each drawn card to be holo.
     */
    #[Assert\Range(min: 0, max: 100)]
    #[ORM\Column(options: ['default' => 10])]
    private int $holoRate = 10;

    #[Vich\UploadableField(mapping: 'boosters', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\ManyToOne(inversedBy: 'boosters')]
    #[ORM\JoinColumn(nullable: false)]
    private Extension $extension;

    public function __toString(): string
    {
        return isset($this->extension) ? $this->extension->getName() . ' Booster' : 'Booster';
    }

    /**
     * @return list<array<string, int>>
     */
    public function getRarityRates(): array
    {
        return $this->rarityRates;
    }

    /**
     * @param list<array<string, int>> $rarityRates
     */
    public function setRarityRates(array $rarityRates): static
    {
        $this->rarityRates = $rarityRates;

        return $this;
    }

    public function getCardCount(): int
    {
        return \count($this->rarityRates);
    }

    public function getRarityRatesJson(): string
    {
        return json_encode($this->rarityRates, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }

    /**
     * Invalid JSON resolves to an empty slot list so the ValidRarityRates
     * constraint reports the error through form validation.
     */
    public function setRarityRatesJson(string $json): void
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        $this->rarityRates = \is_array($decoded) ? array_values($decoded) : [];
    }

    public function getHoloRate(): int
    {
        return $this->holoRate;
    }

    public function setHoloRate(int $holoRate): static
    {
        $this->holoRate = $holoRate;

        return $this;
    }

    /**
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     */
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;

        if ($imageFile instanceof File) {
            $this->updatedAt = new \DateTime();
        }
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
}
