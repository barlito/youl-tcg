<?php

declare(strict_types=1);

namespace App\Dto\Admin;

final readonly class ImportTokenInfo
{
    public function __construct(
        public string $generatedBy,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
