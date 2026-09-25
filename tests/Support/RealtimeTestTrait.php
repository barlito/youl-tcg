<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Realtime\RealtimeTopics;
use App\Service\Realtime\UserEventPublisher;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

/**
 * Builds a real UserEventPublisher for unit tests, over a hub double.
 */
trait RealtimeTestTrait
{
    private function userEventPublisher(?HubInterface $hub = null): UserEventPublisher
    {
        $hub ??= $this->createStub(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://localhost/.well-known/mercure');

        return new UserEventPublisher($hub, new RealtimeTopics($hub), new NullLogger());
    }
}
