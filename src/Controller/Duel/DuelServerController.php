<?php

declare(strict_types=1);

namespace App\Controller\Duel;

use App\Attribute\RequiresFeature;
use App\Entity\Deck;
use App\Entity\DiscordUser;
use App\Enum\FeatureEnum;
use App\Repository\DeckRepository;
use App\Repository\DiscordUserRepository;
use App\Service\Duel\DeckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-to-server API of the duel game server (bearer DUEL_SERVER_TOKEN, firewall duel_server).
 */
#[RequiresFeature(FeatureEnum::DUEL)]
#[Route('/api/duel/server', format: 'json')]
class DuelServerController extends AbstractController
{
    public function __construct(
        private readonly DeckService $deckService,
        private readonly DeckRepository $deckRepository,
        private readonly DiscordUserRepository $discordUserRepository,
    ) {
    }

    /**
     * The deck checked against the player's collection at this very moment, as the game must play it.
     */
    #[Route('/decks/{id}', name: 'api_duel_server_deck', methods: ['GET'])]
    public function deck(string $id, Request $request): JsonResponse
    {
        $discordId = $request->query->getString('player');
        if ('' === $discordId) {
            return $this->json(['error' => 'Paramètre player (discordId) requis.'], Response::HTTP_BAD_REQUEST);
        }

        $player = $this->discordUserRepository->find($discordId);
        $deck = $player instanceof DiscordUser ? $this->deckRepository->findOneOwnedBy($id, $player) : null;
        if (!$deck instanceof Deck) {
            return $this->json(['error' => 'Deck introuvable pour ce joueur.'], Response::HTTP_NOT_FOUND);
        }

        $validity = $this->deckService->validity($deck);
        if (!$validity->isValid()) {
            return $this->json([
                'error' => 'Deck incomplet : il ne correspond plus à la collection du joueur.',
                'missingCards' => $validity->missingCards,
                'issues' => $validity->issues,
            ], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id' => (string) $deck->getId(),
            'name' => $deck->getName(),
            'cards' => $deck->getCardIds(),
            'terrain' => $deck->getTerrain()?->getId(),
        ]);
    }
}
