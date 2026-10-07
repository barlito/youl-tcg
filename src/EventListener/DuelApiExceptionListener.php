<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * JSON errors under /api/duel with generic French messages (a disabled feature is a plain 404).
 */
#[AsEventListener]
final class DuelApiExceptionListener
{
    public const string PATH_PREFIX = '/api/duel/';

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface || !str_starts_with($event->getRequest()->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }

        $status = $exception->getStatusCode();
        $event->setResponse(new JsonResponse(['error' => match ($status) {
            Response::HTTP_NOT_FOUND => 'Introuvable.',
            Response::HTTP_METHOD_NOT_ALLOWED => 'Méthode non autorisée.',
            Response::HTTP_FORBIDDEN => 'Accès refusé.',
            default => 'Requête refusée.',
        }], $status, $exception->getHeaders()));
    }
}
