<?php

declare(strict_types=1);

namespace App\DTO\BorderConfig;

use Symfony\Component\Validator\Constraints as Assert;

class GlowConfigDTO
{
    #[Assert\Type('bool')]
    public bool $enabled = false;

    #[Assert\Range(min: 0, max: 100)]
    public int $intensity = 50;

    #[Assert\Regex(pattern: '/^#[0-9A-Fa-f]{6}$/')]
    public string $color = '#9333ea';

    #[Assert\Type('bool')]
    public bool $pulse = false;
}
