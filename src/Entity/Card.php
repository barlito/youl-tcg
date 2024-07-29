<?php

namespace App\Entity;

use App\Repository\CardRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: CardRepository::class)]
class Card
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column]
    private bool $uniqueFlag = false;

    #[ORM\ManyToOne(inversedBy: 'cards')]
    private ?Extension $extension;

    #[Vich\UploadableField(mapping: 'cards', fileNameProperty: 'imageName')]
    private File $imageFile;

    #[ORM\Column(type: 'string', length: 255)]
    private string $imageName;

    #[Vich\UploadableField(mapping: 'masks', fileNameProperty: 'imageMaskName')]
    private ?File $imageMaskFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageMaskName = null;

    #[Vich\UploadableField(mapping: 'foils', fileNameProperty: 'imageFoilName')]
    private ?File $imageFoilFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageFoilName = null;

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

    public function setUnique(bool $uniqueFlag): static
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
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     *
     * @param File|null $imageMaskFile
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
     *
     * @param File|null $imageFoilFile
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

    public function setImageFoilName(?string $imageFoilFile): void
    {
        $this->imageFoilFile = $imageFoilFile;
    }

    public function getImageFoilName(): ?string
    {
        return $this->imageFoilFile;
    }
}
