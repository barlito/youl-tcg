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
        $this->assertSame('holo--secret', CardEffectEnum::SECRET->cssClass());
    }

    public function testEveryCaseHasANonEmptyLabel(): void
    {
        foreach (CardEffectEnum::cases() as $effect) {
            $this->assertNotSame('', $effect->label());
        }
    }

    public function testTryFromNameParsesValidValue(): void
    {
        $this->assertSame(CardEffectEnum::RAINBOW, CardEffectEnum::tryFromName('rainbow'));
    }

    public function testTryFromNameReturnsNullForInvalidOrNull(): void
    {
        $this->assertNull(CardEffectEnum::tryFromName('not-a-preset'));
        $this->assertNull(CardEffectEnum::tryFromName(null));
        $this->assertNull(CardEffectEnum::tryFromName(''));
    }
}
