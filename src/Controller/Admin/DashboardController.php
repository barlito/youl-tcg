<?php

declare(strict_types=1);

namespace App\Controller\Admin;

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
        #[Autowire(env: 'APP_VERSION')]
        private readonly string $appVersion,
    ) {
    }

    #[Route('/admin', name: 'admin')]
    #[\Override]
    public function index(): Response
    {
        return $this->redirect($this->adminUrlGenerator->setController(CardCrudController::class)->generateUrl());
    }

    #[\Override]
    public function configureDashboard(): Dashboard
    {
        // the title accepts raw HTML; appVersion is the image release tag (see .env)
        return Dashboard::new()
            ->setTitle(\sprintf(
                'YTCG Admin <span class="badge badge-secondary" data-testid="app-version">%s</span>',
                htmlspecialchars($this->appVersion),
            ))
            ->setFaviconPath('ytcg_logo.png')
            ->renderContentMaximized()
        ;
    }

    #[\Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Card Settings');
        yield MenuItem::linkTo(CardCrudController::class, 'Cards', 'fas fa-wallet');
        yield MenuItem::linkToRoute('Ajout en masse', 'fa fa-images', 'admin_cards_batch');
        yield MenuItem::linkTo(ExtensionCrudController::class, 'Extensions', 'fa fa-chart-bar');
        yield MenuItem::linkTo(ExtensionBannerCrudController::class, 'Bannières d\'univers', 'fa fa-image');
        yield MenuItem::linkTo(BoosterCrudController::class, 'Boosters', 'fa fa-box-open');

        yield MenuItem::section('Aide');
        yield MenuItem::linkToRoute('Guide admin', 'fa fa-book', 'admin_guide');
    }
}
