<?php

declare(strict_types=1);

namespace App\Exception\Admin;

/**
 * A management API refusal, rendered as JSON by AdminApiExceptionListener.
 */
final class ManageApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(public readonly int $status, public readonly array $payload)
    {
        parent::__construct((string) ($payload['message'] ?? $payload['error'] ?? 'Management API refusal'));
    }

    /**
     * @param array<string, list<string>> $violations
     */
    public static function invalid(array $violations): self
    {
        return new self(422, ['error' => 'Validation failed', 'violations' => $violations]);
    }

    /**
     * A business rule of the game state refuses the change (owned card, frozen flag, duplicate…).
     *
     * @param array<string, list<string>> $violations
     * @param array<string, mixed>        $extra
     */
    public static function conflict(string $message, array $violations = [], array $extra = []): self
    {
        return new self(409, ['error' => 'Conflict', 'message' => $message, ...([] === $violations ? [] : ['violations' => $violations]), ...$extra]);
    }

    public static function notFound(string $message): self
    {
        return new self(404, ['error' => $message]);
    }
}
