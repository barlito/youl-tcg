<?php

declare(strict_types=1);

namespace App\Fixtures;

use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Fidry\AliceDataFixtures\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Dev fixtures reference entities inside notification payloads (offerId,
 * listingId): uuids only exist once persisted, so the referenced entity is
 * persisted first and replaced by its id.
 */
#[When(env: 'dev')]
final readonly class NotificationPayloadProcessor implements ProcessorInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function preProcess(string $id, object $object): void
    {
        if (!$object instanceof Notification) {
            return;
        }

        $property = new \ReflectionProperty(Notification::class, 'payload');
        $payload = $property->getValue($object);
        if (!\is_array($payload)) {
            return;
        }

        $changed = false;
        foreach ($payload as $key => $value) {
            if (\is_object($value) && method_exists($value, 'getId')) {
                $this->entityManager->persist($value);
                $payload[$key] = $value->getId();
                $changed = true;
            }
        }

        if ($changed) {
            $property->setValue($object, $payload);
        }
    }

    public function postProcess(string $id, object $object): void
    {
    }
}
