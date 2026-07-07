<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static admin handbook: every entity, every upload (with recommended sizes),
 * the visual system (cascade, presets, masks, foils) and the publishing rules,
 * written for an admin discovering the back office.
 */
class AdminGuideController extends AbstractController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/guide', name: 'admin_guide')]
    public function __invoke(Request $request): Response
    {
        // The template extends the EasyAdmin layout, which needs the admin
        // context. A direct hit on /admin/guide doesn't have it: bounce
        // through the dashboard, which forwards back here with the context.
        if (!$request->attributes->has(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
            return $this->redirect(
                $this->adminUrlGenerator->setRoute('admin_guide')->generateUrl(),
            );
        }

        return $this->render('admin/guide.html.twig');
    }
}
