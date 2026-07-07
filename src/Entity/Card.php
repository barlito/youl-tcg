<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\VisualConfig;
use App\Enum\Card\FoilTextureEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: CardRepository::class)]
class Card
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(options: ['default' => CardStatusEnum::DRAFT])]
    private CardStatusEnum $status = CardStatusEnum::DRAFT;

    #[ORM\Column(options: ['default' => CardRarityEnum::COMMON])]
    private CardRarityEnum $rarity = CardRarityEnum::COMMON;

    #[ORM\Column]
    private bool $uniqueFlag = false;

    /**
     * Owner of a one-of-one (unique) card. Null while unclaimed; set atomically
     * the first time the card is drawn (CardRepository::claimUnique). A claimed
     * unique is filtered out of the draw pool so it can never be drawn again.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'claimed_by', referencedColumnName: 'discord_id', nullable: true)]
    private ?DiscordUser $claimedBy = null;

    /**
     * When true the card is always drawn holo, whatever the slot's holoChance
     * (e.g. legendaries that should always shine).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $alwaysHolo = false;

    #[Assert\Valid]
    #[Assert\NotBlank]
    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'cards')]
    private ?Extension $extension = null;

    #[Vich\UploadableField(mapping: 'cards', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageName = null;

    #[Vich\UploadableField(mapping: 'masks', fileNameProperty: 'imageMaskName')]
    private ?File $imageMaskFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageMaskName = null;

    #[Vich\UploadableField(mapping: 'foils', fileNameProperty: 'imageFoilName')]
    private ?File $imageFoilFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageFoilName = null;

    /**
     * Per-card visual overrides (glow / border / css class / holo preset);
     * each set field beats the extension's default in the resolution cascade.
     *
     * @var array<string, string|bool>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $visualConfigOverride = [];

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

    public function getStatus(): CardStatusEnum
    {
        return $this->status;
    }

    public function setStatus(CardStatusEnum $status): Card
    {
        $this->status = $status;

        return $this;
    }

    public function getRarity(): CardRarityEnum
    {
        return $this->rarity;
    }

    public function setRarity(CardRarityEnum $rarity): static
    {
        $this->rarity = $rarity;

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

    public function getClaimedBy(): ?DiscordUser
    {
        return $this->claimedBy;
    }

    public function setClaimedBy(?DiscordUser $claimedBy): static
    {
        $this->claimedBy = $claimedBy;

        return $this;
    }

    /**
     * A unique card that has already been claimed by someone.
     */
    public function isClaimed(): bool
    {
        return $this->claimedBy instanceof DiscordUser;
    }

    public function isAlwaysHolo(): bool
    {
        return $this->alwaysHolo;
    }

    public function setAlwaysHolo(bool $alwaysHolo): static
    {
        $this->alwaysHolo = $alwaysHolo;

        return $this;
    }

    public function getExtension(): Extension
    {
        \assert($this->extension instanceof Extension);

        return $this->extension;
    }

    public function setExtension(Extension $extension): static
    {
        $this->extension = $extension;

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

        $this->updatedAt = new \DateTime();
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
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     */
    public function setImageMaskFile(?File $imageMaskFile = null): void
    {
        $this->imageMaskFile = $imageMaskFile;

        if ($imageMaskFile instanceof File) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updatedAt = new \DateTime();
        }
    }

    public function getImageMaskFile(): ?File
    {
        return $this->imageMaskFile;
    }

    public function setImageMaskName(?string $imageMaskName): void
    {
        $this->imageMaskName = $imageMaskName;
    }

    public function getImageMaskName(): ?string
    {
        return $this->imageMaskName;
    }

    /**
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     */
    public function setImageFoilFile(?File $imageFoilFile = null): void
    {
        $this->imageFoilFile = $imageFoilFile;

        if ($imageFoilFile instanceof File) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updatedAt = new \DateTime();
        }
    }

    public function getImageFoilFile(): ?File
    {
        return $this->imageFoilFile;
    }

    public function setImageFoilName(?string $imageFoilName): void
    {
        $this->imageFoilName = $imageFoilName;
    }

    public function getImageFoilName(): ?string
    {
        return $this->imageFoilName;
    }

    public function getVisualConfigOverride(): VisualConfig
    {
        return VisualConfig::fromArray($this->visualConfigOverride);
    }

    public function setVisualConfigOverride(VisualConfig $visualConfig): static
    {
        $this->visualConfigOverride = $visualConfig->toArray();

        return $this;
    }

    /**
     * Virtual field for the back office: the bundled foil texture stored
     * inside the override JSON, exposed as a selectable enum.
     */
    public function getFoilTexture(): ?FoilTextureEnum
    {
        return $this->getVisualConfigOverride()->foilTexture;
    }

    /**
     * Merges the chosen texture into the existing override without clobbering
     * the other keys (glow, holoEffect, ...).
     */
    public function setFoilTexture(?FoilTextureEnum $foilTexture): static
    {
        $this->visualConfigOverride = array_filter(
            array_merge($this->visualConfigOverride, ['foilTexture' => $foilTexture?->value]),
            static fn (mixed $value): bool => null !== $value,
        );

        return $this;
    }

    public function getVisualConfigOverrideJson(): string
    {
        return json_encode($this->visualConfigOverride, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }

    /**
     * Invalid JSON resolves to no override.
     */
    public function setVisualConfigOverrideJson(string $json): void
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        $this->visualConfigOverride = VisualConfig::fromArray(\is_array($decoded) ? $decoded : [])->toArray();
    }
}
