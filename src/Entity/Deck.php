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
     * The cards the player chose to play in their holo copy (a subset of the cards; ownership is rechecked on read).
     *
     * @var Collection<int, Card>
     */
    #[ORM\ManyToMany(targetEntity: Card::class)]
    #[ORM\JoinTable(name: 'deck_holo_card')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $holoCards;

    /**
     * What the last reconciliation with the collection changed, until the player acknowledges it or saves the deck.
     *
     * @var list<array{card: string, name: string, type: string, at: string}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $changes = [];

    /**
     * @param list<Card> $cards
     * @param list<Card> $holoCards
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
        array $holoCards = [],
    ) {
        $this->cards = new ArrayCollection();
        $this->holoCards = new ArrayCollection();
        $this->replaceCards($cards, $holoCards);
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

    /**
     * @return list<string> sorted ids of the cards the player chose to play holo (the choice, not the ownership)
     */
    public function getHoloCardIds(): array
    {
        $ids = array_map(static fn (Card $card): string => (string) $card->getId(), array_values($this->holoCards->toArray()));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<array{card: string, name: string, type: string, at: string}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * @param list<array{card: string, name: string, type: string, at: string}> $changes
     */
    public function recordChanges(array $changes): void
    {
        $this->changes = [...$this->changes, ...$changes];
    }

    public function acknowledgeChanges(): void
    {
        $this->changes = [];
    }

    public function hasCard(Card $card): bool
    {
        return $this->cards->contains($card);
    }

    /**
     * The player lost the last copy of a card: it leaves the deck at once (the deck becomes incomplete).
     */
    public function removeCard(Card $card): void
    {
        $this->cards->removeElement($card);
        $this->holoCards->removeElement($card);
        $this->updatedAt = new \DateTime();
    }

    /**
     * Switches the kind played for a card of the deck (no-op when it already matches).
     */
    public function setHolo(Card $card, bool $holo): void
    {
        if (!$this->cards->contains($card) || $this->holoCards->contains($card) === $holo) {
            return;
        }
        $holo ? $this->holoCards->add($card) : $this->holoCards->removeElement($card);
        $this->updatedAt = new \DateTime();
    }

    public function getTerrain(): ?Card
    {
        return $this->terrain;
    }

    /**
     * @param list<Card> $cards
     * @param list<Card> $holoCards
     */
    public function update(string $name, array $cards, ?Card $terrain, array $holoCards = []): void
    {
        $this->name = $name;
        $this->replaceCards($cards, $holoCards);
        $this->terrain = $terrain;
        // saving the deck settles whatever the player was told about
        $this->changes = [];
        // a cards-only change leaves no field changeset: Timestampable would not bump it
        $this->updatedAt = new \DateTime();
    }

    /**
     * @param list<Card> $cards
     * @param list<Card> $holoCards
     */
    private function replaceCards(array $cards, array $holoCards): void
    {
        $this->cards->clear();
        $this->holoCards->clear();
        foreach ($cards as $card) {
            if (!$this->cards->contains($card)) {
                $this->cards->add($card);
            }
        }
        foreach ($holoCards as $card) {
            if ($this->cards->contains($card) && !$this->holoCards->contains($card)) {
                $this->holoCards->add($card);
            }
        }
    }
}
