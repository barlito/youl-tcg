<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Security\AdminApiUser;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * One log line (channel admin_api) per management call that changed something.
 */
final readonly class ManageApiAudit
{
    private const int MAX_VALUE_LENGTH = 200;

    public function __construct(
        #[Autowire(service: 'monolog.logger.admin_api')]
        private LoggerInterface $logger,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array<string, array{from: mixed, to: mixed}> only the fields whose value differs
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $changes field => value or {from, to}; nothing is logged when empty
     */
    public function record(string $target, array $changes): void
    {
        if ([] === $changes) {
            return;
        }

        $user = $this->security->getUser();
        $request = $this->requestStack->getCurrentRequest();

        $this->logger->info('admin_api.write', [
            'admin' => $user instanceof AdminApiUser ? $user->getGeneratedBy() : null,
            'route' => $request?->attributes->get('_route'),
            'method' => $request?->getMethod(),
            'path' => $request?->getPathInfo(),
            'target' => $target,
            'changes' => array_map($this->truncate(...), $changes),
        ]);
    }

    private function truncate(mixed $value): mixed
    {
        if (\is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
        }

        if (\is_array($value)) {
            return array_map($this->truncate(...), $value);
        }

        return $value;
    }
}
