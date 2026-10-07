<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Enum\FeatureEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[RequiresFeature(FeatureEnum::FUSION)]
class FusionController extends AbstractController
{
    #[Route('/fusion', name: 'fusion')]
    public function __invoke(): Response
    {
        return $this->render('pages/fusion.html.twig');
    }
}
