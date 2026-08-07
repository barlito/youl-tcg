<?php

declare(strict_types=1);

namespace App\Entity\Traits;

use App\Dto\VisualConfig;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\CardFrameEnum;
use App\Enum\Card\CardNameFontEnum;
use App\Enum\Card\FoilTextureEnum;

/**
 * Virtual back-office properties over a VisualConfig JSON column: one widget
 * per key so the admin never edits raw JSON. Every setter merges into the
 * stored configuration instead of replacing it, and an empty widget always
 * resolves to null — "not set", i.e. inherit the next level of the cascade
 * (card override -> extension config -> system default).
 *
 * Shared by Extension (set-wide defaults) and Card (per-card overrides); the
 * host entity only has to expose its own JSON column through the two adapters.
 */
trait HasVisualConfigTrait
{
    public function getHoloEffect(): ?CardEffectEnum
    {
        return $this->readVisualConfig()->holoEffect;
    }

    public function setHoloEffect(?CardEffectEnum $holoEffect): static
    {
        return $this->mergeVisualConfig(['holoEffect' => $holoEffect?->value]);
    }

    public function getFoilTexture(): ?FoilTextureEnum
    {
        return $this->readVisualConfig()->foilTexture;
    }

    public function setFoilTexture(?FoilTextureEnum $foilTexture): static
    {
        return $this->mergeVisualConfig(['foilTexture' => $foilTexture?->value]);
    }

    /**
     * A range input has no empty state, so the slider's leftmost position
     * (FOIL_SIZE_AUTO, below the valid range) is the "not set" sentinel — see
     * the field help in App\Admin\VisualConfigFields.
     */
    public function getFoilSize(): int
    {
        return $this->readVisualConfig()->foilSize ?? VisualConfig::FOIL_SIZE_AUTO;
    }

    public function setFoilSize(?int $foilSize): static
    {
        return $this->mergeVisualConfig(['foilSize' => $foilSize]);
    }

    public function getGlowColor(): ?string
    {
        return $this->readVisualConfig()->glow;
    }

    public function setGlowColor(?string $glowColor): static
    {
        return $this->mergeVisualConfig(['glow' => $glowColor]);
    }

    public function getBorderColor(): ?string
    {
        return $this->readVisualConfig()->borderColor;
    }

    public function setBorderColor(?string $borderColor): static
    {
        return $this->mergeVisualConfig(['borderColor' => $borderColor]);
    }

    public function getCssClass(): ?string
    {
        return $this->readVisualConfig()->cssClass;
    }

    public function setCssClass(?string $cssClass): static
    {
        return $this->mergeVisualConfig(['cssClass' => $cssClass]);
    }

    public function getFrame(): ?CardFrameEnum
    {
        return $this->readVisualConfig()->frame;
    }

    public function setFrame(?CardFrameEnum $frame): static
    {
        return $this->mergeVisualConfig(['frame' => $frame?->value]);
    }

    public function getNameFont(): ?CardNameFontEnum
    {
        return $this->readVisualConfig()->nameFont;
    }

    public function setNameFont(?CardNameFontEnum $nameFont): static
    {
        return $this->mergeVisualConfig(['nameFont' => $nameFont?->value]);
    }

    public function getFrameLineStart(): ?string
    {
        return $this->readVisualConfig()->frameLineStart;
    }

    public function setFrameLineStart(?string $frameLineStart): static
    {
        return $this->mergeVisualConfig(['frameLineStart' => $frameLineStart]);
    }

    public function getFrameLineEnd(): ?string
    {
        return $this->readVisualConfig()->frameLineEnd;
    }

    public function setFrameLineEnd(?string $frameLineEnd): static
    {
        return $this->mergeVisualConfig(['frameLineEnd' => $frameLineEnd]);
    }

    abstract protected function readVisualConfig(): VisualConfig;

    abstract protected function writeVisualConfig(VisualConfig $visualConfig): void;

    /**
     * @param array<string, string|int|null> $changes
     */
    private function mergeVisualConfig(array $changes): static
    {
        $this->writeVisualConfig($this->readVisualConfig()->merge($changes));

        return $this;
    }
}
