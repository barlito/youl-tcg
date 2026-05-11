<?php

declare(strict_types=1);

namespace App\DTO\BorderConfig;

use Symfony\Component\Validator\Constraints as Assert;

class FadeConfigDTO
{
    #[Assert\Type('bool')]
    public bool $enabled = false;

    #[Assert\Range(min: 0, max: 100)]
    public int $start = 50;

    #[Assert\Range(min: 10, max: 100)]
    public int $length = 50;

    #[Assert\Range(min: 0, max: 360)]
    public int $direction = 135;
}
