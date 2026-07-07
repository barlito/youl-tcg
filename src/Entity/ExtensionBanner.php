<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExtensionBannerRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * A hero banner of an extension's universe page. An extension can have
 * several: the page shows them as a carousel, ordered by position.
 */
#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: ExtensionBannerRepository::class)]
class ExtensionBanner implements \Stringable
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\ManyToOne(targetEntity: Extension::class, inversedBy: 'banners')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Extension $extension;

    #[Vich\UploadableField(mapping: 'banners', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imageName = null;

    /**
     * Display order in the carousel (lowest first).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __toString(): string
    {
        return \sprintf('%s — bannière %d', $this->extension->getName(), $this->position);
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
     * of 'UploadedFile' is injected into this setter to trigger the update.
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
