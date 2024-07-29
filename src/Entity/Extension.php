<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExtensionRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: ExtensionRepository::class)]
class Extension
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $description;

    #[Vich\UploadableField(mapping: 'cards', fileNameProperty: 'imageName')]
    private File $imageFile;

    #[ORM\Column(type: 'string', length: 255)]
    private string $imageName;

    #[ORM\OneToMany(mappedBy: 'extension', targetEntity: Card::class)]
    private Collection $cards;

    #[ORM\OneToMany(mappedBy: 'extension', targetEntity: Booster::class)]
    private Collection $boosters;

    public function __construct()
    {
        $this->cards = new ArrayCollection();
        $this->boosters = new ArrayCollection();
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

    public function removeCard(Card $card): static
    {
        if ($this->cards->removeElement($card)) {
            // set the owning side to null (unless already changed)
            if ($card->getExtension() === $this) {
                $card->setExtension(null);
            }
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

    public function removeBooster(Booster $booster): static
    {
        if ($this->boosters->removeElement($booster)) {
            // set the owning side to null (unless already changed)
            if ($booster->getExtension() === $this) {
                $booster->setExtension(null);
            }
        }

        return $this;
    }
}
