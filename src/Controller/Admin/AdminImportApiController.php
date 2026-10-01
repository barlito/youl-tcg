<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\GeneratedImportToken;
use App\Entity\DiscordUser;
use App\Service\Admin\ImportApiTokenManager;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates / revokes the short-lived bearer token of the import API (/api/admin).
 */
#[AdminRoute(path: '/api-import', name: 'api_import')]
class AdminImportApiController extends AbstractController
{
    private const string CSRF_ID = 'admin_api_import';

    public function __construct(private readonly ImportApiTokenManager $tokenManager)
    {
    }

    public function __invoke(Request $request): Response
    {
        $generated = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $generated = $this->handleAction((string) $request->request->get('action'));
            if (!$generated instanceof GeneratedImportToken) {
                return $this->redirectToRoute('admin_api_import');
            }
        }

        return $this->render('admin/api_import.html.twig', [
            'csrfId' => self::CSRF_ID,
            'generated' => $generated,
            'active' => $this->tokenManager->activeTokenInfo(),
            'ttlMinutes' => intdiv(ImportApiTokenManager::TTL, 60),
        ]);
    }

    private function handleAction(string $action): ?GeneratedImportToken
    {
        $user = $this->getUser();

        if ('generate' === $action && $user instanceof DiscordUser) {
            return $this->tokenManager->generate($user->getDiscordId());
        }

        if ('revoke' === $action) {
            $this->tokenManager->revoke();
            $this->addFlash('success', 'Token révoqué : l\'API d\'import n\'accepte plus aucune requête.');
        }

        return null;
    }
}
