<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\DBAL\Types\ConversionException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An EasyAdmin entityId that is not a valid uuid (/admin/card/not-a-uuid) is an unknown entity: 404, not a 500.
 */
#[AsEventListener]
final class AdminEntityIdNotFoundListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();

        $isAdminEntityRoute = $request->attributes->has('entityId') && str_starts_with($request->getPathInfo(), '/admin/');
        if (!$exception instanceof ConversionException || !$isAdminEntityRoute) {
            return;
        }

        $event->setThrowable(new NotFoundHttpException('Unknown entity.', $exception));
    }
}
