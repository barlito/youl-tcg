<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\VisualConfig;
use App\Enum\Card\CardEffectEnum;
use PHPUnit\Framework\TestCase;

final class VisualConfigTest extends TestCase
{
    public function testParsesFields(): void
    {
        $config = VisualConfig::fromArray([
            'glow' => '#a435f0',
            'borderColor' => '#ff3db0',
            'cssClass' => 'special',
            'holoEffect' => 'basic',
        ]);

        $this->assertSame('#a435f0', $config->glow);
        $this->assertSame('#ff3db0', $config->borderColor);
        $this->assertSame('special', $config->cssClass);
        $this->assertSame(CardEffectEnum::BASIC, $config->holoEffect);
    }

    public function testIgnoresRemovedHoloTuningKnobs(): void
    {
        // Old JSON (pre-migration) may still carry the dropped tuning knobs:
        // they are silently ignored and never round-trip back to storage.
        $config = VisualConfig::fromArray([
            'glow' => '#fff',
            'holoIntensity' => 0.7,
            'holoSaturation' => 1.2,
            'holoGlitter' => 0.5,
        ]);

        $this->assertSame(['glow' => '#fff'], $config->toArray());
    }

    public function testToArrayKeepsOnlySetFieldsAndRoundTrips(): void
    {
        $array = (new VisualConfig(glow: '#fff', holoEffect: CardEffectEnum::COSMOS))->toArray();

        $this->assertSame(['glow' => '#fff', 'holoEffect' => 'cosmos'], $array);
        $this->assertSame(CardEffectEnum::COSMOS, VisualConfig::fromArray($array)->holoEffect);
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue((new VisualConfig())->isEmpty());
        $this->assertFalse((new VisualConfig(glow: '#fff'))->isEmpty());
        $this->assertFalse((new VisualConfig(holoEffect: CardEffectEnum::COSMOS))->isEmpty());
    }

    public function testParsesValidHoloEffect(): void
    {
        $config = VisualConfig::fromArray(['holoEffect' => 'cosmos']);

        $this->assertSame(CardEffectEnum::COSMOS, $config->holoEffect);
    }

    public function testIgnoresInvalidHoloEffect(): void
    {
        $this->assertNull(VisualConfig::fromArray(['holoEffect' => 'nope'])->holoEffect);
        // removed presets (old JSON not yet migrated) degrade to null too
        $this->assertNull(VisualConfig::fromArray(['holoEffect' => 'rainbow'])->holoEffect);
        $this->assertNull(VisualConfig::fromArray(['holoEffect' => 42])->holoEffect);
        $this->assertNull(VisualConfig::fromArray([])->holoEffect);
    }

    public function testHoloEffectRoundTripsThroughToArray(): void
    {
        $array = (new VisualConfig(holoEffect: CardEffectEnum::TRAINER))->toArray();

        $this->assertSame(['holoEffect' => 'trainer'], $array);
        $this->assertSame(CardEffectEnum::TRAINER, VisualConfig::fromArray($array)->holoEffect);
    }
}
