<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\Admin\ManageApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[AsEventListener]
final class AdminApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof ManageApiException) {
            $event->setResponse(new JsonResponse($exception->payload, $exception->status));

            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/admin') || !$exception instanceof HttpExceptionInterface) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'Http error', 'message' => $exception->getMessage()],
            $exception->getStatusCode(),
            $exception->getHeaders(),
        ));
    }
}
