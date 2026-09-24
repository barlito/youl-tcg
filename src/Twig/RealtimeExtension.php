<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DiscordUser;
use App\Service\Realtime\RealtimeTopics;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Subscription side of the realtime layer: hands the layout the hub URL to
 * listen to and sets the mercureAuthorization cookie, whose JWT grants the
 * logged-in player their own private topic and nothing else.
 */
final readonly class RealtimeExtension
{
    public function __construct(
        private HubInterface $hub,
        private RealtimeTopics $topics,
        private Authorization $authorization,
        private RequestStack $requestStack,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Null for an anonymous visitor or when the cookie cannot be issued:
     * the page then simply renders without live updates.
     */
    #[AsTwigFunction(name: 'live_updates_url')]
    public function liveUpdatesUrl(): ?string
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getMainRequest();

        if (!$user instanceof DiscordUser || !$request instanceof Request) {
            return null;
        }

        try {
            $this->authorization->setCookie($request, $this->topics->privateTopicsFor($user));
        } catch (\Throwable $exception) {
            $this->logger->warning('Mercure subscription cookie not issued: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }

        $query = implode('&', array_map(
            static fn (string $topic): string => 'topic=' . rawurlencode($topic),
            $this->topics->subscriptionsFor($user),
        ));

        return $this->hub->getPublicUrl() . '?' . $query;
    }
}
