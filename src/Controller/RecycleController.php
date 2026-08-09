<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RecycleController extends AbstractController
{
    #[Route('/recyclage', name: 'recycle')]
    public function __invoke(): Response
    {
        return $this->render('pages/recycle.html.twig');
    }
}
