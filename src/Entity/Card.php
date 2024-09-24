<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CardRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\String\Slugger\AsciiSlugger;
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

    #[ORM\Column]
    private bool $uniqueFlag = false;

    #[Assert\Valid]
    #[Assert\NotBlank]
    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'cards')]
    private ?Extension $extension;

    #[Vich\UploadableField(mapping: 'cards', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $imageName = null;

    #[Vich\UploadableField(mapping: 'masks', fileNameProperty: 'imageMaskName')]
    private ?File $imageMaskFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageMaskName = null;

    #[Vich\UploadableField(mapping: 'foils', fileNameProperty: 'imageFoilName')]
    private ?File $imageFoilFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageFoilName = null;

    public function getSlug(): string
    {
        return (new AsciiSlugger())->slug($this->name)->toString();
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

    public function isUnique(): bool
    {
        return $this->uniqueFlag;
    }

    public function setIsUnique(bool $uniqueFlag): static
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
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     */
    public function setMaskImageFile(?File $imageMaskFile = null): void
    {
        $this->imageMaskFile = $imageMaskFile;

        if (null !== $imageMaskFile) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updatedAt = new \DateTimeImmutable();
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
    public function setFoilImageFile(?File $imageFoilFile = null): void
    {
        $this->imageMaskFile = $imageFoilFile;

        if (null !== $imageFoilFile) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updatedAt = new \DateTimeImmutable();
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
}
