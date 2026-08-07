<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\VisualConfig;
use App\Entity\Traits\HasVisualConfigTrait;
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
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: ExtensionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_extension_slug', columns: ['slug'])]
class Extension implements \Stringable
{
    use HasVisualConfigTrait;
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
     * Wordmark/logo of the universe (transparent PNG/SVG), shown top-right on
     * the CSS card frame; without it the frame falls back to the extension
     * name in styled text.
     */
    #[Vich\UploadableField(mapping: 'extension_logos', fileNameProperty: 'logoName')]
    private ?File $logoFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logoName = null;

    /**
     * Default visual configuration (glow / border / css class / holo preset)
     * applied to the extension's cards, each card may override individual
     * fields.
     *
     * @var array<string, string|int>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $visualConfig = [];

    /**
     * Hero banners of the universe page, position ascending (carousel order).
     *
     * @var Collection<int, ExtensionBanner>
     */
    #[ORM\OneToMany(targetEntity: ExtensionBanner::class, mappedBy: 'extension')]
    #[ORM\OrderBy(['position' => 'ASC', 'createdAt' => 'ASC'])]
    private Collection $banners;

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
        $this->banners = new ArrayCollection();
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

    public function setLogoFile(?File $logoFile = null): void
    {
        $this->logoFile = $logoFile;

        if ($logoFile instanceof File) {
            $this->updatedAt = new \DateTime();
        }
    }

    public function getLogoFile(): ?File
    {
        return $this->logoFile;
    }

    public function setLogoName(?string $logoName): void
    {
        $this->logoName = $logoName;
    }

    public function getLogoName(): ?string
    {
        return $this->logoName;
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
     * Fixtures / imports only — the back office edits dedicated widgets.
     *
     * @throws \JsonException malformed JSON is rejected instead of resolving to
     *                        an empty configuration and silently wiping every key
     */
    public function setVisualConfigJson(string $json): void
    {
        $this->visualConfig = VisualConfig::fromJson($json)->toArray();
    }

    protected function readVisualConfig(): VisualConfig
    {
        return $this->getVisualConfig();
    }

    protected function writeVisualConfig(VisualConfig $visualConfig): void
    {
        $this->setVisualConfig($visualConfig);
    }

    /**
     * @return Collection<int, ExtensionBanner>
     */
    public function getBanners(): Collection
    {
        return $this->banners;
    }

    public function addBanner(ExtensionBanner $banner): static
    {
        if (!$this->banners->contains($banner)) {
            $this->banners->add($banner);
            $banner->setExtension($this);
        }

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
