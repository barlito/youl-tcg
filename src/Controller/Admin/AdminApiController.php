<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\GeneratedAdminApiToken;
use App\Entity\DiscordUser;
use App\Enum\Admin\AdminApiScopeEnum;
use App\Service\Admin\AdminApiTokenManager;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates / revokes the short-lived bearer token of the admin API (/api/admin), with its scopes.
 */
#[AdminRoute(path: '/api', name: 'api')]
class AdminApiController extends AbstractController
{
    private const string CSRF_ID = 'admin_api';

    public function __construct(private readonly AdminApiTokenManager $tokenManager)
    {
    }

    public function __invoke(Request $request): Response
    {
        $generated = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $action = (string) $request->request->get('action');
            $generated = $this->handleAction($action, $request);
            if (!$generated instanceof GeneratedAdminApiToken) {
                return $this->redirectToRoute('admin_api');
            }
        }

        return $this->render('admin/admin_api.html.twig', [
            'csrfId' => self::CSRF_ID,
            'generated' => $generated,
            'active' => $this->tokenManager->activeTokenInfo(),
            'scopes' => AdminApiScopeEnum::cases(),
            'ttlMinutes' => intdiv(AdminApiTokenManager::TTL, 60),
        ]);
    }

    private function handleAction(string $action, Request $request): ?GeneratedAdminApiToken
    {
        $user = $this->getUser();

        if ('generate' === $action && $user instanceof DiscordUser) {
            $scopes = AdminApiScopeEnum::fromValues($request->request->all('scopes'));
            if ([] === $scopes) {
                $this->addFlash('danger', 'Coche au moins un droit pour générer un token.');

                return null;
            }

            return $this->tokenManager->generate($user->getDiscordId(), $scopes);
        }

        if ('revoke' === $action) {
            $this->tokenManager->revoke();
            $this->addFlash('success', 'Token révoqué : l\'API admin n\'accepte plus aucune requête.');
        }

        return null;
    }
}
