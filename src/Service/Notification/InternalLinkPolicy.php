<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RequestContext;

/**
 * Admin-typed links (announcements) may only point inside the app: a path
 * ("/boosters") or an absolute http(s) URL on the app's own host, reduced
 * to its path before storage. Everything else is refused (other hosts,
 * protocol-relative "//", "/\", javascript:, data:, control characters).
 */
final readonly class InternalLinkPolicy
{
    private const int MAX_LENGTH = 255;

    public function __construct(
        private RequestStack $requestStack,
        private RequestContext $requestContext,
    ) {
    }

    /**
     * The storable internal path for $link, or null when it is refused.
     */
    public function toInternalPath(string $link): ?string
    {
        $link = trim($link);

        if ('' === $link || mb_strlen($link) > self::MAX_LENGTH || 1 === preg_match('/[\x00-\x20\x7F\\\\]/u', $link)) {
            return null;
        }

        if (self::isInternalPath($link)) {
            return $link;
        }

        $url = parse_url($link);

        if (!\is_array($url) || !isset($url['scheme'], $url['host']) || isset($url['user']) || isset($url['pass'])) {
            return null;
        }

        if (!\in_array(mb_strtolower($url['scheme']), ['http', 'https'], true) || mb_strtolower($url['host']) !== $this->appHost()) {
            return null;
        }

        $path = ($url['path'] ?? '') ?: '/';
        $path .= isset($url['query']) ? '?' . $url['query'] : '';
        $path .= isset($url['fragment']) ? '#' . $url['fragment'] : '';

        return self::isInternalPath($path) ? $path : null;
    }

    /**
     * A same-origin path: starts with one "/" (not "//" nor "/\"), no
     * whitespace, control characters or backslashes anywhere.
     */
    public static function isInternalPath(string $path): bool
    {
        return 1 === preg_match('#^/(?![/\\\\])[^\x00-\x20\x7F\\\\]*$#u', $path);
    }

    private function appHost(): string
    {
        $host = $this->requestStack->getMainRequest()?->getHost() ?? $this->requestContext->getHost();

        return mb_strtolower($host);
    }
}
