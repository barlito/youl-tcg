<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\Card\CardEffectEnum;
use PHPUnit\Framework\TestCase;

final class CardEffectEnumTest extends TestCase
{
    public function testCssClassMapsToHoloPrefixedValue(): void
    {
        $this->assertSame('holo--cosmos', CardEffectEnum::COSMOS->cssClass());
        $this->assertSame('holo--shine', CardEffectEnum::SHINE->cssClass());
        $this->assertSame('holo--trainer', CardEffectEnum::TRAINER->cssClass());
    }

    public function testExactlyTheFourKeptPresetsExist(): void
    {
        $this->assertSame(
            ['shine', 'basic', 'cosmos', 'trainer'],
            array_map(static fn (CardEffectEnum $effect): string => $effect->value, CardEffectEnum::cases()),
        );
    }

    public function testEveryCaseHasANonEmptyLabel(): void
    {
        foreach (CardEffectEnum::cases() as $effect) {
            $this->assertNotSame('', $effect->label());
        }
    }

    public function testTryFromNameParsesValidValue(): void
    {
        $this->assertSame(CardEffectEnum::COSMOS, CardEffectEnum::tryFromName('cosmos'));
    }

    public function testTryFromNameReturnsNullForInvalidOrNull(): void
    {
        $this->assertNull(CardEffectEnum::tryFromName('not-a-preset'));
        // removed presets (pre-migration JSON) must degrade to null, not throw
        $this->assertNull(CardEffectEnum::tryFromName('rainbow'));
        $this->assertNull(CardEffectEnum::tryFromName('vstar'));
        $this->assertNull(CardEffectEnum::tryFromName(null));
        $this->assertNull(CardEffectEnum::tryFromName(''));
    }
}
