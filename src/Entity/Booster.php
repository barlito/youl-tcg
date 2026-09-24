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
     * Denominator of a slot's uniqueChance: per 10 000, the smallest non-zero
     * rate is 0,01 % instead of the 1 % a percentage would floor it at.
     */
    public const int UNIQUE_CHANCE_SCALE = 10_000;

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
     * weight map (keys are CardRarityEnum values), its own holo chance
     * (0-100 %) and its own one-of-one chance (uniqueChance, per
     * UNIQUE_CHANCE_SCALE), all rolled independently for the card drawn in
     * that slot. Example for a 2-card booster:
     * [{rarities: {common: 100}, holoChance: 5}, {rarities: {common: 60, rare: 40}, holoChance: 30, uniqueChance: 25}].
     *
     * uniqueChance is optional: slots stored before the option existed read as 0.
     *
     * @var list<array{rarities: array<string, int>, holoChance: int, uniqueChance?: int}>
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
     * @return list<array{rarities: array<string, int>, holoChance: int, uniqueChance?: int}>
     */
    public function getRarityRates(): array
    {
        return $this->rarityRates;
    }

    /**
     * Reindexed on the way in: the admin collection form submits the surviving
     * slots with their original keys (0, 2, 3…) once a slot is deleted, and a
     * gapped array would be stored as a JSON object instead of a list.
     *
     * @param array<array-key, array{rarities: array<string, int>, holoChance: int, uniqueChance?: int}> $rarityRates
     */
    public function setRarityRates(array $rarityRates): static
    {
        $this->rarityRates = array_values($rarityRates);

        return $this;
    }

    public function getCardCount(): int
    {
        return \count($this->rarityRates);
    }

    /**
     * Player-facing drop rates: per slot, the rarity weights normalised to
     * percentages (1 decimal), the slot's holo chance and its one-of-one
     * chance both as raw setting (uniqueChance) and as a percentage
     * (uniqueRate). Pure projection of rarityRates — nothing new is stored.
     *
     * @return list<array{rates: array<string, float>, holoChance: int, uniqueChance: int, uniqueRate: float}>
     */
    public function getDropRates(): array
    {
        $slots = [];

        foreach ($this->rarityRates as $slot) {
            $uniqueChance = $slot['uniqueChance'] ?? 0;
            $slots[] = [
                'rates' => self::toPercentages($slot['rarities']),
                'holoChance' => $slot['holoChance'],
                'uniqueChance' => $uniqueChance,
                'uniqueRate' => self::toUniquePercentage($uniqueChance),
            ];
        }

        return $slots;
    }

    /**
     * A uniqueChance setting turned into the percentage the player reads:
     * 25 per 10 000 = 0,25 %.
     */
    public static function toUniquePercentage(int $uniqueChance): float
    {
        return round($uniqueChance / self::UNIQUE_CHANCE_SCALE * 100, 2);
    }

    /**
     * Normalises a slot weight map into percentages (1 decimal), keeping the
     * weight order. Shared by getDropRates() and the admin previews.
     *
     * @param array<string, int> $weights
     *
     * @return array<string, float>
     */
    public static function toPercentages(array $weights): array
    {
        $total = array_sum($weights);
        $percentages = [];

        foreach ($weights as $rarity => $weight) {
            $percentages[(string) $rarity] = $total > 0 ? round($weight / $total * 100, 1) : 0.0;
        }

        return $percentages;
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

    /**
     * A booster being created in the back-office has no extension yet, and the
     * typed property would throw on read.
     */
    public function hasExtension(): bool
    {
        return isset($this->extension);
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
