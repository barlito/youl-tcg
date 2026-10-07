<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeckRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Duel deck: 12 distinct cards (a set, the game shuffles) + an optional terrain; ownership is recomputed on read.
 */
#[ORM\Entity(repositoryClass: DeckRepository::class)]
class Deck
{
    use IdUuidTrait;
    use TimestampableEntity;

    public const int SIZE = 12;

    public const int NAME_MAX_LENGTH = 40;

    /**
     * @var Collection<int, Card>
     */
    #[ORM\ManyToMany(targetEntity: Card::class)]
    #[ORM\JoinTable(name: 'deck_card')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $cards;

    /**
     * @param list<Card> $cards
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'discord_id', nullable: false, onDelete: 'CASCADE')]
        private DiscordUser $owner,
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        private string $name,
        array $cards,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Card $terrain = null,
    ) {
        $this->cards = new ArrayCollection();
        $this->replaceCards($cards);
    }

    public function getOwner(): DiscordUser
    {
        return $this->owner;
    }

    public function isOwnedBy(DiscordUser $user): bool
    {
        return $this->owner->getDiscordId() === $user->getDiscordId();
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<Card>
     */
    public function getCards(): array
    {
        return array_values($this->cards->toArray());
    }

    /**
     * @return list<string> sorted card ids
     */
    public function getCardIds(): array
    {
        $ids = array_map(static fn (Card $card): string => (string) $card->getId(), $this->getCards());
        sort($ids);

        return $ids;
    }

    public function getTerrain(): ?Card
    {
        return $this->terrain;
    }

    /**
     * @param list<Card> $cards
     */
    public function update(string $name, array $cards, ?Card $terrain): void
    {
        $this->name = $name;
        $this->replaceCards($cards);
        $this->terrain = $terrain;
        // a cards-only change leaves no field changeset: Timestampable would not bump it
        $this->updatedAt = new \DateTime();
    }

    /**
     * @param list<Card> $cards
     */
    private function replaceCards(array $cards): void
    {
        $this->cards->clear();
        foreach ($cards as $card) {
            if (!$this->cards->contains($card)) {
                $this->cards->add($card);
            }
        }
    }
}
