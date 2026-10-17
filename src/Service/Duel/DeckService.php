<?php

declare(strict_types=1);

namespace App\Service\Duel;

use App\Dto\Duel\DeckValidity;
use App\Entity\Deck;
use App\Entity\DiscordUser;
use App\Entity\UserCard;
use App\Enum\FeatureEnum;
use App\Exception\Duel\DeckRefusedException;
use App\Exception\Feature\FeatureDisabledException;
use App\Repository\DeckRepository;
use App\Repository\UserCardRepository;
use App\Service\Feature\FeatureFlags;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Duel decks: stored here, checked against ownership only, never locking the economy flows.
 */
final readonly class DeckService
{
    public const int MAX_DECKS = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeckRepository $deckRepository,
        private UserCardRepository $userCardRepository,
        private DeckInputValidator $inputValidator,
        private DeckValidator $validator,
        private FeatureFlags $featureFlags,
    ) {
    }

    /**
     * @throws FeatureDisabledException
     *
     * @return array<string, UserCard> card id => row
     */
    public function collection(DiscordUser $player): array
    {
        $this->featureFlags->assertEnabled(FeatureEnum::DUEL);

        return $this->userCardRepository->findDuelCollection($player);
    }

    /**
     * @throws FeatureDisabledException
     *
     * @return list<array{deck: Deck, validity: DeckValidity}>
     */
    public function decksOf(DiscordUser $player): array
    {
        $collection = $this->collection($player);

        return array_map(
            fn (Deck $deck): array => ['deck' => $deck, 'validity' => $this->validator->check($deck, $collection)],
            $this->deckRepository->findForOwner($player),
        );
    }

    /**
     * @throws FeatureDisabledException
     */
    public function validity(Deck $deck): DeckValidity
    {
        return $this->validator->check($deck, $this->collection($deck->getOwner()));
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws DeckRefusedException
     * @throws FeatureDisabledException
     */
    public function create(DiscordUser $player, array $payload): Deck
    {
        $draft = $this->inputValidator->validate($payload, $this->collection($player));

        // refusals are returned from the closure: throwing inside would close the EntityManager
        $deck = $this->entityManager->wrapInTransaction(function () use ($player, $draft): ?Deck {
            // serializes concurrent creations of the same player against the deck cap
            $this->entityManager->find(DiscordUser::class, $player->getDiscordId(), LockMode::PESSIMISTIC_WRITE);

            if ($this->deckRepository->countForOwner($player) >= self::MAX_DECKS) {
                return null;
            }

            $deck = new Deck($player, $draft->name, $draft->cards, $draft->terrain);
            $this->entityManager->persist($deck);

            return $deck;
        });

        return $deck ?? throw DeckRefusedException::limitReached(self::MAX_DECKS);
    }

    /**
     * Full replacement: name, the 12 cards and the terrain (absent = none).
     *
     * @param array<mixed> $payload
     *
     * @throws DeckRefusedException
     * @throws FeatureDisabledException
     */
    public function update(Deck $deck, array $payload): Deck
    {
        $draft = $this->inputValidator->validate($payload, $this->collection($deck->getOwner()));

        $deck->update($draft->name, $draft->cards, $draft->terrain);
        $this->entityManager->flush();

        return $deck;
    }

    /**
     * @throws FeatureDisabledException
     */
    public function delete(Deck $deck): void
    {
        $this->featureFlags->assertEnabled(FeatureEnum::DUEL);

        $this->entityManager->remove($deck);
        $this->entityManager->flush();
    }
}
