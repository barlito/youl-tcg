<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BoosterController extends AbstractController
{
    #[Route('/boosters', name: 'boosters')]
    public function index(): Response
    {
        return $this->render('pages/boosters.html.twig');
    }
}
