<?php

declare(strict_types=1);

namespace App\Controller\Duel;

use App\Attribute\RequiresFeature;
use App\Entity\Deck;
use App\Entity\DiscordUser;
use App\Enum\FeatureEnum;
use App\Exception\Duel\DeckRefusedException;
use App\Repository\DeckRepository;
use App\Service\Duel\DeckService;
use App\Service\Duel\DuelApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Player API of the duel game client (same domain, JWT cookie of the main firewall).
 */
#[RequiresFeature(FeatureEnum::DUEL)]
#[Route('/api/duel', format: 'json')]
class DuelApiController extends AbstractController
{
    public function __construct(
        private readonly DeckService $deckService,
        private readonly DeckRepository $deckRepository,
        private readonly DuelApiPresenter $presenter,
    ) {
    }

    #[Route('/collection', name: 'api_duel_collection', methods: ['GET'])]
    public function collection(#[CurrentUser] DiscordUser $player): JsonResponse
    {
        return $this->json([
            'cards' => array_values(array_map($this->presenter->collectionCard(...), $this->deckService->collection($player))),
        ]);
    }

    #[Route('/decks', name: 'api_duel_deck_list', methods: ['GET'])]
    public function list(#[CurrentUser] DiscordUser $player): JsonResponse
    {
        return $this->json([
            'decks' => array_map(
                fn (array $entry): array => $this->presenter->deck($entry['deck'], $entry['validity']),
                $this->deckService->decksOf($player),
            ),
            'maxDecks' => DeckService::MAX_DECKS,
            'deckSize' => Deck::SIZE,
        ]);
    }

    #[Route('/decks', name: 'api_duel_deck_create', methods: ['POST'])]
    public function create(#[CurrentUser] DiscordUser $player, Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $deck = $this->deckService->create($player, $payload);
        } catch (DeckRefusedException $exception) {
            return $this->json($exception->payload(), $exception->status);
        }

        return $this->json($this->presenter->deck($deck, $this->deckService->validity($deck)), Response::HTTP_CREATED);
    }

    #[Route('/decks/{id}', name: 'api_duel_deck_update', methods: ['PUT'])]
    public function update(#[CurrentUser] DiscordUser $player, string $id, Request $request): JsonResponse
    {
        $deck = $this->deckRepository->findOneOwnedBy($id, $player);
        if (!$deck instanceof Deck) {
            return $this->notFound();
        }

        $payload = $this->payload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $this->deckService->update($deck, $payload);
        } catch (DeckRefusedException $exception) {
            return $this->json($exception->payload(), $exception->status);
        }

        return $this->json($this->presenter->deck($deck, $this->deckService->validity($deck)));
    }

    #[Route('/decks/{id}', name: 'api_duel_deck_delete', methods: ['DELETE'])]
    public function delete(#[CurrentUser] DiscordUser $player, string $id): Response
    {
        $deck = $this->deckRepository->findOneOwnedBy($id, $player);
        if (!$deck instanceof Deck) {
            return $this->notFound();
        }

        $this->deckService->delete($deck);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * JSON only: a cross-site form cannot send application/json without a CORS preflight.
     *
     * @return array<mixed>|JsonResponse
     */
    private function payload(Request $request): array | JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->json(['error' => 'Envoie le deck en JSON (Content-Type: application/json).'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $payload = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }

        if (!\is_array($payload) || ([] !== $payload && array_is_list($payload))) {
            return $this->json(['error' => 'Corps JSON invalide : objet attendu.'], Response::HTTP_BAD_REQUEST);
        }

        return $payload;
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'Deck introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
