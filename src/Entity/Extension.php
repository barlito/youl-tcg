<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\ExtensionRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: ExtensionRepository::class)]
class Extension implements \Stringable
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(options: ['default' => ExtensionStatusEnum::DRAFT])]
    private ExtensionStatusEnum $status = ExtensionStatusEnum::DRAFT;

    #[Vich\UploadableField(mapping: 'extensions', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageName = null;

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
