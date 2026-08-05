<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Enum\Entity\CardRarityEnum;
use App\Twig\CardRarityExtension;
use PHPUnit\Framework\TestCase;

final class CardRarityExtensionTest extends TestCase
{
    public function testCardRaritiesExposesTheAscendingScale(): void
    {
        $this->assertSame(CardRarityEnum::ascending(), new CardRarityExtension()->cardRarities());
    }

    public function testRarityLabelAcceptsEnumsAndRawStrings(): void
    {
        $extension = new CardRarityExtension();

        $this->assertSame('Légendaire', $extension->rarityLabel(CardRarityEnum::LEGENDARY));
        $this->assertSame('Peu commune', $extension->rarityLabel('uncommon'));
        // unknown JSON keys fall back to the raw value instead of crashing the template
        $this->assertSame('mythic', $extension->rarityLabel('mythic'));
    }
}
