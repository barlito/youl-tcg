<?php

declare(strict_types=1);

namespace App\Dto\Admin;

final readonly class GeneratedAdminApiToken
{
    public function __construct(
        public string $token,
        public AdminApiTokenInfo $info,
    ) {
    }
}
