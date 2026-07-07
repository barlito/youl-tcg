<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\VisualConfig;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\ExtensionRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: ExtensionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_extension_slug', columns: ['slug'])]
class Extension implements \Stringable
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    /**
     * URL-friendly slug generated from the name (Gedmo), used as a clean route
     * param for the collection / extension pages instead of the raw UUID.
     */
    #[Gedmo\Slug(fields: ['name'])]
    #[ORM\Column(length: 255)]
    private string $slug;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(options: ['default' => ExtensionStatusEnum::DRAFT])]
    private ExtensionStatusEnum $status = ExtensionStatusEnum::DRAFT;

    #[Vich\UploadableField(mapping: 'extensions', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageName = null;

    /**
     * Default holo foil texture for the extension's cards (cascade fallback
     * when a card has no foil of its own).
     */
    #[Vich\UploadableField(mapping: 'foils', fileNameProperty: 'imageFoilName')]
    private ?File $imageFoilFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageFoilName = null;

    /**
     * Default holo mask for the extension's cards (cascade fallback).
     */
    #[Vich\UploadableField(mapping: 'masks', fileNameProperty: 'imageMaskName')]
    private ?File $imageMaskFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageMaskName = null;

    /**
     * Default visual configuration (glow / border / css class / holo preset)
     * applied to the extension's cards, each card may override individual
     * fields.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $visualConfig = [];

    /**
     * @var Collection<int, Card>
     */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'extension')]
    private Collection $cards;

    /**
     * @var Collection<int, Booster>
     */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: Booster::class, mappedBy: 'extension')]
    private Collection $boosters;

    public function __construct()
    {
        $this->cards = new ArrayCollection();
        $this->boosters = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
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

    public function getStatus(): ExtensionStatusEnum
    {
        return $this->status;
    }

    public function setStatus(ExtensionStatusEnum $status): Extension
    {
        $this->status = $status;

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

    public function setImageFoilFile(?File $imageFoilFile = null): void
    {
        $this->imageFoilFile = $imageFoilFile;

        if ($imageFoilFile instanceof File) {
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

    public function setImageMaskFile(?File $imageMaskFile = null): void
    {
        $this->imageMaskFile = $imageMaskFile;

        if ($imageMaskFile instanceof File) {
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

    public function getVisualConfig(): VisualConfig
    {
        return VisualConfig::fromArray($this->visualConfig);
    }

    public function setVisualConfig(VisualConfig $visualConfig): static
    {
        $this->visualConfig = $visualConfig->toArray();

        return $this;
    }

    public function getVisualConfigJson(): string
    {
        return json_encode($this->visualConfig, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }

    /**
     * Invalid JSON resolves to an empty configuration (no override).
     */
    public function setVisualConfigJson(string $json): void
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        $this->visualConfig = VisualConfig::fromArray(\is_array($decoded) ? $decoded : [])->toArray();
    }

    /**
     * Virtual field for the back office: the holo preset stored inside the
     * visual config JSON, exposed as a selectable enum.
     */
    public function getHoloEffect(): ?CardEffectEnum
    {
        return $this->getVisualConfig()->holoEffect;
    }

    /**
     * Merges the chosen preset into the existing visual config without
     * clobbering the other keys (glow, borderColor, ...).
     */
    public function setHoloEffect(?CardEffectEnum $holoEffect): static
    {
        $this->visualConfig = array_filter(
            array_merge($this->visualConfig, ['holoEffect' => $holoEffect?->value]),
            static fn (mixed $value): bool => null !== $value,
        );

        return $this;
    }

    /**
     * @return Collection<int, Card>
     */
    public function getCards(): Collection
    {
        return $this->cards;
    }

    public function addCard(Card $card): static
    {
        if (!$this->cards->contains($card)) {
            $this->cards->add($card);
            $card->setExtension($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Booster>
     */
    public function getBoosters(): Collection
    {
        return $this->boosters;
    }

    public function addBooster(Booster $booster): static
    {
        if (!$this->boosters->contains($booster)) {
            $this->boosters->add($booster);
            $booster->setExtension($this);
        }

        return $this;
    }
}
