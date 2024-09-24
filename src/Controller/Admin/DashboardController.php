<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Card;
use App\Entity\Extension;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        #[Autowire(param: 'app.admin_urls')]
        private readonly array $adminUrls,
    ) {
    }

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->redirect($this->adminUrlGenerator->setController(CardCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('YTCG Admin')
            ->setFaviconPath('ytcg_logo.png')
            ->renderContentMaximized()
        ;
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Card Settings');
        yield MenuItem::linkToCrud('Cards', 'fas fa-wallet', Card::class);
        yield MenuItem::linkToCrud('Extensions', 'fa fa-chart-bar', Extension::class);

        yield MenuItem::section('Extra');
        yield MenuItem::linkToUrl('YoulCoin - Admin', 'fa-brands fa-wizards-of-the-coast', $this->adminUrls['youl_coin'] ?? '#');
    }
}
