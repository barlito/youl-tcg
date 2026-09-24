<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeatureEnum;
use App\Repository\FeatureFlagRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * On/off switch of a FeatureEnum feature. A missing row means OFF.
 */
#[ORM\Entity(repositoryClass: FeatureFlagRepository::class)]
class FeatureFlag implements \Stringable
{
    use TimestampableEntity;

    /**
     * FeatureEnum value, kept as a plain string id (admin urls, missing cases).
     */
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    public function __construct(
        FeatureEnum $feature,
        #[ORM\Column(options: ['default' => false])]
        private bool $enabled = false,
    ) {
        $this->name = $feature->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->getLabel();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFeature(): ?FeatureEnum
    {
        return FeatureEnum::tryFrom($this->name);
    }

    public function getLabel(): string
    {
        return $this->getFeature()?->label() ?? $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
