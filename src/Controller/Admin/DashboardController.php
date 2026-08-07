<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

// EA5 pretty URLs: the attribute registers /admin and every CRUD route
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        #[Autowire(env: 'APP_VERSION')]
        private readonly string $appVersion,
    ) {
    }

    #[\Override]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_card_index');
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
