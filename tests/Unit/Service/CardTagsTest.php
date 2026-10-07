<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Card;
use App\Service\Card\CardTags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardTagsTest extends TestCase
{
    public function testNormalizeTrimsLowercasesDeduplicatesAndSorts(): void
    {
        $this->assertSame(
            ['character:benj', 'family:linette', 'trait:machine'],
            CardTags::normalize([' trait:machine', 'Character:Benj', 'character:benj', '', 42, 'family:linette']),
        );
    }

    public function testTheCardStoresNormalizedTags(): void
    {
        $card = new Card()->setTags(['Trait:Machine', 'character:benj', 'trait:machine']);

        $this->assertSame(['character:benj', 'trait:machine'], $card->getTags());
        $this->assertTrue($card->hasTag('trait:machine'));
        $this->assertFalse($card->hasTag('trait:epee'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tags(): iterable
    {
        yield 'character' => ['character:benj', true];
        yield 'dashed value' => ['character:babou-linette', true];
        yield 'digits' => ['trait:t800', true];
        yield 'implicit universe' => ['universe:kda', false];
        yield 'no family' => ['benj', false];
        yield 'uppercase' => ['Character:Benj', false];
        yield 'space' => ['character:benj linette', false];
        yield 'empty value' => ['character:', false];
        yield 'two colons' => ['a:b:c', false];
    }

    #[DataProvider('tags')]
    public function testIsValid(string $tag, bool $valid): void
    {
        $this->assertSame($valid, CardTags::isValid($tag));
    }

    public function testLabels(): void
    {
        $this->assertSame('Personnages', CardTags::familyLabel('character'));
        $this->assertSame('Faction', CardTags::familyLabel('faction'));
        $this->assertSame('Babou linette', CardTags::valueLabel('character:babou-linette'));
        $families = ['trait', 'faction', 'character', 'family'];
        usort($families, CardTags::compareFamilies(...));
        $this->assertSame(['character', 'family', 'trait', 'faction'], $families);
    }
}
