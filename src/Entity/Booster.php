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
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: BoosterRepository::class)]
class Booster implements \Stringable
{
    use IdUuidTrait;
    use TimestampableEntity;

    /**
     * Optional display name ("Pack Full Rare", named after its drop rates…);
     * null falls back to the extension name everywhere.
     */
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /**
     * Whether the booster can be claimed for free on the hub. A non-claimable
     * booster is distributed another way (event, code…) and only shows up as
     * openable for users who already own copies.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $claimable = true;

    /**
     * One slot per card the booster yields. Each slot carries its own rarity
     * weight map (keys are CardRarityEnum values) and its own holo chance
     * (0-100 %) rolled independently for the card drawn in that slot.
     * Example for a 2-card booster:
     * [{rarities: {common: 100}, holoChance: 5}, {rarities: {common: 60, rare: 40}, holoChance: 30}].
     *
     * @var list<array{rarities: array<string, int>, holoChance: int}>
     */
    #[ValidRarityRates]
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $rarityRates = [];

    #[Vich\UploadableField(mapping: 'boosters', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\ManyToOne(inversedBy: 'boosters')]
    #[ORM\JoinColumn(nullable: false)]
    private Extension $extension;

    public function __toString(): string
    {
        return $this->name ?? (isset($this->extension) ? $this->extension->getName() . ' Booster' : 'Booster');
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = null !== $name && '' !== trim($name) ? trim($name) : null;

        return $this;
    }

    /**
     * What the player sees: the booster's own name, else its extension's.
     */
    public function getDisplayName(): string
    {
        return $this->name ?? $this->extension->getName();
    }

    public function isClaimable(): bool
    {
        return $this->claimable;
    }

    public function setClaimable(bool $claimable): static
    {
        $this->claimable = $claimable;

        return $this;
    }

    /**
     * @return list<array{rarities: array<string, int>, holoChance: int}>
     */
    public function getRarityRates(): array
    {
        return $this->rarityRates;
    }

    /**
     * @param list<array{rarities: array<string, int>, holoChance: int}> $rarityRates
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

    /**
     * Player-facing drop rates: per slot, the rarity weights normalised to
     * percentages (1 decimal) plus the slot's holo chance. Pure projection of
     * rarityRates — nothing new is stored.
     *
     * @return list<array{rates: array<string, float>, holoChance: int}>
     */
    public function getDropRates(): array
    {
        $slots = [];

        foreach ($this->rarityRates as $slot) {
            $total = array_sum($slot['rarities']);
            $rates = [];

            foreach ($slot['rarities'] as $rarity => $weight) {
                $rates[(string) $rarity] = $total > 0 ? round($weight / $total * 100, 1) : 0.0;
            }

            $slots[] = ['rates' => $rates, 'holoChance' => $slot['holoChance']];
        }

        return $slots;
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
