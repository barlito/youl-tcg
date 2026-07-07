<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Standalone card render for the back office: embedded as an iframe on the
 * Card form/detail pages so an admin sees the REAL CardComponent (visual
 * cascade, holo preset, frame…) while editing. Lives under /admin → covered
 * by the ROLE_ADMIN access_control.
 */
class CardPreviewController extends AbstractController
{
    #[Route('/admin/card-preview/{id}', name: 'admin_card_preview')]
    public function __invoke(string $id, CardRepository $cardRepository): Response
    {
        $card = Uuid::isValid($id) ? $cardRepository->find($id) : null;

        if (null === $card) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/card_preview.html.twig', ['card' => $card]);
    }
}
