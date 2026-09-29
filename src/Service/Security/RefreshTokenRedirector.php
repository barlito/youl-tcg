<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Service\Notification\InternalLinkPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

readonly class RefreshTokenRedirector
{
    private const string LIVE_COMPONENT_PREFIX = '/_components/';

    public function __construct(
        #[Autowire(env: 'REFRESH_TOKEN_URL')]
        private string $refreshTokenUrl,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function createRedirect(?Request $request): RedirectResponse
    {
        $targetPath = $request instanceof Request
            ? $this->targetUrl($request)
            : $this->urlGenerator->generate('homepage', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new RedirectResponse($this->refreshTokenUrl . '?_target_path=' . urlencode($targetPath));
    }

    private function targetUrl(Request $request): string
    {
        if (!str_starts_with($request->getPathInfo(), self::LIVE_COMPONENT_PREFIX)) {
            return $request->getUri();
        }

        // The live controller turns this redirect into a full-page navigation: come back to the page, not the component endpoint
        $pageUrl = (string) $request->headers->get('X-Live-Url');
        if (!InternalLinkPolicy::isInternalPath($pageUrl) || str_starts_with($pageUrl, self::LIVE_COMPONENT_PREFIX)) {
            return $this->urlGenerator->generate('homepage', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $pageUrl;
    }
}
