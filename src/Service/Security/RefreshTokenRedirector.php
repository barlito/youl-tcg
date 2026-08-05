<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the redirect to the external auth app refresh endpoint, carrying the
 * originally requested URI as _target_path so the user lands back where they
 * started once a fresh token has been issued.
 */
readonly class RefreshTokenRedirector
{
    public function __construct(
        #[Autowire(env: 'REFRESH_TOKEN_URL')]
        private string $refreshTokenUrl,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function createRedirect(?Request $request): RedirectResponse
    {
        $targetPath = $this->urlGenerator->generate('homepage');
        if ($request instanceof Request) {
            $targetPath = urlencode($request->getUri());
        }

        return new RedirectResponse($this->refreshTokenUrl . '?_target_path=' . $targetPath);
    }
}
