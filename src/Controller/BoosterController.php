<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Repository\BoosterRepository;
use App\Repository\CardRepository;
use App\Repository\UserBoosterRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

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
        CardRepository $cardRepository,
        UserBoosterRepository $userBoosterRepository,
    ): Response {
        $booster = $boosterRepository->find($id);

        if (null === $booster) {
            throw $this->createNotFoundException();
        }

        $drawableExtensionIds = array_flip($cardRepository->findExtensionIdsWithPublishedCards());
        $userBooster = $userBoosterRepository->findOneBy(['discordUser' => $user, 'booster' => $booster]);

        if (
            !isset($drawableExtensionIds[(string) $booster->getExtension()->getId()])
            || null === $userBooster
            || $userBooster->getQuantity() < 1
        ) {
            $this->addFlash('error', "Ce pack n'est pas ouvrable pour le moment.");

            return $this->redirectToRoute('boosters');
        }

        return $this->render('pages/booster_open.html.twig', ['booster' => $booster]);
    }
}
