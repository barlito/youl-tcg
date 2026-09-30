<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Player-to-player market: the board of every active listing and the player's own shop.
 */
class MarketController extends AbstractController
{
    #[Route('/marche', name: 'market')]
    public function board(): Response
    {
        return $this->render('pages/market.html.twig');
    }

    #[Route('/marche/ma-boutique', name: 'market_my_shop')]
    public function myShop(): Response
    {
        return $this->render('pages/market_my_shop.html.twig');
    }
}
