<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\WishlistEntry;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Exception\Wishlist\WishlistRefusedException;
use App\Repository\CardRepository;
use App\Repository\ExtensionRepository;
use App\Repository\WishlistEntryRepository;
use App\Service\Wishlist\WishlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * « Ma wishlist » page plus the two toggles used by the static pages
 * (collection, universe). A card that is not visible to the player and not
 * already wished answers 404, exactly like an unknown id: no oracle.
 */
#[RequiresFeature(FeatureEnum::WISHLIST)]
class WishlistController extends AbstractController
{
    public function __construct(
        private readonly WishlistService $wishlist,
        private readonly CardRepository $cardRepository,
        private readonly ExtensionRepository $extensionRepository,
        private readonly WishlistEntryRepository $entryRepository,
    ) {
    }

    #[Route('/wishlist', name: 'wishlist')]
    public function index(): Response
    {
        return $this->render('pages/wishlist.html.twig');
    }

    #[Route('/wishlist/carte/{id}', name: 'wishlist_card_toggle', methods: ['POST'])]
    public function toggleCard(#[CurrentUser] DiscordUser $user, string $id): JsonResponse
    {
        $card = Uuid::isValid($id) ? $this->cardRepository->find($id) : null;

        if (!$card instanceof Card) {
            throw $this->createNotFoundException('Unknown card.');
        }

        if (!$this->entryRepository->findOneFor($user, $card) instanceof WishlistEntry && !$this->wishlist->canSee($user, $card)) {
            throw $this->createNotFoundException('Unknown card.');
        }

        try {
            return $this->json(['active' => $this->wishlist->toggle($user, $card)]);
        } catch (WishlistRefusedException $exception) {
            return $this->json(['error' => $exception->getUserMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/wishlist/univers/{slug}', name: 'wishlist_universe_toggle', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function toggleUniverse(#[CurrentUser] DiscordUser $user, string $slug): JsonResponse
    {
        $extension = $this->extensionRepository->findOneBy(['slug' => $slug, 'status' => ExtensionStatusEnum::PUBLISHED]);

        if (!$extension instanceof Extension) {
            throw $this->createNotFoundException('Unknown universe.');
        }

        try {
            return $this->json(['active' => $this->wishlist->toggleUniverse($user, $extension)]);
        } catch (WishlistRefusedException $exception) {
            return $this->json(['error' => $exception->getUserMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
