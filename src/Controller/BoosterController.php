<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Repository\BoosterRepository;
use App\Repository\UserBoosterRepository;
use App\Service\Booster\BoosterAvailabilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

class BoosterController extends AbstractController
{
    /**
     * Dedicated single-booster opening page (packs.com-style): the 3D pack on the
     * left, the extension's card set on the right. The draw itself happens through
     * the BoosterOpening live component's `open` action.
     */
    #[Route('/boosters/{id}/open', name: 'booster_open')]
    public function open(
        string $id,
        #[CurrentUser] DiscordUser $user,
        BoosterRepository $boosterRepository,
        UserBoosterRepository $userBoosterRepository,
        BoosterAvailabilityService $boosterAvailability,
    ): Response {
        // A malformed uuid must be a plain 404 instead of a Doctrine conversion error.
        $booster = Uuid::isValid($id) ? $boosterRepository->find($id) : null;

        if (null === $booster) {
            throw $this->createNotFoundException();
        }

        $userBooster = $userBoosterRepository->findOneBy(['discordUser' => $user, 'booster' => $booster]);

        if (!$boosterAvailability->isOpenable($booster, $userBooster?->getQuantity() ?? 0)) {
            $this->addFlash('error', "Ce pack n'est pas ouvrable pour le moment.");

            return $this->redirectToRoute('boosters');
        }

        return $this->render('pages/booster_open.html.twig', ['booster' => $booster]);
    }
}
