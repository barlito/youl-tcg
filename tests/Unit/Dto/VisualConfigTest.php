<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\VisualConfig;
use PHPUnit\Framework\TestCase;

final class VisualConfigTest extends TestCase
{
    public function testParsesHoloTuningFields(): void
    {
        $config = VisualConfig::fromArray([
            'glow' => '#a435f0',
            'holoIntensity' => 0.7,
            'holoSaturation' => 1.2,
            'holoGlitter' => 0.5,
        ]);

        $this->assertSame('#a435f0', $config->glow);
        $this->assertSame(0.7, $config->holoIntensity);
        $this->assertSame(1.2, $config->holoSaturation);
        $this->assertSame(0.5, $config->holoGlitter);
    }

    public function testHoloFieldsAreClampedToSafeRanges(): void
    {
        $config = VisualConfig::fromArray([
            'holoIntensity' => 5,     // > 1
            'holoSaturation' => -2,   // < 0
            'holoGlitter' => 9,       // > 2
        ]);

        $this->assertSame(1.0, $config->holoIntensity);
        $this->assertSame(0.0, $config->holoSaturation);
        $this->assertSame(2.0, $config->holoGlitter);
    }

    public function testAcceptsNumericStringsAndIgnoresGarbage(): void
    {
        $config = VisualConfig::fromArray([
            'holoIntensity' => '0.4',
            'holoSaturation' => 'nope',
            'holoGlitter' => null,
        ]);

        $this->assertSame(0.4, $config->holoIntensity);
        $this->assertNull($config->holoSaturation);
        $this->assertNull($config->holoGlitter);
    }

    public function testToArrayKeepsOnlySetFieldsAndRoundTrips(): void
    {
        $array = (new VisualConfig(glow: '#fff', holoIntensity: 0.6))->toArray();

        $this->assertSame(['glow' => '#fff', 'holoIntensity' => 0.6], $array);
        $this->assertSame(0.6, VisualConfig::fromArray($array)->holoIntensity);
    }

    public function testIsEmptyAccountsForHoloFields(): void
    {
        $this->assertTrue((new VisualConfig())->isEmpty());
        $this->assertFalse((new VisualConfig(holoIntensity: 0.5))->isEmpty());
    }
}
