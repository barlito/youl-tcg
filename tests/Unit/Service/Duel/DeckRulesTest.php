<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Duel;

use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\DiscordUser;
use App\Entity\UserCard;
use App\Exception\Duel\DeckRefusedException;
use App\Service\Duel\DeckInputValidator;
use App\Service\Duel\DeckValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DeckRulesTest extends TestCase
{
    private DiscordUser $player;

    /**
     * @var array<string, UserCard>
     */
    private array $collection = [];

    /**
     * @var list<Card>
     */
    private array $cards = [];

    private Card $terrain;

    #[\Override]
    protected function setUp(): void
    {
        $this->player = new DiscordUser()->setDiscordId('1')->setUsername('Player');
        for ($i = 1; $i <= 13; ++$i) {
            $this->cards[] = $this->own(new Card()->setName('Card ' . $i));
        }
        $this->terrain = $this->own(new Card()->setName('Niveau 24')->setTerrain(true));
    }

    public function testAValidPayloadResolvesTheOwnedCards(): void
    {
        $draft = $this->validate(['name' => '  Linettes  ', 'cards' => $this->ids(12), 'terrain' => (string) $this->terrain->getId()]);

        $this->assertSame('Linettes', $draft->name);
        $this->assertCount(12, $draft->cards);
        $this->assertSame($this->terrain, $draft->terrain);
    }

    public function testTheTerrainIsOptional(): void
    {
        $this->assertNull($this->validate(['name' => 'Sans terrain', 'cards' => $this->ids(12)])->terrain);
        $this->assertNull($this->validate(['name' => 'Sans terrain', 'cards' => $this->ids(12), 'terrain' => null])->terrain);
    }

    public function testEveryRuleIsReportedPerField(): void
    {
        $ids = $this->ids(11);
        $ids[3] = 'not-a-uuid';
        $ids[5] = $ids[4];
        $ids[6] = (string) Uuid::v7();
        $ids[7] = (string) $this->terrain->getId();

        $violations = $this->violations(['name' => str_repeat('x', 41), 'cards' => $ids, 'terrain' => (string) $this->cards[0]->getId()]);

        $this->assertSame(['40 caractères maximum.'], $violations['name']);
        $this->assertSame(['Un deck contient exactement 12 cartes (reçu : 11).'], $violations['cards']);
        $this->assertSame(['Identifiant de carte invalide.'], $violations['cards[3]']);
        $this->assertSame(['Carte en double : chaque carte ne figure qu\'une fois par deck.'], $violations['cards[5]']);
        $this->assertSame(['Carte introuvable dans ta collection.'], $violations['cards[6]']);
        $this->assertSame(['« Niveau 24 » est un terrain : place-le dans le champ terrain.'], $violations['cards[7]']);
        $this->assertSame(['« Card 1 » n\'est pas un terrain.'], $violations['terrain']);
    }

    public function testMissingOrMalformedFields(): void
    {
        $violations = $this->violations(['name' => '   ', 'cards' => 'nope', 'terrain' => 12]);

        $this->assertSame(['Donne un nom à ton deck.'], $violations['name']);
        $this->assertSame(['Liste de 12 identifiants de cartes attendue.'], $violations['cards']);
        $this->assertSame(['Identifiant de terrain invalide.'], $violations['terrain']);
    }

    public function testATerrainNotOwnedIsRefusedLikeAnUnknownOne(): void
    {
        $violations = $this->violations(['name' => 'Deck', 'cards' => $this->ids(12), 'terrain' => (string) Uuid::v7()]);

        $this->assertSame(['terrain' => ['Terrain introuvable dans ta collection.']], $violations);
    }

    public function testAFullyOwnedDeckIsValid(): void
    {
        $validity = new DeckValidator()->check($this->deck(), $this->collection);

        $this->assertTrue($validity->isValid());
        $this->assertSame([], $validity->missingCards);
    }

    public function testACardThatLeftTheCollectionMakesTheDeckIncomplete(): void
    {
        $deck = $this->deck();
        unset($this->collection[(string) $this->cards[2]->getId()], $this->collection[(string) $this->terrain->getId()]);

        $validity = new DeckValidator()->check($deck, $this->collection);

        $this->assertFalse($validity->isValid());
        $expected = [(string) $this->cards[2]->getId(), (string) $this->terrain->getId()];
        $this->assertSame($expected, $validity->missingCards);
        $this->assertSame(['1 carte du deck n\'est plus dans ta collection.', 'Ton terrain n\'est plus dans ta collection.'], $validity->issues);
    }

    public function testACardTurnedIntoATerrainOrADeletedCardInvalidateTheDeck(): void
    {
        $cards = $this->cards;
        $deck = new Deck($this->player, 'Deck', \array_slice($cards, 0, 11), $this->terrain);
        $cards[0]->setTerrain(true);
        $this->terrain->setTerrain(false);

        $validity = new DeckValidator()->check($deck, $this->collection);

        $this->assertSame([], $validity->missingCards);
        $this->assertSame([
            'Il manque 1 carte : un deck en contient 12.',
            '« Card 1 » est désormais un terrain : il ne se joue plus comme carte.',
            '« Niveau 24 » n\'est plus un terrain.',
        ], $validity->issues);
    }

    private function own(Card $card): Card
    {
        $card->setId((string) Uuid::v7());
        $this->collection[(string) $card->getId()] = new UserCard()->setDiscordUser($this->player)->setCard($card)->setQuantity(1);

        return $card;
    }

    /**
     * @return list<string>
     */
    private function ids(int $count): array
    {
        return array_map(static fn (Card $card): string => (string) $card->getId(), \array_slice($this->cards, 0, $count));
    }

    private function deck(): Deck
    {
        return new Deck($this->player, 'Deck', \array_slice($this->cards, 0, 12), $this->terrain);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validate(array $payload): \App\Dto\Duel\DeckDraft
    {
        return new DeckInputValidator()->validate($payload, $this->collection);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private function violations(array $payload): array
    {
        try {
            $this->validate($payload);
        } catch (DeckRefusedException $exception) {
            $this->assertSame(422, $exception->status);

            return $exception->violations;
        }

        $this->fail('The payload should have been refused.');
    }
}
