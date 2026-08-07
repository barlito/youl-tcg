<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

// EA5 pretty URLs: the attribute registers /admin and every CRUD route
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        #[Autowire(env: 'APP_VERSION')]
        private readonly string $appVersion,
        private readonly AssetMapperInterface $assetMapper,
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

    /**
     * Loaded on every admin page (the dashboard assets are the base every CRUD
     * controller inherits), unlike the field-level stylesheets.
     */
    #[\Override]
    public function configureAssets(): Assets
    {
        $adminCss = $this->assetMapper->getAsset('styles/admin/admin.css')
            ?? throw new \LogicException('Asset "styles/admin/admin.css" not found in the asset map.');

        return parent::configureAssets()->addCssFile($adminCss->publicPath);
    }

    #[\Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Contenu');
        yield MenuItem::linkTo(CardCrudController::class, 'Cartes', 'fas fa-wallet');
        yield MenuItem::linkToRoute('Ajout en masse', 'fa fa-images', 'admin_cards_batch');
        yield MenuItem::linkTo(ExtensionCrudController::class, 'Extensions', 'fa fa-chart-bar');
        yield MenuItem::linkTo(ExtensionBannerCrudController::class, 'Bannières d\'univers', 'fa fa-image');
        yield MenuItem::linkTo(BoosterCrudController::class, 'Boosters', 'fa fa-box-open');

        yield MenuItem::section('Distribution');
        yield MenuItem::linkToRoute('Générer des codes', 'fa fa-ticket', 'admin_booster_codes_batch');
        yield MenuItem::linkTo(BoosterCodeCrudController::class, 'Codes', 'fa fa-key');
        yield MenuItem::linkTo(BoosterCodeRedemptionCrudController::class, 'Utilisations de codes', 'fa fa-check-double');

        yield MenuItem::section('Économie (lecture seule)');
        yield MenuItem::linkTo(DiscordUserCrudController::class, 'Joueurs', 'fa fa-users');
        yield MenuItem::linkTo(BoosterOpeningCrudController::class, 'Ouvertures', 'fa fa-box-open');
        yield MenuItem::linkTo(BoosterClaimCrudController::class, 'Récupérations', 'fa fa-gift');

        yield MenuItem::section('Aide');
        yield MenuItem::linkToRoute('Guide admin', 'fa fa-book', 'admin_guide');

        yield MenuItem::section('Application');
        // linkToRoute would keep the admin context and stay inside /admin:
        // leaving the back-office needs a plain URL
        yield MenuItem::linkToUrl('Retour au site', 'fa fa-arrow-left', $this->generateUrl('homepage'));
    }
}
